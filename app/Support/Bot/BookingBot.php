<?php

namespace App\Support\Bot;

use App\Actions\Customers\SaveCustomer;
use App\Actions\Jobs\SaveJob;
use App\Enums\JobType;
use App\Enums\LeadSource;
use App\Enums\UserRole;
use App\Enums\VisitStatus;
use App\Models\BotToken;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPhone;
use App\Models\JobVisit;
use App\Models\Membership;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What the website booking bot may do, and nothing more: read free arrival windows, find a customer by phone,
 * add a customer and book a job. It never changes or deletes anything and never sees money.
 */
class BookingBot
{
    /** Two-hour arrival windows, as on the booking form: 9–11, 11–1, 1–3, 3–5 (company time). */
    public const WINDOW_STARTS = [9, 11, 13, 15];

    public const WINDOW_HOURS = 2;

    /** How far ahead the bot can book. */
    public const MAX_DAYS = 30;

    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveJob $saveJob,
    ) {}

    /**
     * Free windows from the given day for the given number of days. A window is free while fewer visits overlap it
     * than the company has people who go on calls; closed days and windows already started are left out.
     *
     * @return list<array{date: string, start: string, end: string}>
     */
    public function freeWindows(Company $company, CarbonImmutable $from, int $days): array
    {
        $timezone = $company->timezone;
        $now = CarbonImmutable::now($timezone);
        $from = $from->setTimezone($timezone)->startOfDay();
        $from = $from->lessThan($now->startOfDay()) ? $now->startOfDay() : $from;
        $days = max(1, min($days, self::MAX_DAYS));
        $capacity = max(1, Membership::query()->assignable()->whereIn('role', [UserRole::Owner->value, UserRole::Technician->value])->count());
        $hours = $company->business_hours ?? Company::defaultBusinessHours();

        $visits = JobVisit::query()
            ->whereIn('status', VisitStatus::openValues())
            ->whereHas('job')
            ->where('scheduled_end', '>', $from->utc())
            ->where('scheduled_start', '<', $from->addDays($days)->utc())
            ->get(['scheduled_start', 'scheduled_end']);

        $free = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $from->addDays($day);
            $weekday = strtolower($date->format('D'));
            $open = $hours[$weekday] ?? null;

            if ($open === null || ($open['closed'] ?? false)) {
                continue;
            }

            foreach (self::WINDOW_STARTS as $hour) {
                $start = $date->setTime($hour, 0);
                $end = $start->addHours(self::WINDOW_HOURS);

                if ($start->lessThanOrEqualTo($now)
                    || ($open['open'] && $start->format('H:i') < $open['open'])
                    || ($open['close'] && $end->format('H:i') > $open['close'])) {
                    continue;
                }

                if ($this->overlapping($visits, $start, $end) < $capacity) {
                    $free[] = ['date' => $date->format('Y-m-d'), 'start' => $start->format('H:i'), 'end' => $end->format('H:i')];
                }
            }
        }

        return $free;
    }

    /**
     * The customer with this phone number: only the first name and the street of each address, so the bot can
     * confirm "Is it the address on Main St?" without reading out the customer's file.
     *
     * @return array{id: int, first_name: string|null, addresses: list<array{id: int, street: string, city: string|null}>}|null
     */
    public function findByPhone(string $phone): ?array
    {
        $number = PhoneNumber::normalize($phone);

        if ($number === '') {
            return null;
        }

        $customerId = CustomerPhone::query()->where('number', $number)->orderBy('id')->value('customer_id');
        $customer = $customerId ? Customer::query()->with('properties')->find($customerId) : null;

        if ($customer === null) {
            return null;
        }

        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name ?: $customer->company_name,
            'addresses' => $customer->properties->map(fn (Property $property) => [
                'id' => $property->id,
                'street' => (string) $property->line1,
                'city' => $property->city,
            ])->values()->all(),
        ];
    }

    /**
     * A new customer with a phone, an optional email and the address of the visit. A phone that is already a
     * customer's returns that customer unchanged (the bot never edits customers).
     *
     * @param  array{first_name: string, last_name?: string|null, phone: string, email?: string|null, address: array<string, mixed>}  $data
     * @return array{customer_id: int, address_id: int|null, existing: bool}
     */
    public function createCustomer(array $data): array
    {
        $existing = $this->findByPhone($data['phone']);

        if ($existing !== null) {
            return ['customer_id' => $existing['id'], 'address_id' => $existing['addresses'][0]['id'] ?? null, 'existing' => true];
        }

        $customer = $this->saveCustomer->handle(
            null,
            [
                'type' => 'residential',
                'first_name' => trim($data['first_name']),
                'last_name' => filled($data['last_name'] ?? null) ? trim((string) $data['last_name']) : null,
                'lead_source' => LeadSource::Website->value,
            ],
            [['label' => 'mobile', 'number' => $data['phone'], 'is_primary' => true]],
            filled($data['email'] ?? null) ? [['label' => 'personal', 'email' => mb_strtolower((string) $data['email']), 'is_primary' => true]] : [],
            [...$data['address'], 'is_primary' => true],
        );

        return ['customer_id' => $customer->id, 'address_id' => $customer->primaryProperty()->value('id'), 'existing' => false];
    }

    /**
     * Books a job in a free window. The window must be one freeWindows() offers right now.
     *
     * @param  array{customer_id: int, address_id?: int|null, date: string, start: string, problem: string, appliance?: string|null}  $data
     */
    public function book(BotToken $token, array $data): ServiceJob
    {
        $company = $token->company;
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $property = filled($data['address_id'] ?? null)
            ? $customer->properties()->findOrFail($data['address_id'])
            : $customer->primaryProperty()->first();

        if ($property === null) {
            throw ValidationException::withMessages(['address_id' => __('bot.errors.no_address')]);
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $company->timezone);
        $window = collect($this->freeWindows($company, $date->startOfDay(), 1))
            ->first(fn (array $w) => $w['date'] === $data['date'] && $w['start'] === $data['start']);

        if ($window === null) {
            throw ValidationException::withMessages(['start' => __('bot.errors.window_taken')]);
        }

        $brandId = Brand::query()->orderBy('id')->value('id');

        if ($brandId === null) {
            throw ValidationException::withMessages(['brand' => __('bot.errors.no_brand')]);
        }

        $at = fn (string $time) => CarbonImmutable::createFromFormat('Y-m-d H:i', "{$window['date']} {$time}", $company->timezone)->utc();
        $description = trim(implode(' • ', array_filter([$data['appliance'] ?? null, $data['problem']])));

        return $this->saveJob->create(
            $customer,
            [
                'brand_id' => $brandId,
                'property_id' => $property->id,
                'job_type' => JobType::Repair->value,
                'lead_source' => LeadSource::Website->value,
                'description' => mb_substr($description, 0, 1000),
            ],
            [],
            [],
            null,
            [
                'attributes' => [
                    'scheduled_start' => $at($window['start']),
                    'scheduled_end' => $at($window['end']),
                    'estimated_duration_minutes' => null,
                    'strict_arrival' => false,
                ],
                'assignee_ids' => [],
            ],
            $token->user,
        );
    }

    /**
     * @param  Collection<int, JobVisit>  $visits
     */
    private function overlapping(Collection $visits, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return $visits->filter(fn (JobVisit $visit) => $visit->scheduled_start->lessThan($end) && $visit->scheduled_end->greaterThan($start))->count();
    }
}
