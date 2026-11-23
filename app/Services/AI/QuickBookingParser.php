<?php

namespace App\Services\AI;

use App\Enums\ApplianceType;
use App\Models\Company;
use App\Models\User;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;

/**
 * Quick booking: a free text (dictated, typed or an SMS from the customer; Russian, English or mixed) and/or up to 5
 * photos (a screenshot of the customer's message, a rating plate) read by the AI
 * (AI_MODEL_BOOKING) into the booking form's fields. Every value comes back in English: names in Cyrillic are written in
 * Latin letters, numbers said in words become digits, letters of a model or serial number said aloud become Latin
 * letters; "today", "tomorrow", "on Monday" count in the company's time zone; phones in E.164. Anything not in the
 * text stays empty. uncertain_fields lists the fields the AI is not sure about (marked "Check" in the form). Nothing is
 * saved here: the person checks the form and presses Save.
 */
class QuickBookingParser
{
    /** Field names the AI may list in uncertain_fields. */
    public const FIELDS = [
        'first_name', 'last_name', 'phone', 'email', 'address', 'unit', 'buzzer', 'appliance_type', 'brand', 'model',
        'serial_number', 'problem', 'date', 'arrival', 'notes',
    ];

    public function __construct(private readonly AiService $ai) {}

    /**
     * @param  list<array{path: string, media_type: string}>  $photos  Stored photos or screenshots to read as well.
     * @return array<string, mixed>
     *
     * @throws AiUnavailable
     */
    public function parse(string $text, Company $company, User $user, array $photos = []): array
    {
        $text = trim($text);
        $content = $photos === [] ? $text : [
            ...array_map(fn (array $photo) => ['type' => 'image', 'path' => $photo['path'], 'media_type' => $photo['media_type']], array_slice($photos, 0, 5)),
            ['type' => 'text', 'text' => '[Photos attached: '.min(count($photos), 5)."]\n".($text === '' ? '(No text: read the booking from the photos.)' : $text)],
        ];

        $raw = $this->ai->send(
            AiFeature::QuickBooking,
            $company,
            $user,
            $this->instructions($company),
            [['role' => 'user', 'content' => $content]],
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
        // Anthropic structured outputs: no node may combine "enum" with an array "type", so every field is a plain
        // string ("" when missing, "unknown" for an appliance type that is not stated); normalize() maps both to null.
        $text = ['type' => 'string'];
        $properties = [
            'first_name' => $text,
            'last_name' => $text,
            'phone' => $text,
            'email' => $text,
            'address' => [
                'type' => 'object',
                'properties' => ['line1' => $text, 'city' => $text, 'region' => $text, 'postal_code' => $text],
                'required' => ['line1', 'city', 'region', 'postal_code'],
                'additionalProperties' => false,
            ],
            'unit' => $text,
            'buzzer' => $text,
            'appliance_type' => ['type' => 'string', 'enum' => [...array_map(fn (ApplianceType $t) => $t->value, ApplianceType::cases()), 'unknown']],
            'brand' => $text,
            'model' => $text,
            'serial_number' => $text,
            'problem' => $text,
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or "" when not given'],
            'arrival_now' => ['type' => 'boolean'],
            'arrival_start' => ['type' => 'string', 'description' => 'HH:MM, 24-hour, or "" when not given'],
            'arrival_end' => ['type' => 'string', 'description' => 'HH:MM, 24-hour, or "" when not given'],
            'notes' => $text,
            'uncertain_fields' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => self::FIELDS],
                'description' => 'Fields whose value you are not sure about; [] when sure of all',
            ],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * A model number only when it looks like one: one word of Latin capitals and digits (dashes and slashes allowed),
     * at least 3 characters with a digit in it ("yg 502 s" -> "YG502S"). Anything else ("YG.Y", "the old one") is
     * dropped rather than put in the form.
     */
    public static function modelNumber(?string $value): ?string
    {
        $model = preg_replace('/\s+/u', '', mb_strtoupper((string) $value)) ?? '';

        return preg_match('/^(?=.*\d)[A-Z0-9][A-Z0-9\/-]{2,39}$/', $model) === 1 ? $model : null;
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
            - Write every field value in English, whatever the language of the text (Russian, English or mixed).
            - Names written in Cyrillic: write them in Latin letters the way the name is spelled in English
              ("бобби чан" -> first_name "Bobby", last_name "Chan"; "анна ким" -> "Anna" "Kim"). Capitalize names. Street and
              city names in Cyrillic are written in Latin letters the same way ("мейн стрит" -> "Main St").
            - Numbers said in words become digits, also when words and digits are mixed ("двести 30 шесть" -> "236",
              "шесть семь восемь" -> "678", "ноль" -> "0").
            - Use "" (an empty string) for anything that is not in the text. Never guess or invent a value.
            - Ignore filler and command words addressed to you, such as "смотри", "значит", "так", "запиши", "забронируй",
              "пиши на английском", "только на английском", "okay", "book": they never go into any field.
            - phone: the customer's phone as digits only (no spaces or dashes), with the country code if given (the company is
              in country {$company->country}); the app formats it.
            - address: the street address of the job (line1 = house number and street, without the unit). unit = apartment, suite
              or unit number. buzzer = buzzer, entry or gate code. When there is no address, all address fields are "".
            - appliance_type: one of the listed values. Russian words: духовка/духовой шкаф -> oven, плита -> range,
              холодильник -> refrigerator, посудомойка/посудомоечная машина -> dishwasher, стиралка/стиральная машина -> washer,
              сушилка/сушильная машина -> dryer, варочная панель -> cooktop, микроволновка/микроволновая печь -> microwave,
              морозилка/морозильник -> freezer, вытяжка -> range_hood, винный холодильник -> wine_cooler. If the appliance is
              not stated, return "unknown".
            - brand: the manufacturer in its usual English spelling ("элджи" -> "LG", "самсунг" -> "Samsung", "вирпул" -> "Whirlpool").
            - model: only when the text clearly says a model number; "" otherwise (never a guess or a fragment).
              Model and serial numbers: letters said aloud in Russian or English become Latin letters:
              эй/а -> A, би/бэ -> B, си/цэ -> C, ди/дэ -> D, и (as a letter) -> E, эф -> F, джи/гэ/жэ -> G, эйч/аш/ха -> H,
              ай -> I, джей/йот -> J, кей/ка -> K, эл -> L, эм -> M, эн -> N, оу/о -> O, пи/пэ -> P, кью/ку -> Q, ар/эр -> R,
              эс -> S, ти/тэ -> T, ю/у -> U, ви/вэ -> V, дабл ю/дабл в -> W, экс/икс -> X, уай/игрек -> Y, зед/зи/зэт -> Z;
              digits said in words become digits (ноль -> 0). Write the number as one word in capital letters, no spaces
              ("уай джи пять ноль два эс" -> "YG502S").
            - serial_number: only when the text or a photo clearly shows the serial number (S/N), as one word in capitals;
              "" otherwise.
            - problem: a short description of what is wrong, in English ("нужно отремонтировать духовку" -> "Oven repair").
            - Dates: today is {$today}, the time now is {$time} (company time zone {$company->timezone}). Turn "today",
              "tomorrow", "on Monday", "next Friday" into a date (YYYY-MM-DD); a weekday means the next such day after today.
            - Arrival: the company's arrival windows are {$windows}. When the customer's wish fits one window ("morning",
              "after 1"), give that window's start and end; when they name other times, give those times (24-hour HH:MM).
              arrival_now = true only when they want someone right away / as soon as possible today.
            - notes: anything else useful for the visit (parking, pets, best time to call) in English; "" when nothing.
            - uncertain_fields: the names of the fields whose value you are not sure about (an unclear name spelling, a phone
              with a wrong number of digits, a letter of the model number that could be heard two ways, a guessed appliance
              type). "arrival" stands for arrival_now, arrival_start and arrival_end. [] when you are sure of every value.

            Photos and screenshots: the message may come with photos (a screenshot of the customer's SMS, email or web form, a
            note, an appliance rating plate). Read the customer's first and last name, phone, email, address, appliance, brand,
            model, serial number, problem, date and time from them and from the text together. Take only what you can
            actually read: a value that is cut off, blurred or unreadable (e.g. a phone with a digit you cannot read) is ""
            and never completed or guessed. Ignore the phone's own status bar, the app's buttons and the sender's company
            signature; the customer is the person asking for the repair. Without photos, use the text only.

            Example.
            Text: "Смотри значит забронируй бобби чан только на английском пиши бобби чан телефон двести 30 шесть семь восемь
            восемь пять пять восемь восемь, нужно отремонтировать духовку, номер модели уай джи пять ноль два эс"
            Result: first_name "Bobby", last_name "Chan", phone "2367885588", appliance_type "oven", problem "Oven repair",
            model "YG502S", every other text field "", arrival_now false, uncertain_fields [].
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
        $model = $string($raw['model'] ?? null, 100);
        $serial = (string) preg_replace('/\s+/u', '', mb_strtoupper((string) $string($raw['serial_number'] ?? null, 100)));
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
            // A model number is one word in capitals ("yg 502 s" -> "YG502S").
            'model' => self::modelNumber($model),
            'serial_number' => $serial === '' ? null : $serial,
            'problem' => $string($raw['problem'] ?? null, 5000),
            'date' => $date,
            'arrival_now' => ($raw['arrival_now'] ?? false) === true,
            'arrival_start' => $start,
            'arrival_end' => $end,
            'notes' => $string($raw['notes'] ?? null, 5000),
            'uncertain_fields' => is_array($raw['uncertain_fields'] ?? null)
                ? array_values(array_unique(array_intersect(array_filter($raw['uncertain_fields'], 'is_string'), self::FIELDS)))
                : [],
        ];
    }
}
