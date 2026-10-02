<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private media (job photos, signatures, rating plates). Files have no public URL;
 * controllers stream them after checking access.
 */
class PrivateMedia
{
    public static function diskName(): string
    {
        return (string) config('fieldservice.private_media_disk');
    }

    public static function disk(): Filesystem&FilesystemAdapter
    {
        /** @var Filesystem&FilesystemAdapter */
        return Storage::disk(self::diskName());
    }

    public static function response(string $path): StreamedResponse
    {
        $disk = self::disk();
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
