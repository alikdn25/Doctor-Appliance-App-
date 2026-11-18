<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One nightly upload of the company's customers and next week's jobs to its Google Drive (owner's task of
 * November 19). The 30 newest successful copies are kept; older files are deleted from the Drive folder.
 *
 * @property int $id
 * @property int $company_id
 * @property string $status ok or failed
 * @property string|null $error
 * @property list<array{id: string, name: string}>|null $files
 * @property Carbon|null $created_at
 */
class DriveBackup extends Model
{
    use BelongsToCompany;

    public const OK = 'ok';

    public const FAILED = 'failed';

    protected $fillable = ['status', 'error', 'files'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['files' => 'array'];
    }
}
