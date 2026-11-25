<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\MessageKind;
use App\Enums\VisitStatus;
use App\Http\Controllers\Controller;
use App\Mail\JobReportMail;
use App\Messaging\Messenger;
use App\Models\BrandAddress;
use App\Models\Company;
use App\Models\JobReport;
use App\Models\ServiceJob;
use App\Support\Jobs\JobPresenter;
use App\Support\Maps\Geocoder;
use App\Support\PrivateMedia;
use App\Support\Reports\JobReportPdf;
use App\Support\Reports\ServiceReport;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Ui\SavedToast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The separate job report (owner's task of November 25): "Report" on the job page opens a form filled from the job
 * (every field can be changed, none is required), with text, photos and an optional customer signature. Saved under
 * the job's Files; a PDF to preview, download, email (attached) or text (a link).
 */
class JobReportController extends Controller
{
    public function create(Request $request, ServiceJob $job): Response
    {
        Gate::authorize('work', $job);

        return $this->form($job, null, self::prefill($job, $request));
    }

    public function store(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);

        $report = new JobReport;
        $report->service_job_id = $job->id;
        $report->user_id = $request->user()->id;
        $report->public_token = Str::random(40);
        $this->save($request, $job, $report);

        SavedToast::flash(route('jobs.reports.edit', $report), __('service_reports.saved'));

        return to_route('jobs.reports.edit', $report);
    }

    public function edit(JobReport $report): Response
    {
        $job = $report->job()->firstOrFail();
        Gate::authorize('work', $job);

        return $this->form($job, $report, [
            'brand_address_id' => $report->brand_address_id,
            'customer_name' => (string) $report->customer_name,
            'address' => (string) $report->address,
            'appliance_type' => (string) $report->appliance_type,
            'appliance_brand' => (string) $report->appliance_brand,
            'appliance_model' => (string) $report->appliance_model,
            'appliance_serial' => (string) $report->appliance_serial,
            'report_date' => $report->report_date?->format('Y-m-d') ?? '',
            'technician_name' => (string) $report->technician_name,
            'description' => (string) $report->description,
            'signer_name' => (string) $report->signer_name,
            'photos' => ServiceReport::forForm($report, $job),
        ]);
    }

    public function update(Request $request, JobReport $report): RedirectResponse
    {
        $job = $report->job()->firstOrFail();
        Gate::authorize('work', $job);
        $this->save($request, $job, $report);

        SavedToast::flash(route('jobs.reports.edit', $report), __('service_reports.saved'));

        return back();
    }

    public function destroy(JobReport $report): RedirectResponse
    {
        $job = $report->job()->firstOrFail();
        Gate::authorize('work', $job);
        $report->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('service_reports.deleted')]);

        return to_route('jobs.show', $job);
    }

    /** Preview PDF (inline) / Download PDF (?download=1). */
    public function pdf(Request $request, JobReport $report): HttpResponse
    {
        Gate::authorize('work', $report->job()->firstOrFail());

        return self::pdfResponse($report, $request->boolean('download'));
    }

    /** The link texted to the customer: the report's PDF (no sign-in). */
    public function public(string $token, CurrentCompany $tenancy): HttpResponse
    {
        $report = JobReport::withoutGlobalScopes()->where('public_token', $token)->whereNull('deleted_at')->firstOrFail();
        $company = Company::query()->findOrFail($report->company_id);

        return $tenancy->runAs($company, fn () => self::pdfResponse(JobReport::query()->findOrFail($report->id), false));
    }

    public function signature(JobReport $report): StreamedResponse
    {
        Gate::authorize('work', $report->job()->firstOrFail());
        abort_if($report->signature_path === null, 404);

        return PrivateMedia::response($report->signature_path);
    }

    /** Email (PDF attached) or SMS (a link to the PDF). */
    public function send(Request $request, JobReport $report, Messenger $messenger): RedirectResponse
    {
        $job = $report->job()->firstOrFail();
        Gate::authorize('work', $job);
        $data = $request->validate([
            'channel' => ['nullable', 'in:email,sms'],
            'to' => ['nullable', 'string', 'max:255'],
        ]);
        $job->loadMissing(['customer', 'brand']);
        $company = currentCompany();

        if (($data['channel'] ?? 'email') === 'sms') {
            $body = __('service_reports.sms_text', [
                'name' => $job->customer?->first_name ?: ($job->customer?->hasName() ? $job->customer->display_name : ''),
                'company' => $job->brand?->name ?? $company->name,
                'url' => route('reports.public', $report->public_token),
            ]);
            $body = (string) preg_replace('/^Hi ,\s*/u', 'Hi, ', $body);
            $message = $messenger->sms(MessageKind::General, $job->customer, $job, $body, $request->user());
            if ($message->status === 'blocked') {
                throw ValidationException::withMessages(['channel' => __('service_reports.not_sent', ['reason' => $message->status_reason])]);
            }
            $report->forceFill(['sent_at' => now(), 'sent_to' => $message->to])->save();
            Inertia::flash('toast', ['type' => 'success', 'message' => __('service_reports.sent_sms', ['to' => $message->to])]);

            return back();
        }

        $email = trim((string) ($data['to'] ?? '')) ?: $job->customer?->primaryEmail()->value('email');
        if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['to' => __('service_reports.no_email')]);
        }

        Mail::to($email)->queue(new JobReportMail($report->id, $company->id));
        $report->forceFill(['sent_at' => now(), 'sent_to' => $email])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('service_reports.sent_email', ['to' => $email])]);

        return back();
    }

    /** "+ Photo" in any report (job report, invoice or estimate): saved among the job's photos too. */
    public function uploadPhotos(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('work', $job);
        $request->validate([
            'photos' => ['nullable', 'array', 'max:20'],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:15360'],
        ]);

        return response()->json(['photos' => ServiceReport::upload($job, $request->file('photos', []), $request->user())]);
    }

    private static function pdfResponse(JobReport $report, bool $download): HttpResponse
    {
        $name = JobReportPdf::filename($report);

        return response(JobReportPdf::render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function save(Request $request, ServiceJob $job, JobReport $report): void
    {
        $data = $request->validate([
            'brand_address_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'appliance_type' => ['nullable', 'string', 'max:100'],
            'appliance_brand' => ['nullable', 'string', 'max:100'],
            'appliance_model' => ['nullable', 'string', 'max:100'],
            'appliance_serial' => ['nullable', 'string', 'max:100'],
            'report_date' => ['nullable', 'date_format:Y-m-d'],
            'technician_name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:2000000'],
            'clear_signature' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'array', 'max:40'],
            'photos.*.job_photo_id' => ['nullable', 'integer'],
            'photos.*.caption' => ['nullable', 'string', 'max:300'],
        ]);

        $location = filled($data['brand_address_id'] ?? null) ? BrandAddress::query()->find((int) $data['brand_address_id']) : null;
        $values = [];
        foreach (['customer_name', 'address', 'appliance_type', 'appliance_brand', 'appliance_model', 'appliance_serial', 'technician_name', 'description', 'signer_name'] as $key) {
            $values[$key] = filled($data[$key] ?? null) ? trim((string) $data[$key]) : null;
        }
        $report->fill([...$values, 'brand_address_id' => $location?->id, 'report_date' => $data['report_date'] ?? null]);

        // The customer's signature: a PNG drawn on the phone (data URL); optional.
        if ($request->boolean('clear_signature') && $report->signature_path !== null) {
            PrivateMedia::disk()->delete($report->signature_path);
            $report->signature_path = null;
        }
        if (filled($data['signature'] ?? null) && preg_match('#^data:image/png;base64,(.+)$#', (string) $data['signature'], $m)) {
            $png = base64_decode($m[1], true);
            if ($png !== false && str_starts_with($png, "\x89PNG")) {
                $path = "companies/{$job->company_id}/jobs/{$job->id}/report-signature-".Str::uuid().'.png';
                PrivateMedia::disk()->put($path, $png);
                if ($report->signature_path !== null) {
                    PrivateMedia::disk()->delete($report->signature_path);
                }
                $report->signature_path = $path;
            }
        }

        $report->save();

        if ($request->has('photos')) {
            ServiceReport::sync($report, $job->id, (array) ($data['photos'] ?? []));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function form(ServiceJob $job, ?JobReport $report, array $values): Response
    {
        return Inertia::render('reports/form', [
            'job' => ['id' => $job->id, 'number' => $job->number, 'url' => route('jobs.show', $job)],
            'report' => $report === null ? null : [
                'id' => $report->id,
                'pdf_url' => route('jobs.reports.pdf', $report),
                'signature_url' => $report->signature_path ? route('jobs.reports.signature', $report) : null,
                'sent_at' => JobPresenter::iso($report->sent_at),
                'sent_to' => $report->sent_to,
            ],
            'values' => [...$values, 'photos' => $values['photos'] ?? []],
            'locations' => self::locations($job),
            'jobPhotos' => ServiceReport::jobPhotos($job),
            'customerEmail' => $job->customer?->primaryEmail()->value('email'),
            'customerPhone' => $job->customer?->primaryPhone()->value('number'),
        ]);
    }

    /**
     * The report filled from the job.
     *
     * @return array<string, mixed>
     */
    private static function prefill(ServiceJob $job, Request $request): array
    {
        $job->loadMissing(['customer', 'property', 'appliances', 'visits.assignees', 'brand']);
        $company = currentCompany();
        $appliance = $job->appliances->first();
        $visit = $job->visits->sortByDesc('scheduled_start')
            ->first(fn ($v) => in_array($v->status->value, [VisitStatus::Completed->value, VisitStatus::InProgress->value], true))
            ?? $job->visits->sortByDesc('scheduled_start')->first();
        $location = BrandAddress::query()->when($job->brand_id, fn ($q) => $q->where('brand_id', $job->brand_id))
            ->orderByDesc('is_primary')->orderBy('id')->first() ?? BrandAddress::query()->orderByDesc('is_primary')->orderBy('id')->first();

        return [
            'brand_address_id' => $location?->id,
            'customer_name' => $job->customer?->hasName() ? $job->customer->display_name : '',
            'address' => filled($job->property?->line1) ? $job->property->fullAddress() : '',
            'appliance_type' => (string) ($appliance?->type->label() ?? $job->appliance_category?->label() ?? ''),
            'appliance_brand' => (string) ($appliance?->manufacturer ?? ''),
            'appliance_model' => (string) ($appliance?->model_number ?? ''),
            'appliance_serial' => (string) ($appliance?->serial_number ?? ''),
            'report_date' => ($visit?->scheduled_start ? CarbonImmutable::instance($visit->scheduled_start)->setTimezone($company->timezone) : CarbonImmutable::now($company->timezone))->format('Y-m-d'),
            'technician_name' => (string) ($visit?->assignees->first()?->name ?? $request->user()->name),
            'description' => '',
            'signer_name' => '',
            'photos' => [],
        ];
    }

    /**
     * The company's locations, each with the distance to the job's address when both can be placed on the map.
     *
     * @return list<array{id: int, label: string, distance: string|null}>
     */
    private static function locations(ServiceJob $job): array
    {
        $job->loadMissing('property');
        $company = currentCompany();
        $miles = in_array($company->country, ['US', 'GB', 'LR', 'MM'], true);
        $point = null;
        if ($job->property?->latitude !== null && $job->property?->longitude !== null) {
            $point = ['lat' => (float) $job->property->latitude, 'lng' => (float) $job->property->longitude];
        } elseif (filled($job->property?->line1)) {
            $point = self::locate($job->property->fullAddress(), $job->property->country ?: $company->country);
        }

        return BrandAddress::query()->with('brand')->orderByDesc('is_primary')->orderBy('id')->get()
            ->map(function (BrandAddress $address) use ($point, $miles) {
                $text = self::addressText($address);
                $at = $point === null ? null : self::locate($text, $address->country);
                $distance = null;
                if ($point !== null && $at !== null) {
                    $km = self::kilometres($point, $at);
                    $distance = $miles ? round($km / 1.609344, 1).' mi' : round($km, 1).' km';
                }

                return [
                    'id' => $address->id,
                    'label' => trim(($address->label ?: $address->brand?->name ?: '').' · '.$text, ' ·'),
                    'distance' => $distance,
                ];
            })->values()->all();
    }

    public static function addressText(BrandAddress $address): string
    {
        return implode(', ', array_filter([
            trim(($address->line1 ?? '').' '.($address->line2 ?? '')),
            $address->city,
            trim(($address->region ?? '').' '.($address->postal_code ?? '')),
        ], fn ($part) => filled($part)));
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private static function locate(string $address, string $country): ?array
    {
        if (! Geocoder::enabled() || trim($address) === '') {
            return null;
        }

        return Cache::remember('geocode:'.md5(mb_strtolower($country.'|'.$address)), now()->addDays(30),
            fn () => app(Geocoder::class)->locate($address, $country));
    }

    /**
     * @param  array{lat: float, lng: float}  $a
     * @param  array{lat: float, lng: float}  $b
     */
    private static function kilometres(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b['lat'] - $a['lat']) * $rad;
        $dLng = ($b['lng'] - $a['lng']) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin($dLng / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}
