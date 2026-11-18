<?php

namespace App\Actions\Jobs;

use App\Enums\JobType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A booking made without the Book customer form (owner's task of November 19): from a button on a push notification
 * ("Book Thu 10–12" for a known customer) or from the customer's own self-booking page. The company's first active
 * brand, Repair (or the vertical's first job type), the given address and arrival window. Must run in a tenant context.
 */
class DirectBooking
{
    public function __construct(private readonly SaveJob $save) {}

    /**
     * @param  array{customer: array<string, mixed>, phones: list<array<string, mixed>>, emails: list<array<string, mixed>>, property: array<string, mixed>|null}|null  $newCustomer
     * @param  array<string, mixed>|null  $newProperty
     */
    public function create(
        ?Customer $customer,
        ?Property $property,
        CarbonImmutable $start,
        CarbonImmutable $end,
        ?string $description,
        User $user,
        ?array $newCustomer = null,
        ?array $newProperty = null,
    ): ServiceJob {
        $types = currentCompany()->vertical->jobTypes();

        return $this->save->create(
            $customer,
            [
                'brand_id' => Brand::query()->where('is_active', true)->orderBy('id')->value('id'),
                'property_id' => $property?->id,
                'job_type' => in_array(JobType::Repair, $types, true) ? JobType::Repair->value : ($types[0] ?? JobType::Repair)->value,
                'description' => $description !== null && trim($description) !== '' ? mb_substr(trim($description), 0, 2000) : null,
            ],
            [],
            [],
            $newCustomer,
            ['attributes' => [
                'scheduled_start' => $start->utc(),
                'scheduled_end' => $end->utc(),
                'estimated_duration_minutes' => null,
                'strict_arrival' => false,
            ], 'assignee_ids' => []],
            $user,
            newProperty: $newProperty,
        );
    }
}
