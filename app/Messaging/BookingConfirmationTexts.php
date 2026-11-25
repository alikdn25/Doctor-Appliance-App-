<?php

namespace App\Messaging;

use App\Enums\VisitStatus;
use App\Models\Brand;
use App\Models\Company;
use App\Models\JobVisit;
use App\Models\ServiceJob;
use App\Support\Locale\Currencies;
use App\Support\Locale\LocalTime;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use IntlDateFormatter;
use NumberFormatter;

/**
 * Settings → Company → Booking confirmation (owner's task of November 25): the company's texts, switches, notice hours
 * and fee (companies.booking_confirmation; a missing key = the default), and the finished SMS / email for a job.
 *
 * A job without a time says "exact time will be confirmed shortly"; without a day "we will confirm the date and time
 * shortly"; without an address or appliance those parts of the sentence are left out. No empty gaps, never "null".
 */
class BookingConfirmationTexts
{
    public const PLACEHOLDERS = ['first_name', 'company_name', 'day', 'date', 'time_window', 'address', 'appliance', 'company_phone', 'cancellation_policy', 'cancellation_hours', 'cancellation_fee'];

    public const TEXT_KEYS = ['sms_text', 'email_subject', 'email_text', 'policy_with_fee', 'policy_without_fee'];

    /**
     * @return array{enabled: bool, by_sms: bool, by_email: bool, sms_text: string, email_subject: string, email_text: string, policy_with_fee: string, policy_without_fee: string, cancellation_hours: int|null, cancellation_fee: string|null}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'by_sms' => true,
            'by_email' => true,
            'sms_text' => (string) __('booking_confirmation.defaults.sms_text'),
            'email_subject' => (string) __('booking_confirmation.defaults.email_subject'),
            'email_text' => (string) __('booking_confirmation.defaults.sms_text'),
            'policy_with_fee' => (string) __('booking_confirmation.defaults.policy_with_fee'),
            'policy_without_fee' => (string) __('booking_confirmation.defaults.policy_without_fee'),
            'cancellation_hours' => 24,
            'cancellation_fee' => '85',
        ];
    }

    /**
     * The company's settings, defaults filled in.
     *
     * @return array{enabled: bool, by_sms: bool, by_email: bool, sms_text: string, email_subject: string, email_text: string, policy_with_fee: string, policy_without_fee: string, cancellation_hours: int|null, cancellation_fee: string|null}
     */
    public static function settings(Company $company): array
    {
        $saved = is_array($company->booking_confirmation) ? $company->booking_confirmation : [];
        $settings = self::defaults();

        foreach (['enabled', 'by_sms', 'by_email'] as $key) {
            if (array_key_exists($key, $saved)) {
                $settings[$key] = (bool) $saved[$key];
            }
        }
        foreach (self::TEXT_KEYS as $key) {
            if (filled($saved[$key] ?? null)) {
                $settings[$key] = (string) $saved[$key];
            }
        }
        if (array_key_exists('cancellation_hours', $saved)) {
            $settings['cancellation_hours'] = filled($saved['cancellation_hours']) ? (int) $saved['cancellation_hours'] : null;
        }
        if (array_key_exists('cancellation_fee', $saved)) {
            $settings['cancellation_fee'] = filled($saved['cancellation_fee']) ? (string) $saved['cancellation_fee'] : null;
        }

        return $settings;
    }

    /**
     * The values of the placeholders for a job (null = not known).
     *
     * @return array<string, string|null>
     */
    public static function variables(Company $company, ServiceJob $job): array
    {
        $job->loadMissing(['customer', 'brand', 'property', 'appliances']);
        $visit = self::visit($job);
        $customer = $job->customer;
        $start = $visit?->scheduled_start;
        $day = $start ?? $job->planned_date;

        $appliance = $job->appliances->first()?->type->label() ?? $job->appliance_category?->label();
        $phone = $job->brand?->phone ?: self::companyPhone();

        return [
            'first_name' => $customer?->first_name ?: ($customer?->hasName() ? $customer->display_name : null),
            'company_name' => $job->brand?->name ?: $company->name,
            'day' => $day ? self::format($company, $day, 'EEEE', $start === null) : null,
            'date' => $day ? self::format($company, $day, 'MMMM d', $start === null) : null,
            'time_window' => $visit ? MessageContext::window($visit) : null,
            'address' => filled($job->property?->line1) ? $job->property->fullAddress() : null,
            'appliance' => filled($appliance) && ($job->appliance_category === null || $job->appliance_category->value !== 'other' || $job->appliances->isNotEmpty())
                ? mb_strtolower((string) $appliance) : null,
            'company_phone' => $phone ? PhoneNumber::display($phone, $company->country) : null,
        ];
    }

    /**
     * Example values for the settings preview and "Send test to me".
     *
     * @return array<string, string|null>
     */
    public static function sampleVariables(Company $company): array
    {
        $day = now($company->timezone)->addDay()->setTime(10, 0);

        return [
            'first_name' => 'Maria',
            'company_name' => $company->name,
            'day' => self::format($company, $day, 'EEEE', false),
            'date' => self::format($company, $day, 'MMMM d', false),
            'time_window' => self::format($company, $day, null, false).' - '.self::format($company, $day->copy()->addHours(2), null, false),
            'address' => '123 Main Street',
            'appliance' => 'dishwasher',
            'company_phone' => ($phone = self::companyPhone()) ? PhoneNumber::display($phone, $company->country) : null,
        ];
    }

    /**
     * The finished text for a template (SMS, email subject or body) with the job's values.
     *
     * @param  array<string, string|null>  $variables
     * @param  array<string, mixed>|null  $settings
     */
    public static function render(Company $company, string $template, array $variables, ?array $settings = null): string
    {
        $settings ??= self::settings($company);
        $hours = $settings['cancellation_hours'];
        $fee = filled($settings['cancellation_fee']) ? self::fee($company, (string) $settings['cancellation_fee']) : null;

        $policy = '';
        if ($hours !== null) {
            $policy = strtr($fee !== null ? $settings['policy_with_fee'] : $settings['policy_without_fee'], [
                '{cancellation_hours}' => (string) $hours,
                '{cancellation_fee}' => (string) $fee,
            ]);
        }

        $values = [...$variables, 'cancellation_policy' => $policy, 'cancellation_hours' => $hours === null ? null : (string) $hours, 'cancellation_fee' => $fee];

        // No day: the whole "for {day}, {date}, {time_window}" says the date and time will be confirmed.
        if (blank($values['date'] ?? null)) {
            $template = (string) preg_replace('/\s*\bfor\s+\{day\},?\s*\{date\},?\s*\{time_window\}/u', ', {time_window}', $template);
            $values['day'] = null;
            $values['date'] = null;
            $values['time_window'] = (string) __('booking_confirmation.no_date');
        } elseif (blank($values['time_window'] ?? null)) {
            $values['time_window'] = (string) __('booking_confirmation.no_time');
        }

        // No address / appliance: their part of the sentence goes.
        if (blank($values['address'] ?? null)) {
            $template = (string) preg_replace('/\s*\bat\s+\{address\}/u', '', $template);
        }
        if (blank($values['appliance'] ?? null)) {
            $template = (string) preg_replace('/\s*\bfor\s+\{appliance\}\s+service\b/u', '', $template);
            $template = (string) preg_replace('/\s*\{appliance\}\s+service\b/u', '', $template);
        }
        if (blank($values['company_phone'] ?? null)) {
            $template = (string) preg_replace('/\s*\bor\s+call\s+\{company_phone\}/u', '', $template);
        }

        $replace = [];
        foreach (self::PLACEHOLDERS as $name) {
            $replace['{'.$name.'}'] = trim((string) ($values[$name] ?? ''));
        }
        $text = strtr($template, $replace);

        // Tidy what empty values leave: doubled spaces and commas, a space before punctuation.
        $text = (string) preg_replace('/[ \t]{2,}/u', ' ', $text);
        $text = (string) preg_replace('/\s+([,.!?])/u', '$1', $text);
        $text = (string) preg_replace('/,(\s*,)+/u', ',', $text);
        $text = (string) preg_replace('/,\s*\./u', '.', $text);
        $text = (string) preg_replace('/ *\n */u', "\n", $text);

        return trim($text);
    }

    /** "$85" (a whole amount without cents), in the company's currency and regional format. */
    public static function fee(Company $company, string $amount): string
    {
        $value = (float) str_replace([',', ' '], ['.', ''], $amount);
        $formatter = new NumberFormatter(str_replace('-', '_', $company->locale ?: 'en'), NumberFormatter::CURRENCY);
        if (floor($value) === $value) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
        } else {
            $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, Currencies::decimals($company->currency));
        }

        return str_replace(["\u{202F}", "\u{00A0}"], ' ', (string) $formatter->formatCurrency($value, $company->currency));
    }

    /** The phone of the company's first brand (a job without a brand). Must run in a tenant context. */
    private static function companyPhone(): ?string
    {
        return Brand::query()->whereNotNull('phone')->orderBy('id')->value('phone');
    }

    /** The visit the confirmation is about: the next one still to come, else the latest. */
    public static function visit(ServiceJob $job): ?JobVisit
    {
        return JobVisit::query()->where('service_job_id', $job->id)
            ->whereIn('status', [VisitStatus::Scheduled->value, VisitStatus::OnTheWay->value])
            ->orderBy('scheduled_start')->first()
            ?? JobVisit::query()->where('service_job_id', $job->id)->whereIn('status', VisitStatus::openValues())
                ->orderByDesc('scheduled_start')->first();
    }

    private static function format(Company $company, CarbonInterface $at, ?string $pattern, bool $plainDate): string
    {
        // A day without a time ("No time") is a calendar date: not moved by the time zone.
        $zone = $plainDate ? 'UTC' : $company->timezone;
        $text = $pattern === null
            ? LocalTime::format($at, $zone, $company->locale)
            : LocalTime::format($at, $zone, $company->locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, $pattern);

        return str_replace(["\u{202F}", "\u{00A0}", "\u{2009}"], ' ', $text);
    }
}
