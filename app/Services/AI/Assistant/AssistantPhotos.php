<?php

namespace App\Services\AI\Assistant;

use App\Models\AssistantMessage;
use App\Support\PrivateMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Photos and screenshots attached to an assistant question (up to 5, shrunk on the phone to 1600 px). They are kept
 * as private files; the chat stores only their paths (block type "image" with "path") and the transport sends them
 * to the AI as images.
 */
class AssistantPhotos
{
    public const MAX = 5;

    public const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Stores the uploads and returns their content blocks.
     *
     * @param  list<UploadedFile>  $files
     * @return list<array{type: 'image', path: string, media_type: string}>
     */
    public static function store(array $files, int $companyId, int $userId): array
    {
        $blocks = [];

        foreach (array_slice($files, 0, self::MAX) as $file) {
            $type = in_array($file->getMimeType(), self::MEDIA_TYPES, true) ? (string) $file->getMimeType() : 'image/jpeg';
            $path = "assistant-photos/{$companyId}/{$userId}/".Str::uuid().'.'.self::extension($type);
            PrivateMedia::disk()->put($path, (string) file_get_contents($file->getRealPath()));
            $blocks[] = ['type' => 'image', 'path' => $path, 'media_type' => $type];
        }

        return $blocks;
    }

    public static function extension(string $mediaType): string
    {
        return match ($mediaType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    /**
     * A stored image block as the Messages API wants it (base64, SDK shape: mediaType). A file that is gone becomes a
     * short text.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public static function toApi(array $block): array
    {
        $disk = PrivateMedia::disk();
        $path = (string) ($block['path'] ?? '');

        if ($path === '' || ! $disk->exists($path)) {
            return ['type' => 'text', 'text' => '[A photo that is no longer available]'];
        }

        return [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => (string) ($block['media_type'] ?? 'image/jpeg'), 'data' => base64_encode((string) $disk->get($path))],
        ];
    }

    /**
     * Photos of a message (its image blocks).
     *
     * @return list<array{path: string, media_type: string}>
     */
    public static function of(AssistantMessage $message): array
    {
        return array_values(array_map(
            fn (array $block) => ['path' => (string) $block['path'], 'media_type' => (string) ($block['media_type'] ?? 'image/jpeg')],
            array_filter((array) $message->content, fn ($block) => is_array($block) && ($block['type'] ?? null) === 'image' && isset($block['path'])),
        ));
    }

    /**
     * The photos of the latest question with photos in the conversation (what an invoice is read from).
     *
     * @return list<array{path: string, media_type: string}>
     */
    public static function latest(string $conversation): array
    {
        $messages = AssistantMessage::query()->where('conversation', $conversation)->where('role', 'user')->orderByDesc('id')->limit(20)->get();

        foreach ($messages as $message) {
            if (($photos = self::of($message)) !== []) {
                return $photos;
            }
        }

        return [];
    }
}
