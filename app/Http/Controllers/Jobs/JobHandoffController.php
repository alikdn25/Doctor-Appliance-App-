<?php

namespace App\Http\Controllers\Jobs;

use App\Actions\Jobs\AssignJob;
use App\Enums\MessageKind;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Messaging\Messenger;
use App\Models\Company;
use App\Models\JobPhoto;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Hand to technician" on a job (owner's task of November 19): a technician of the app or an outside one (Prices and
 * answers → Outside technicians). Preview of two texts — to the technician (customer, phone, address, window, problem,
 * model, a link to the photos) and to the customer ("Your technician {name} will arrive {window}.") — and Confirm sends
 * both. The job shows "Handed to: {name}"; a technician of the app also becomes the job's technician. Nothing is sent
 * before Confirm.
 */
class JobHandoffController extends Controller
{
    public const PHOTO_LINK_DAYS = 14;

    /**
     * The people a job can be handed to.
     *
     * @return list<array{key: string, name: string, phone: string|null, outside: bool}>
     */
    public static function options(): array
    {
        $company = currentCompany();
        $members = Membership::query()->with('user')->where('is_active', true)->get()
            ->filter(fn (Membership $m) => $m->user !== null && $m->user->is_active)
            ->map(fn (Membership $m) => ['key' => 'user:'.$m->user_id, 'name' => $m->user->name, 'phone' => $m->user->phone ? PhoneNumber::normalize($m->user->phone, $company->country) : null, 'outside' => false]);
        $outside = collect((array) $company->outside_technicians)->values()
            ->map(fn (array $t, int $i) => ['key' => 'outside:'.$i, 'name' => $t['name'], 'phone' => $t['phone'], 'outside' => true]);

        return [...$members->values()->all(), ...$outside->all()];
    }

    public function preview(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('update', $job);
        [$who, $texts] = $this->plan($request, $job);

        return response()->json(['to' => $who, ...$texts]);
    }

    public function store(Request $request, ServiceJob $job, Messenger $messenger, AssignJob $assign, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('update', $job);
        [$who, $texts] = $this->plan($request, $job);
        $user = $request->user();

        $tech = $messenger->smsToNumber($who['phone'], $texts['technician'], $user, MessageKind::Handoff);
        $customer = $texts['customer'] !== null && $job->customer !== null
            ? $messenger->sms(MessageKind::Handoff, $job->customer, $job, $texts['customer'], $user)
            : null;

        if (str_starts_with($who['key'], 'user:')) {
            $assign->assign($job, (int) substr($who['key'], 5), $user);
        }
        $job->forceFill(['handed_to' => $who['name'], 'handed_at' => now()])->save();
        $audit->record('job.handed_off', $job, ['to' => $who['name']]);

        $blocked = collect([$tech, $customer])->filter(fn ($m) => $m !== null && $m->status === 'blocked')->map->status_reason->unique()->implode(' ');
        Inertia::flash('toast', ['type' => $blocked === '' ? 'success' : 'warning', 'message' => __('handoff.done', ['name' => $who['name']]).($blocked === '' ? '' : ' '.$blocked)]);

        return back();
    }

    /**
     * @return array{0: array{key: string, name: string, phone: string, outside: bool}, 1: array{technician: string, customer: string|null}}
     */
    private function plan(Request $request, ServiceJob $job): array
    {
        $data = $request->validate(['to' => ['required', 'string', 'max:40']]);
        $who = collect(self::options())->firstWhere('key', $data['to']);
        if ($who === null) {
            throw ValidationException::withMessages(['to' => __('handoff.unknown')]);
        }
        if (blank($who['phone'])) {
            throw ValidationException::withMessages(['to' => __('handoff.no_phone', ['name' => $who['name']])]);
        }

        $company = currentCompany();
        $job->loadMissing(['customer.primaryPhone', 'property', 'appliances', 'photos']);
        $visit = $job->visits()->whereIn('status', VisitStatus::openValues())->orderBy('scheduled_start')->first();
        $window = $visit ? MessageContext::date($visit->scheduled_start).' '.MessageContext::window($visit) : __('handoff.no_time');
        $model = $job->appliances->map(fn ($a) => trim($a->label().' '.($a->model_number ?? '')))->filter()->implode('; ');
        $photos = $job->photos->isEmpty() ? null : URL::temporarySignedRoute('shared.job-photos', now()->addDays(self::PHOTO_LINK_DAYS), ['job' => $job->id]);

        $technician = implode("\n", array_filter([
            __('handoff.tech.title', ['number' => $job->number, 'company' => $company->name]),
            __('handoff.tech.customer', ['name' => (string) $job->customer?->display_name]),
            $job->customer?->primaryPhone ? __('handoff.tech.phone', ['phone' => PhoneNumber::display($job->customer->primaryPhone->number, $company->country)]) : null,
            $job->property?->hasAddress() ? __('handoff.tech.address', ['address' => $job->property->fullAddress()]) : null,
            __('handoff.tech.window', ['window' => $window]),
            $job->description ? __('handoff.tech.problem', ['problem' => $job->description]) : null,
            $model !== '' ? __('handoff.tech.model', ['model' => $model]) : null,
            $photos ? __('handoff.tech.photos', ['link' => $photos]) : null,
        ]));
        $customer = $visit ? __('handoff.customer', ['name' => $who['name'], 'window' => $window]) : null;

        return [$who, ['technician' => $technician, 'customer' => $customer]];
    }

    /**
     * The job's photos for the outside technician, from the signed link (no sign-in, 14 days).
     */
    public function photos(Request $request, int $job): HttpResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $model = ServiceJob::withoutCompanyScope()->findOrFail($job);
        $company = Company::query()->findOrFail($model->company_id);

        return app(CurrentCompany::class)->runAs($company, function () use ($model) {
            $links = $model->photos()->get()->map(fn (JobPhoto $photo) => URL::temporarySignedRoute('shared.job-photo',
                now()->addHours(2), ['job' => $model->id, 'photo' => $photo->id]));

            $html = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                .'<title>'.e(__('handoff.photos_title', ['number' => $model->number])).'</title>'
                .'<body style="font-family:system-ui,sans-serif;margin:16px"><h1 style="font-size:20px">'.e(__('handoff.photos_title', ['number' => $model->number])).'</h1>'
                .$links->map(fn (string $url) => '<p><img src="'.e($url).'" style="max-width:100%;border-radius:8px" alt=""></p>')->implode('')
                .'</body>';

            return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
        });
    }

    public function photo(Request $request, int $job, int $photo): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $file = JobPhoto::withoutCompanyScope()->where('service_job_id', $job)->findOrFail($photo);

        return PrivateMedia::response($file->path);
    }
}
