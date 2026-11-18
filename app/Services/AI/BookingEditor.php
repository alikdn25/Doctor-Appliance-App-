<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\User;
use App\Support\Locale\RelativeDate;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * "Quick edit" of a booking (owner's task of November 18): the booking as it is now and what the person said or typed
 * ("move to Thursday after 1, it's Bob not Rob") go to AI_MODEL_BOOKING; only the fields named in the text come back.
 * Nothing is saved: the edit form is filled and highlighted, and the person presses Save.
 */
class BookingEditor
{
    public const FIELDS = ['date', 'arrival', 'technician', 'first_name', 'last_name', 'phone', 'email', 'address', 'problem', 'notes'];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @param  array<string, mixed>  $current  date, start_time, end_time, technician, first_name, last_name, phone, email, address, problem, notes
     * @param  list<array{id: int, name: string}>  $technicians
     * @return array<string, mixed> Only the changed fields (form keys); [] when nothing was understood.
     *
     * @throws AiUnavailable
     */
    public function edit(string $text, array $current, array $technicians, Company $company, User $user): array
    {
        $raw = $this->ai->send(
            AiFeature::BookingEdit,
            $company,
            $user,
            $this->instructions($company, $technicians),
            [['role' => 'user', 'content' => 'Booking now: '.json_encode($current, JSON_UNESCAPED_UNICODE)."\n\nChange: ".trim($text)]],
            schema: self::schema(),
            maxTokens: 1000,
            effort: 'low',
        )->json();

        return self::normalize($raw, $technicians, $company);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $properties = [
            'changed_fields' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::FIELDS]],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or the words said ("tomorrow", "thursday")'],
            'arrival_start' => ['type' => 'string', 'description' => 'HH:MM'],
            'arrival_end' => ['type' => 'string', 'description' => 'HH:MM'],
            'arrival_now' => ['type' => 'boolean'],
            'technician' => $text,
            'first_name' => $text,
            'last_name' => $text,
            'phone' => $text,
            'email' => $text,
            'address' => $text,
            'problem' => $text,
            'notes' => $text,
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    /**
     * @param  list<array{id: int, name: string}>  $technicians
     */
    private function instructions(Company $company, array $technicians): string
    {
        $today = CarbonImmutable::now($company->timezone)->format('Y-m-d (l)');
        $windows = implode(', ', array_map(fn (array $w) => "{$w['start']}-{$w['end']}", $company->arrivalWindows()));
        $people = implode(', ', array_map(fn (array $t) => $t['name'], $technicians)) ?: '(none)';

        return <<<TEXT
            You change an existing booking of a repair company by what the office person says (Russian, English or mixed).
            Return ONLY what they ask to change, in changed_fields; every other field is "" (or false) and is kept as it is.
            - Values in English; names in Cyrillic written in Latin letters; numbers said in words become digits.
            - date: YYYY-MM-DD when sure, otherwise the words said ("tomorrow", "thursday"). Today is {$today}
              (time zone {$company->timezone}).
            - arrival: "arrival" in changed_fields with arrival_start/arrival_end (HH:MM, 24 h) for a new time or window;
              the company's windows are {$windows}; arrival_now = true for "now / right away".
            - technician: one of these names exactly: {$people}.
            - address: the full new street address as said.
            - problem: appliance and what is wrong ("Dryer not heating").
            - notes: the full new note text.
            If you cannot tell what to change, return changed_fields [].
            TEXT;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<array{id: int, name: string}>  $technicians
     * @return array<string, mixed>
     */
    private static function normalize(array $raw, array $technicians, Company $company): array
    {
        $fields = array_values(array_intersect(self::FIELDS, array_filter((array) ($raw['changed_fields'] ?? []), 'is_string')));
        $string = fn (string $key, int $max = 255) => is_string($raw[$key] ?? null) && trim($raw[$key]) !== '' ? mb_substr(trim($raw[$key]), 0, $max) : null;
        $time = fn (string $key) => is_string($raw[$key] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $raw[$key]) === 1 ? $raw[$key] : null;
        $changes = [];

        foreach ($fields as $field) {
            switch ($field) {
                case 'date':
                    if (($date = RelativeDate::resolve($string('date', 40), $company)) !== null) {
                        $changes['date'] = $date;
                    }
                    break;
                case 'arrival':
                    if (($raw['arrival_now'] ?? false) === true) {
                        $now = CarbonImmutable::now($company->timezone);
                        $start = $now->setTime($now->hour, intdiv($now->minute, 15) * 15)->addMinutes(15);
                        $changes += ['date' => $now->toDateString(), 'start_time' => $start->format('H:i'), 'end_time' => $start->addHours(2)->format('H:i'), 'now' => true];
                    } elseif (($start = $time('arrival_start')) !== null) {
                        $end = $time('arrival_end');
                        $changes['start_time'] = $start;
                        $changes['end_time'] = $end !== null && $end > $start ? $end : CarbonImmutable::createFromFormat('H:i', $start)->addHours(2)->format('H:i');
                    }
                    break;
                case 'technician':
                    $name = mb_strtolower((string) $string('technician'));
                    $match = collect($technicians)->first(fn (array $t) => mb_strtolower($t['name']) === $name)
                        ?? collect($technicians)->first(fn (array $t) => $name !== '' && str_contains(mb_strtolower($t['name']), $name));
                    if ($match !== null) {
                        $changes['technician_id'] = (string) $match['id'];
                    }
                    break;
                case 'phone':
                    $phone = $string('phone', 32);
                    if ($phone !== null && PhoneNumber::isPossible($phone, $company->country)) {
                        $changes['phone'] = PhoneNumber::normalize($phone, $company->country);
                    }
                    break;
                case 'email':
                    $email = $string('email');
                    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $changes['email'] = mb_strtolower($email);
                    }
                    break;
                default:
                    $value = $string($field, $field === 'problem' || $field === 'notes' ? 5000 : 255);
                    if ($value !== null) {
                        $changes[$field] = $value;
                    }
            }
        }

        return $changes;
    }
}
