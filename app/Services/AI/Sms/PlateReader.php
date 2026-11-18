<?php

namespace App\Services\AI\Sms;

use App\Models\Company;
use App\Services\AI\AiFeature;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\Assistant\AssistantPhotos;
use App\Services\AI\QuickBookingParser;

/**
 * A photo from an incoming MMS, read by AI_MODEL_ASSISTANT: is it an appliance rating plate, and if so brand, model
 * and serial number. Nothing is changed here: attaching them to the customer's job waits for Confirm.
 */
class PlateReader
{
    public function __construct(private readonly AiService $ai) {}

    /**
     * @param  list<array{path: string, media_type: string}>  $photos
     * @return array{is_plate: bool, brand: string|null, model: string|null, serial: string|null}
     *
     * @throws AiUnavailable
     */
    public function read(array $photos, Company $company): array
    {
        $content = array_map(fn (array $photo) => AssistantPhotos::toApi(['type' => 'image', ...$photo]), array_slice($photos, 0, 3));
        $content[] = ['type' => 'text', 'text' => 'Is one of these photos the rating plate (data plate / nameplate) of an appliance? If so, read the brand, model number and serial number exactly as printed.'];

        $raw = $this->ai->send(
            AiFeature::SmsPhoto,
            $company,
            null,
            'You read appliance rating plates from photos customers text to a repair company. Copy the characters exactly; '
            .'use "" for anything you cannot read clearly. Never guess a number.',
            [['role' => 'user', 'content' => $content]],
            schema: self::schema(),
            maxTokens: 600,
        )->json();

        $text = fn ($value, int $max = 100) => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
        $serial = $text($raw['serial'] ?? null);

        return [
            'is_plate' => ($raw['is_rating_plate'] ?? false) === true,
            'brand' => $text($raw['brand'] ?? null),
            'model' => QuickBookingParser::modelNumber($text($raw['model'] ?? null)),
            'serial' => $serial === null ? null : (string) preg_replace('/\s+/u', '', mb_strtoupper($serial)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => ['is_rating_plate' => ['type' => 'boolean'], 'brand' => $text, 'model' => $text, 'serial' => $text],
            'required' => ['is_rating_plate', 'brand', 'model', 'serial'],
            'additionalProperties' => false,
        ];
    }
}
