<?php

namespace App\Http\Controllers\Company;

use App\Enums\SmsMode;
use App\Http\Controllers\Controller;
use App\Messaging\SmsThreads;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Company → Auto messages (Owner): auto reply to texts, the missed-call text, reminders about unanswered texts and
 * the "Possible warranty" window. Auto replies are off by default.
 */
class AutoMessagesController extends Controller
{
    public const DELAYS = [0, 5, 15, 30, 60];

    public function edit(): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        return Inertia::render('company/auto-messages', [
            'settings' => [
                'auto_reply_enabled' => $company->auto_reply_enabled,
                'auto_reply_text' => SmsThreads::text($company, SmsThreads::TEXT),
                'auto_reply_delay_minutes' => $company->auto_reply_delay_minutes,
                'missed_call_reply_enabled' => $company->missed_call_reply_enabled,
                'missed_call_reply_text' => SmsThreads::text($company, SmsThreads::MISSED_CALL),
                'sms_reminder_hours' => $company->sms_reminder_hours,
                'warranty_lookback_days' => $company->warranty_lookback_days,
            ],
            'delays' => self::DELAYS,
            'automatic' => $company->sms_mode === SmsMode::Automatic,
            'missedCallsAvailable' => (bool) config('sms.missed_calls.enabled'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $data = $request->validate([
            'auto_reply_enabled' => ['required', 'boolean'],
            'auto_reply_text' => ['nullable', 'string', 'max:480'],
            'auto_reply_delay_minutes' => ['required', 'integer', Rule::in(self::DELAYS)],
            'missed_call_reply_enabled' => ['required', 'boolean'],
            'missed_call_reply_text' => ['nullable', 'string', 'max:480'],
            'sms_reminder_hours' => ['required', 'integer', 'min:1', 'max:24'],
            'warranty_lookback_days' => ['required', 'integer', 'min:0', 'max:730'],
        ]);

        // The default text is kept as "no own text", so it follows the company name.
        foreach (['auto_reply_text' => SmsThreads::TEXT, 'missed_call_reply_text' => SmsThreads::MISSED_CALL] as $field => $kind) {
            $text = trim((string) ($data[$field] ?? ''));
            $data[$field] = $text === '' || $text === __("automation.auto.default_{$kind}", ['company' => $company->name]) ? null : $text;
        }

        $company->fill($data);
        $changes = array_keys($company->getDirty());
        $company->save();

        if ($changes !== []) {
            $audit->record('company.auto_messages_updated', $company, ['fields' => $changes]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.settings.saved')]);

        return to_route('company.auto-messages.edit');
    }
}
