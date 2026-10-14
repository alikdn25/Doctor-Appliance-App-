<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One backup attempt (database or files), kept on this server for status checks. Platform-level, no company.
 *
 * @property int $id
 * @property string $kind database or files
 * @property string $reason hourly, daily, deploy, restore, manual
 * @property string $status running, succeeded, failed
 * @property list<string>|null $paths
 * @property int|null $size_bytes
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class BackupRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paths' => 'array',
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
