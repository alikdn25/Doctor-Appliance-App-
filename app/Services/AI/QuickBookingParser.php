<?php

namespace App\Services\AI;

use App\Enums\ApplianceType;
use App\Models\Company;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Quick booking: a free text (dictated, typed or an SMS from the customer; Russian, English or mixed) read by the AI
 * (AI_MODEL_BOOKING) into the booking form's fields. Field values come back in English, names and addresses as
 * written; "today", "tomorrow", "on Monday" count in the company's time zone; phones in E.164. Anything not in the
 * text stays empty. Nothing is saved here: the person checks the form and presses Save.
 */
class QuickBookingParser
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function parse(string $text, Company $company, User $user): array
    {
        $raw = $this->ai->send(
            AiFeature::QuickBooking,
            $company,
            $user,
            $this->instructions($company),
            [['role' => 'user', 'content' => trim($text)]],
            schema: self::schema(),
            maxTokens: 2000,
            effort: 'low',
        )->json();

        return $this->normalize($raw, $company);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];
        $properties = [
            'first_name' => $text,
            'last_name' => $text,
            'phone' => $text,
            'email' => $text,
            'address' => [
                'type' => ['object', 'null'],
                'properties' => ['line1' => $text, 'city' => $text, 'region' => $text, 'postal_code' => $text],
                'required' => ['line1', 'city', 'region', 'postal_code'],
                'additionalProperties' => false,
            ],
            'unit' => $text,
            'buzzer' => $text,
            'appliance_type' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn (ApplianceType $t) => $t->value, ApplianceType::cases()), null]],
            'brand' => $text,
            'model' => $text,
            'problem' => $text,
            'date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD'],
            'arrival_now' => ['type' => 'boolean'],
            'arrival_start' => ['type' => ['string', 'null'], 'description' => 'HH:MM, 24-hour'],
            'arrival_end' => ['type' => ['string', 'null'], 'description' => 'HH:MM, 24-hour'],
            'notes' => $text,
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private function instructions(Company $company): string
    {
        $now = CarbonImmutable::now($company->timezone);
        $today = $now->format('Y-m-d (l)');
        $time = $now->format('H:i');
        $windows = implode(', ', array_map(fn (array $w) => "{$w['start']}-{$w['end']}", $company->arrivalWindows()));

        return <<<TEXT
            You fill in the booking form of a repair company (appliance repair, handyman) from a text the office typed, dictated
            or pasted (often an SMS from the customer). The text may be in Russian, English or both.

            Rules:
            - Write field values in English. Keep people's names, street names and city names exactly as written (do not translate
              or transliterate them).
            - Use null for anything that is not in the text. Never guess or invent a value.
            - phone: the customer's phone as written, with the country code if given (the company is in country {$company->country}).
            - address: the street address of the job (line1 = house number and street, without the unit). unit = apartment, suite
              or unit number. buzzer = buzzer, entry or gate code.
            - appliance_type: one of the listed values or null. brand and model as written (model = model number).
            - problem: a short description of what is wrong, in English.
            - Dates: today is {$today}, the time now is {$time} (company time zone {$company->timezone}). Turn "today",
              "tomorrow", "on Monday", "next Friday" into a date (YYYY-MM-DD); a weekday means the next such day after today.
            - Arrival: the company's arrival windows are {$windows}. When the customer's wish fits one window ("morning",
              "after 1"), give that window's start and end; when they name other times, give those times (24-hour HH:MM).
              arrival_now = true only when they want someone right away / as soon as possible today.
            - notes: anything else useful for the visit (parking, pets, best time to call) in English; null when nothing.
            TEXT;
    }

    /**
     * Known keys only, trimmed strings, a phone in E.164, a real date and HH:MM times.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw, Company $company): array
    {
        $string = fn ($value, int $max = 255) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $phone = $string($raw['phone'] ?? null, 32);
        $email = $string($raw['email'] ?? null);
        $address = is_array($raw['address'] ?? null) && $string($raw['address']['line1'] ?? null) !== null ? [
            'line1' => $string($raw['address']['line1']),
            'city' => $string($raw['address']['city'] ?? null, 100),
            'region' => $string($raw['address']['region'] ?? null, 50),
            'postal_code' => $string($raw['address']['postal_code'] ?? null, 20),
        ] : null;
        $date = $string($raw['date'] ?? null, 10);
        $time = fn ($value) => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : null;
        $start = $time($raw['arrival_start'] ?? null);
        $end = $time($raw['arrival_end'] ?? null);

        if ($date !== null && (CarbonImmutable::canBeCreatedFromFormat($date, 'Y-m-d') === false || CarbonImmutable::createFromFormat('Y-m-d', $date)?->format('Y-m-d') !== $date)) {
            $date = null;
        }

        if ($start === null || $end === null || $end <= $start) {
            $start = $end = null;
        }

        return [
            'first_name' => $string($raw['first_name'] ?? null, 100),
            'last_name' => $string($raw['last_name'] ?? null, 100),
            'phone' => $phone !== null && PhoneNumber::isPossible($phone, $company->country) ? PhoneNumber::normalize($phone, $company->country) : null,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
            'address' => $address,
            'unit' => $string($raw['unit'] ?? null, 50),
            'buzzer' => $string($raw['buzzer'] ?? null, 100),
            'appliance_type' => ApplianceType::tryFrom((string) ($raw['appliance_type'] ?? ''))?->value,
            'brand' => $string($raw['brand'] ?? null, 100),
            'model' => $string($raw['model'] ?? null, 100),
            'problem' => $string($raw['problem'] ?? null, 5000),
            'date' => $date,
            'arrival_now' => ($raw['arrival_now'] ?? false) === true,
            'arrival_start' => $start,
            'arrival_end' => $end,
            'notes' => $string($raw['notes'] ?? null, 5000),
        ];
    }
}
