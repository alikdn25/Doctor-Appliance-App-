<?php

namespace App\Support\Ai;

use App\Enums\ApplianceType;
use App\Models\Company;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * "Paste from message": any text (an SMS, an email, a note from a call) split into the booking form's fields —
 * customer (who pays), occupant, phones, address, appliance and the problem. The company's AI provider reads it;
 * without one (or when it fails) a basic parser picks out what it can. The person checks the form and saves it.
 */
class BookingTextParser
{
    /** Words that mark the occupant (someone else living at the job address). */
    private const OCCUPANT_WORDS = '/\b(tenant|occupant|resident|renter|lives there|on site|onsite|homeowner|owner of the unit)\b/i';

    public function __construct(private readonly AiProvider $ai) {}

    /**
     * @return array{source: string, fields: array<string, mixed>}
     */
    public function parse(string $text, Company $company): array
    {
        $text = trim($text);
        $extractor = $this->ai->for($company);

        if ($extractor !== null) {
            try {
                return ['source' => 'ai', 'fields' => $this->normalize($extractor->extract($this->instructions($company), $text, self::schema()), $company)];
            } catch (AiUnavailable $e) {
                Log::warning('AI booking parser unavailable, using the basic parser', ['company' => $company->id, 'error' => $e->getMessage()]);
            }
        }

        return ['source' => 'basic', 'fields' => $this->normalize($this->basic($text), $company)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => ['string', 'null']];
        $address = [
            'type' => ['object', 'null'],
            'properties' => [
                'line1' => $text, 'unit' => $text, 'city' => $text, 'region' => $text, 'postal_code' => $text,
            ],
            'required' => ['line1', 'unit', 'city', 'region', 'postal_code'],
            'additionalProperties' => false,
        ];
        $properties = [
            'first_name' => $text,
            'last_name' => $text,
            'company_name' => $text,
            'phone' => $text,
            'email' => $text,
            'job_address' => $address,
            'billing_address' => $address,
            'occupant_name' => $text,
            'occupant_phone' => $text,
            'access_notes' => $text,
            'appliance_type' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn (ApplianceType $t) => $t->value, ApplianceType::cases()), null]],
            'appliance_brand' => $text,
            'problem' => $text,
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
        return <<<TEXT
            You fill in a booking form of an appliance repair company from a message the office pasted (an SMS, an email or call notes).
            The customer is the person or company who pays (for example a landlord or property manager). The occupant is someone else
            who lives at the job address (a tenant); leave occupant fields null when nobody else is mentioned. The job address is where
            the work is done; billing_address only when the payer's own address is given and differs from it. Phone numbers as written,
            with the country code when known (the company is in country {$company->country}). appliance_type is one of the listed
            values or null. problem is a short description of what is wrong, in the message's words. Use null for anything not in the
            message; never invent details.
            TEXT;
    }

    /**
     * What a basic reading finds: emails, phones, a street address, an appliance and the rest as the problem.
     *
     * @return array<string, mixed>
     */
    private function basic(string $text): array
    {
        $fields = array_fill_keys(array_keys(self::schema()['properties']), null);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn ($l) => $l !== ''));
        $used = [];

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            $fields['email'] = $m[0];
        }

        // Phones: the first is the customer's; one on a line about the tenant is the occupant's.
        foreach ($lines as $i => $line) {
            if (! preg_match_all('/\+?\d[\d\s().-]{7,}\d/', $line, $matches)) {
                continue;
            }
            foreach ($matches[0] as $number) {
                if (! PhoneNumber::isPossible($number)) {
                    continue;
                }
                if (preg_match(self::OCCUPANT_WORDS, $line) && $fields['occupant_phone'] === null) {
                    $fields['occupant_phone'] = $number;
                    $name = trim((string) preg_replace(['/\+?\d[\d\s().-]{7,}\d/', self::OCCUPANT_WORDS, '/[:,\-–]|\b(is|name|phone|cell|tel)\b/i'], ' ', $line));
                    $fields['occupant_name'] = $name !== '' && ! preg_match('/\d/', $name) ? (string) preg_replace('/\s+/', ' ', $name) : null;
                    $used[$i] = true;
                } elseif ($fields['phone'] === null) {
                    $fields['phone'] = $number;
                    $used[$i] = $used[$i] ?? $this->onlyContact($line);
                }
            }
        }

        // The address: a line starting with a house number and a street word, or one labelled "Address".
        foreach ($lines as $i => $line) {
            $value = preg_replace('/^(job\s+)?address\s*[:\-]\s*/i', '', $line, 1, $labelled);
            if ($labelled || preg_match('/^\d+[A-Z]?\s+\D+\b(st|street|ave|avenue|rd|road|dr|drive|blvd|boulevard|way|cres|crescent|pl|place|ct|court|lane|ln|hwy|highway|terrace|trail|close|gate|row)\b/i', $line)) {
                $parts = array_map('trim', explode(',', (string) $value));
                $fields['job_address'] = [
                    'line1' => $parts[0],
                    'unit' => null,
                    'city' => $parts[1] ?? null,
                    'region' => null,
                    'postal_code' => null,
                ];
                if (isset($parts[2]) && preg_match('/^([A-Za-z]{2})\s*(.*)$/', $parts[2], $rest)) {
                    $fields['job_address']['region'] = strtoupper($rest[1]);
                    $fields['job_address']['postal_code'] = trim($rest[2]) ?: null;
                }
                $used[$i] = true;
                break;
            }
        }

        // The name: "Name: …", or a short first line of words only.
        foreach ($lines as $i => $line) {
            if (preg_match('/^(customer|name|client)\s*[:\-]\s*(.+)$/i', $line, $m) || ($i === 0 && preg_match('/^(\p{Lu}[\p{L}\'.-]+)(\s+\p{Lu}[\p{L}\'.-]+){0,2}$/u', $line) && ! isset($used[$i]))) {
                $name = trim($m[2] ?? $line);
                $name = trim((string) preg_replace('/\+?\d[\d\s().-]{7,}\d|[A-Z0-9._%+-]+@\S+/i', '', $name), ' ,-');
                $parts = preg_split('/\s+/u', $name, 2) ?: [];
                $fields['first_name'] = $parts[0] ?? null;
                $fields['last_name'] = $parts[1] ?? null;
                $used[$i] = true;
                break;
            }
        }

        $lower = mb_strtolower($text);
        foreach ([
            'washer_dryer_combo' => '/washer[\s\/-]*dryer combo|combo washer/',
            'dishwasher' => '/dish\s?washer/',
            'refrigerator' => '/fridge|refrigerator/',
            'wine_cooler' => '/wine (cooler|fridge)/',
            'freezer' => '/freezer/',
            'washer' => '/washer|washing machine/',
            'dryer' => '/dryer/',
            'range_hood' => '/range hood|hood fan|exhaust hood/',
            'microwave' => '/microwave/',
            'cooktop' => '/cooktop|stovetop|hob/',
            'oven' => '/wall oven|oven/',
            'range' => '/range|stove/',
        ] as $type => $pattern) {
            if (preg_match($pattern, $lower)) {
                $fields['appliance_type'] = $type;
                break;
            }
        }

        $rest = array_values(array_filter($lines, fn ($line, $i) => ! isset($used[$i]) || ! $used[$i], ARRAY_FILTER_USE_BOTH));
        $problem = trim(implode("\n", array_map(fn ($l) => trim((string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '', $l)), $rest)));
        $fields['problem'] = $problem !== '' ? $problem : null;

        return $fields;
    }

    /** A line that holds nothing but a phone (and maybe a label) is used up by it. */
    private function onlyContact(string $line): bool
    {
        return trim((string) preg_replace(['/\+?\d[\d\s().-]{7,}\d/', '/\b(phone|cell|mobile|tel|number|call)\b|[:#]/i'], '', $line)) === '';
    }

    /**
     * Cleans the answer: known keys only, trimmed strings, phones in E.164, an appliance type the app knows.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw, Company $company): array
    {
        $string = fn ($value, int $max = 255) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $phone = fn ($value) => ($v = $string($value, 32)) !== null && PhoneNumber::isPossible($v, $company->country) ? PhoneNumber::normalize($v, $company->country) : null;
        $address = function ($value) use ($string) {
            if (! is_array($value) || $string($value['line1'] ?? null) === null) {
                return null;
            }

            return [
                'line1' => $string($value['line1']),
                'unit' => $string($value['unit'] ?? null, 50),
                'city' => $string($value['city'] ?? null, 100),
                'region' => $string($value['region'] ?? null, 50),
                'postal_code' => $string($value['postal_code'] ?? null, 20),
            ];
        };
        $email = $string($raw['email'] ?? null);

        return [
            'first_name' => $string($raw['first_name'] ?? null, 100),
            'last_name' => $string($raw['last_name'] ?? null, 100),
            'company_name' => $string($raw['company_name'] ?? null),
            'phone' => $phone($raw['phone'] ?? null),
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null,
            'job_address' => $address($raw['job_address'] ?? null),
            'billing_address' => $address($raw['billing_address'] ?? null),
            'occupant_name' => $string($raw['occupant_name'] ?? null),
            'occupant_phone' => $phone($raw['occupant_phone'] ?? null),
            'access_notes' => $string($raw['access_notes'] ?? null, 2000),
            'appliance_type' => ApplianceType::tryFrom((string) ($raw['appliance_type'] ?? ''))?->value,
            'appliance_brand' => $string($raw['appliance_brand'] ?? null, 100),
            'problem' => $string($raw['problem'] ?? null, 5000),
        ];
    }
}
