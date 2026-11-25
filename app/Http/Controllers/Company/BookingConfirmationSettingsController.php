<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmationMail;
use App\Messaging\BookingConfirmation;
use App\Messaging\BookingConfirmationTexts;
use App\Messaging\Messenger;
use App\Services\AuditLogger;
use App\Support\Locale\Currencies;
use App\Support\PhoneNumber;
use App\Support\Ui\SavedToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Company → Booking confirmation (owner's task of November 25): switches, texts, notice hours and fee,
 * with a live preview and "Send test to me". Changes apply to the next confirmation at once.
 */
class BookingConfirmationSettingsController extends Controller
{
    public function edit(): Response
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $settings = BookingConfirmationTexts::settings($company);

        return Inertia::render('company/booking-confirmation', [
            'settings' => [...$settings, 'cancellation_fee' => $settings['cancellation_fee'] ?? ''],
            'defaults' => BookingConfirmationTexts::defaults(),
            'placeholders' => BookingConfirmationTexts::PLACEHOLDERS,
            'sample' => BookingConfirmationTexts::sampleVariables($company),
            'feeSymbol' => self::symbol($company->currency, $company->locale),
            'noTime' => __('booking_confirmation.no_time'),
            'noDate' => __('booking_confirmation.no_date'),
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'by_sms' => ['nullable', 'boolean'],
            'by_email' => ['nullable', 'boolean'],
            'sms_text' => ['nullable', 'string', 'max:1600'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'email_text' => ['nullable', 'string', 'max:5000'],
            'policy_with_fee' => ['nullable', 'string', 'max:1000'],
            'policy_without_fee' => ['nullable', 'string', 'max:1000'],
            'cancellation_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'cancellation_fee' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], [], [
            'cancellation_hours' => __('booking_confirmation.settings.cancellation_hours'),
            'cancellation_fee' => __('booking_confirmation.settings.cancellation_fee', ['symbol' => '']),
        ]);

        $defaults = BookingConfirmationTexts::defaults();
        $saved = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'by_sms' => (bool) ($data['by_sms'] ?? false),
            'by_email' => (bool) ($data['by_email'] ?? false),
            'cancellation_hours' => filled($data['cancellation_hours'] ?? null) ? (int) $data['cancellation_hours'] : null,
            'cancellation_fee' => filled($data['cancellation_fee'] ?? null) ? (string) $data['cancellation_fee'] : null,
        ];
        // A text equal to the default (or empty) is not stored: it follows the default.
        foreach (BookingConfirmationTexts::TEXT_KEYS as $key) {
            $text = trim((string) ($data[$key] ?? ''));
            if ($text !== '' && $text !== $defaults[$key]) {
                $saved[$key] = $text;
            }
        }

        $old = $company->booking_confirmation;
        $company->forceFill(['booking_confirmation' => $saved])->save();
        $audit->record('company.booking_confirmation_updated', $company, ['old' => $old, 'new' => $saved]);

        SavedToast::flash(route('company.booking-confirmation.edit'), __('booking_confirmation.settings.saved'));

        return back();
    }

    /** "Send test to me": the SMS and the email with the example customer, to the person's own phone and email. */
    public function test(Request $request, Messenger $messenger): RedirectResponse
    {
        $company = currentCompany();
        Gate::authorize('update', $company);
        $user = $request->user();
        $settings = BookingConfirmationTexts::settings($company);
        $variables = BookingConfirmationTexts::sampleVariables($company);
        $sent = [];
        $problems = [];

        if (filled($user->phone)) {
            $message = $messenger->smsToNumber(PhoneNumber::normalize($user->phone, $company->country),
                BookingConfirmationTexts::render($company, $settings['sms_text'], $variables, $settings), $user);
            if ($message->status === 'blocked') {
                $problems[] = __('booking_confirmation.settings.test_sms_failed', ['reason' => $message->status_reason]);
            } else {
                $sent[] = __('booking_confirmation.status.sms').' → '.PhoneNumber::display($user->phone, $company->country);
            }
        }

        if (filled($user->email)) {
            Mail::to($user->email)->queue(new BookingConfirmationMail(null, $company->id,
                BookingConfirmationTexts::render($company, $settings['email_subject'], $variables, $settings),
                BookingConfirmationTexts::render($company, $settings['email_text'], $variables, $settings),
                BookingConfirmation::details($company, $variables, $settings)));
            $sent[] = __('booking_confirmation.status.email').' → '.$user->email;
        }

        Inertia::flash('toast', match (true) {
            $sent === [] && $problems === [] => ['type' => 'error', 'message' => __('booking_confirmation.settings.test_nothing')],
            $sent === [] => ['type' => 'error', 'message' => implode(' ', $problems)],
            default => ['type' => 'success', 'message' => trim(__('booking_confirmation.settings.test_sent', ['channels' => implode(', ', $sent)]).' '.implode(' ', $problems))],
        });

        return back();
    }

    private static function symbol(string $currency, ?string $locale): string
    {
        $formatter = new \NumberFormatter(str_replace('-', '_', $locale ?: 'en'), \NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);

        return Currencies::exists($currency) ? (string) $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL) : $currency;
    }
}
