<?php

namespace App\Models;

use App\Enums\JobType;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Jobs\ChecklistDefaults;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's checklist for one job type. Copied onto each new job of that type.
 *
 * @property int $id
 * @property int $company_id
 * @property JobType $job_type
 * @property list<string> $items
 */
class ChecklistTemplate extends Model
{
    use BelongsToCompany;

    protected $fillable = ['job_type', 'items'];

    protected $attributes = ['items' => '[]'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'job_type' => JobType::class,
            'items' => 'array',
        ];
    }

    /**
     * Creates the default checklists for the current company.
     */
    public static function createDefaults(): void
    {
        foreach (ChecklistDefaults::all() as $jobType => $items) {
            static::query()->firstOrCreate(['job_type' => $jobType], ['items' => $items]);
        }
    }
}
