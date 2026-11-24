<?php

use App\Enums\ApplianceCategory;
use App\Enums\ApplianceType;
use App\Support\Statistics\ApplianceGuess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fields for Statistics (owner's task of November 24): the job's appliance type and brand and "Only diagnostic".
 * New nullable columns (only_diagnostic false); nothing is removed. Existing jobs get the type and brand from their
 * appliance, else from the description, notes and model where the words are clear; the rest stays empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->string('appliance_category', 32)->nullable();
            $table->string('appliance_brand', 100)->nullable();
            $table->boolean('only_diagnostic')->default(false);
        });

        DB::table('service_jobs')->select(['id', 'description', 'notes', 'tech_notes'])->orderBy('id')
            ->chunkById(500, function ($jobs) {
                $appliances = DB::table('job_appliance')
                    ->join('appliances', 'appliances.id', '=', 'job_appliance.appliance_id')
                    ->whereIn('job_appliance.service_job_id', $jobs->pluck('id'))
                    ->orderBy('appliances.id')
                    ->get(['job_appliance.service_job_id', 'appliances.type', 'appliances.manufacturer', 'appliances.model_number', 'appliances.notes'])
                    ->groupBy('service_job_id');

                foreach ($jobs as $job) {
                    $own = $appliances->get($job->id, collect());
                    $text = implode("\n", array_filter([$job->description, $job->notes, $job->tech_notes, ...$own->pluck('model_number')->all(), ...$own->pluck('notes')->all()]));
                    $category = ApplianceCategory::fromApplianceType(ApplianceType::tryFrom((string) $own->first()?->type))
                        ?? ApplianceGuess::type($text);
                    $brand = ApplianceGuess::normalizeBrand($own->pluck('manufacturer')->filter()->first()) ?? ApplianceGuess::brand($text);

                    if ($category !== null || $brand !== null) {
                        DB::table('service_jobs')->where('id', $job->id)->update([
                            'appliance_category' => $category?->value,
                            'appliance_brand' => $brand,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropColumn(['appliance_category', 'appliance_brand', 'only_diagnostic']);
        });
    }
};
