<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The state of the company's conversation with one phone number: whether a reply is owed (awaiting_since), the
 * reminders about it, a pending auto reply and the last one sent (one per 24 hours per number), "Done" and the
 * urgent mark from the AI.
 *
 * @property int $id
 * @property int $company_id
 * @property string $phone E.164
 * @property Carbon|null $awaiting_since The first incoming text no one has answered yet
 * @property bool $urgent
 * @property int $reminders_sent
 * @property int $reminders_max 3, or 1 for a text that came outside business hours
 * @property Carbon|null $next_reminder_at
 * @property Carbon|null $auto_reply_due_at
 * @property string|null $auto_reply_kind text or missed_call
 * @property Carbon|null $last_auto_reply_at
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
class SmsThread extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'phone', 'awaiting_since', 'urgent', 'reminders_sent', 'reminders_max', 'next_reminder_at',
        'auto_reply_due_at', 'auto_reply_kind', 'last_auto_reply_at', 'done_at', 'done_by',
    ];

    protected $attributes = ['urgent' => false, 'reminders_sent' => 0, 'reminders_max' => 3];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'awaiting_since' => 'datetime',
            'urgent' => 'boolean',
            'reminders_sent' => 'integer',
            'reminders_max' => 'integer',
            'next_reminder_at' => 'datetime',
            'auto_reply_due_at' => 'datetime',
            'last_auto_reply_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }
}
