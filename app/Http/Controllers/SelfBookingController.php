<?php

namespace App\Http\Controllers;

use App\Actions\Jobs\DirectBooking;
use App\Enums\PhotoKind;
use App\Enums\UserRole;
use App\Messaging\BookingConfirmation;
use App\Models\BookingLink;
use App\Models\Company;
use App\Models\Customer;
use App\Models\JobPhoto;
use App\Models\User;
use App\Notifications\AppAlert;
use App\Support\Jobs\NearbyWindows;
use App\Support\PhoneNumber;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Self-booking by link (owner's task of November 19): /book/{token}, no sign-in. The free arrival windows of the next 7
 * days (the company's windows minus busy ones; days with a job nearby first), name and phone (filled when known),
 * address, what is broken and a photo of the sticker with the model number. Sending creates the booking marked
 * "Self-booked" and pushes the Owners. A link lives 7 days and books once.
 */
class SelfBookingController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * A new link for a phone number (or a known customer), used by the AI's reply draft.
     */
    public static function link(?Customer $customer, ?string $phone, ?User $creator = null): string
    {
        $link = BookingLink::query()->create([
            'token' => Str::random(40),
            'customer_id' => $customer?->id,
            'phone' => $phone,
            'name' => $customer?->display_name,
            'expires_at' => now()->addDays(BookingLink::DAYS),
            'created_by' => $creator?->id,
        ]);

        return route('self-booking.show', $link->token);
    }

    public function show(string $token): Response
    {
        [$link, $company] = $this->resolve($token);

        return $this->tenancy->runAs($company, function () use ($link, $company) {
            if (! $link->isUsable()) {
                return Inertia::render('public/self-booking', [
                    'state' => session('booked') ? 'booked' : ($link->used_at ? 'used' : 'expired'),
                    'company' => $company->name,
                ]);
            }

            $property = $link->customer?->primaryProperty;

            return Inertia::render('public/self-booking', [
                'state' => 'open',
                'token' => $link->token,
                'company' => $company->name,
                'locale' => $company->locale,
                'prefill' => [
                    'name' => (string) ($link->customer?->display_name ?? $link->name),
                    'phone' => $link->phone ? PhoneNumber::display($link->phone, $company->country) : (string) $link->customer?->primaryPhone?->number,
                    'address' => $property?->hasAddress() ? $property->fullAddress() : '',
                ],
                'windows' => $this->windows($company, NearbyWindows::point($property)),
            ]);
        });
    }

    /**
     * The windows again, ranked for an address typed on the page.
     */
    public function nearby(Request $request, string $token): JsonResponse
    {
        [$link, $company] = $this->resolve($token);
        abort_unless($link->isUsable(), 410);
        $data = $request->validate(['address' => ['required', 'string', 'max:300']]);

        return $this->tenancy->runAs($company, fn () => response()->json([
            'windows' => $this->windows($company, NearbyWindows::point(null, $data['address'])),
        ]));
    }

    public function store(Request $request, string $token, DirectBooking $booking): RedirectResponse
    {
        [$link, $company] = $this->resolve($token);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'address' => ['required', 'string', 'max:300'],
            'problem' => ['required', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:10240'],
        ]);

        return $this->tenancy->runAs($company, function () use ($link, $company, $data, $request, $booking) {
            if (! PhoneNumber::isPossible($data['phone'], $company->country)) {
                throw ValidationException::withMessages(['phone' => __('booking.self.bad_phone')]);
            }

            $job = DB::transaction(function () use ($link, $company, $data, $booking) {
                $locked = BookingLink::query()->lockForUpdate()->findOrFail($link->id);
                if (! $locked->isUsable()) {
                    throw ValidationException::withMessages(['date' => __('booking.self.link_used')]);
                }
                // The window must still be free now.
                $free = collect(NearbyWindows::freeWindows($company))
                    ->contains(fn (array $w) => $w['date'] === $data['date'] && $w['start'] === $data['start'] && $w['end'] === $data['end']);
                if (! $free) {
                    throw ValidationException::withMessages(['date' => __('booking.self.taken')]);
                }

                $owner = User::query()->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)
                    ->where('role', UserRole::Owner->value)->where('is_active', true))->orderBy('id')->firstOrFail();
                $phone = PhoneNumber::normalize($data['phone'], $company->country);
                $customer = $locked->customer;
                $parts = preg_split('/\s+/', trim($data['name']), 2) ?: [];
                $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['start']}", $company->timezone);
                $end = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['end']}", $company->timezone);
                $address = ['line1' => trim($data['address']), 'country' => $company->country];
                $property = $customer?->properties()->get()->first(fn ($p) => $p->hasAddress() && mb_strtolower($p->fullAddress()) === mb_strtolower(trim($data['address'])));

                $job = $booking->create(
                    $customer,
                    $property,
                    $start,
                    $end,
                    $data['problem'],
                    $owner,
                    newCustomer: $customer === null ? [
                        'customer' => ['type' => 'residential', 'first_name' => $parts[0] ?? null, 'last_name' => $parts[1] ?? null],
                        'phones' => [['number' => $phone, 'label' => 'mobile', 'is_primary' => true]],
                        'emails' => [],
                        'property' => $address,
                    ] : null,
                    newProperty: $customer !== null && $property === null ? $address : null,
                );
                $job->forceFill(['self_booked' => true])->save();
                $locked->forceFill(['used_at' => now(), 'service_job_id' => $job->id])->save();

                return $job;
            });

            if ($request->hasFile('photo')) {
                $this->photo($request, $job->id, $company->id);
            }

            // Every new booking confirms itself to the customer (owner's task of November 25).
            try {
                app(BookingConfirmation::class)->send($job, null);
            } catch (Throwable $e) {
                report($e);
            }

            $when = CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $company->timezone)->isoFormat('ddd MMM D').' '.$data['start'].'–'.$data['end'];
            $this->notify($company, $job->number, $data['name'], $when, $job->id);

            return redirect()->route('self-booking.show', $link->token)->with('booked', true);
        });
    }

    /**
     * @return list<array{date: string, start: string, end: string, nearby: string|null}>
     */
    private function windows(Company $company, ?array $point): array
    {
        return NearbyWindows::rank(NearbyWindows::freeWindows($company), NearbyWindows::days($company, $point));
    }

    private function photo(Request $request, int $jobId, int $companyId): void
    {
        try {
            $file = $request->file('photo');
            $uuid = (string) Str::uuid();
            $path = "companies/{$companyId}/jobs/{$jobId}/photos/{$uuid}.".($file->extension() ?: 'jpg');
            PrivateMedia::disk()->put($path, (string) file_get_contents($file->getRealPath()));
            $photo = new JobPhoto(['kind' => PhotoKind::Before, 'client_uuid' => $uuid, 'taken_at' => now()]);
            $photo->service_job_id = $jobId;
            $photo->path = $path;
            $photo->size = (int) $file->getSize();
            $photo->save();
        } catch (Throwable $e) {
            Log::warning('Self-booking photo not saved', ['error' => $e->getMessage()]);
        }
    }

    private function notify(Company $company, string|int $number, string $name, string $when, int $jobId): void
    {
        $owners = User::query()->where('is_active', true)->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)
            ->where('is_active', true)->where('role', UserRole::Owner->value))->get();
        foreach ($owners as $owner) {
            try {
                $owner->notify(new AppAlert('self_booking', __('booking.self.push_title', ['name' => $name]),
                    __('booking.self.push_body', ['number' => $number, 'when' => $when]), route('jobs.show', $jobId), mail: false));
            } catch (Throwable $e) {
                Log::warning('Self-booking alert failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * @return array{0: BookingLink, 1: Company}
     */
    private function resolve(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        $link = BookingLink::withoutCompanyScope()->where('token', $token)->first();
        abort_if($link === null, 404);
        $company = Company::query()->where('status', 'active')->find($link->company_id);
        abort_if($company === null, 404);

        return [$link, $company];
    }
}
