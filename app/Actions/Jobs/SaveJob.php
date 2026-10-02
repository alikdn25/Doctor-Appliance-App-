<?php

namespace App\Actions\Jobs;

use App\Actions\Customers\SaveCustomer;
use App\Enums\JobStatus;
use App\Models\Appliance;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a job (optionally with a new customer typed in on the phone and a first visit)
 * or updates a job's details. Must run in a tenant context; input is validated by JobRequest.
 */
class SaveJob
{
    public function __construct(
        private readonly SaveCustomer $saveCustomer,
        private readonly SaveVisit $saveVisit,
        private readonly ChangeJobStatus $changeStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Job fields (brand_id, property_id, job_type, ...).
     * @param  list<int>  $applianceIds  Existing appliances at the property.
     * @param  list<array<string, mixed>>  $newAppliances  Appliances to add at the property.
     * @param  array{customer: array<string, mixed>, phones: list<array<string, mixed>>, emails: list<array<string, mixed>>, property: array<string, mixed>}|null  $newCustomer
     * @param  array{attributes: array<string, mixed>, assignee_ids: list<int>}|null  $visit
     */
    public function create(
        ?Customer $customer,
        array $attributes,
        array $applianceIds,
        array $newAppliances,
        ?array $newCustomer,
        ?array $visit,
        User $user,
    ): ServiceJob {
        return DB::transaction(function () use ($customer, $attributes, $applianceIds, $newAppliances, $newCustomer, $visit, $user) {
            if ($newCustomer !== null) {
                $customer = $this->saveCustomer->handle(
                    null,
                    $newCustomer['customer'],
                    $newCustomer['phones'],
                    $newCustomer['emails'],
                    $newCustomer['property'],
                );
                $attributes['property_id'] = $customer->primaryProperty()->value('id');
            }

            /** @var Customer $customer */
            $job = new ServiceJob;
            $job->fill($attributes);
            $job->customer()->associate($customer);
            $job->number = $this->nextNumber();
            $job->created_by = $user->id;
            $job->status = JobStatus::New;
            $job->save();

            $this->syncAppliances($job, $applianceIds, $newAppliances);
            $job->applyChecklistTemplate();
            $this->changeStatus->created($job, $user);

            if ($visit !== null) {
                $this->saveVisit->handle($job, null, $visit['attributes'], $visit['assignee_ids'], $user);
            }

            return $job;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $applianceIds
     * @param  list<array<string, mixed>>  $newAppliances
     */
    public function update(ServiceJob $job, array $attributes, array $applianceIds, array $newAppliances): ServiceJob
    {
        return DB::transaction(function () use ($job, $attributes, $applianceIds, $newAppliances) {
            $job->fill($attributes);
            $typeChanged = $job->isDirty('job_type');
            $job->save();
            $this->syncAppliances($job, $applianceIds, $newAppliances);

            if ($typeChanged) {
                $job->applyChecklistTemplate();
            }

            return $job;
        });
    }

    /**
     * Adds an appliance at the job's property and links it to the job.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addAppliance(ServiceJob $job, array $attributes): Appliance
    {
        $appliance = new Appliance($attributes);
        $appliance->property()->associate($job->property_id);
        $appliance->save();

        $job->appliances()->syncWithoutDetaching([$appliance->id]);

        return $appliance;
    }

    /**
     * @param  list<int>  $applianceIds
     * @param  list<array<string, mixed>>  $newAppliances
     */
    private function syncAppliances(ServiceJob $job, array $applianceIds, array $newAppliances): void
    {
        // Only appliances at the job's property can be linked (Appliance is tenant-scoped).
        $ids = Appliance::query()
            ->where('property_id', $job->property_id)
            ->whereKey($applianceIds)
            ->pluck('id')
            ->all();

        $job->appliances()->sync($ids);

        foreach ($newAppliances as $attributes) {
            $this->addAppliance($job, $attributes);
        }
    }

    /**
     * Next job number of the current company (row lock keeps numbers unique under concurrency).
     */
    private function nextNumber(): int
    {
        $company = Company::query()->lockForUpdate()->findOrFail(currentCompany()->id);
        $number = $company->job_next_number;
        $company->increment('job_next_number');

        return $number;
    }
}
