<?php

namespace App\Support\Reviews;

use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The optional picture of the Google review block in invoice emails. Any upload is cropped to a square and saved as
 * a 220×220 PNG with transparency (shown at 110×110, sharp on high-density screens), at most 40 KB: when the full
 * colour file is larger, it is reduced to a 256-colour palette with a transparent background. Each upload gets a new
 * file name, so mail clients that cache images by URL show the new picture.
 */
class ReviewImage
{
    public const SIZE = 220;

    public const MAX_BYTES = 40 * 1024;

    /**
     * Replaces the company's picture with the image at $path (PNG, JPEG, WebP or GIF).
     */
    public static function store(Company $company, string $path): void
    {
        $png = self::process($path);
        $disk = Storage::disk(config('fieldservice.media_disk'));
        $file = "companies/{$company->id}/review-image-".Str::random(12).'.png';
        $disk->put($file, $png, 'public');

        self::delete($company);
        $company->forceFill(['review_image_path' => $file])->save();
    }

    public static function delete(Company $company): void
    {
        if ($company->review_image_path === null) {
            return;
        }

        Storage::disk(config('fieldservice.media_disk'))->delete($company->review_image_path);
        $company->forceFill(['review_image_path' => null])->save();
    }

    /**
     * Absolute URL of the picture (APP_URL), as an email needs it; null without a picture.
     */
    public static function url(Company $company): ?string
    {
        if ($company->review_image_path === null) {
            return null;
        }

        $url = Storage::disk(config('fieldservice.media_disk'))->url($company->review_image_path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }

    /**
     * The PNG bytes: centre-cropped square, 220×220, transparent background kept, at most MAX_BYTES.
     */
    public static function process(string $path): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($path));

        if ($source === false) {
            throw new RuntimeException('Not an image.');
        }

        $side = min(imagesx($source), imagesy($source));
        $target = self::canvas();
        imagecopyresampled(
            $target, $source, 0, 0,
            intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        $png = self::encode($target);

        if (strlen($png) > self::MAX_BYTES) {
            $png = self::encode(self::palette($target));
        }

        if (strlen($png) > self::MAX_BYTES) {
            throw new RuntimeException('The image is too detailed to fit in 40 KB.');
        }

        return $png;
    }

    /**
     * 255 colours plus one fully transparent entry. GD's quantizer drops the alpha channel, so pixels that are more
     * than half transparent in the full colour image are set to the transparent entry afterwards.
     */
    private static function palette(\GdImage $image): \GdImage
    {
        $palette = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopy($palette, $image, 0, 0, 0, 0, self::SIZE, self::SIZE);
        imagetruecolortopalette($palette, false, 255);
        $clear = imagecolorallocatealpha($palette, 0, 0, 0, 127);
        imagecolortransparent($palette, $clear);

        for ($y = 0; $y < self::SIZE; $y++) {
            for ($x = 0; $x < self::SIZE; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 63) {
                    imagesetpixel($palette, $x, $y, $clear);
                }
            }
        }

        return $palette;
    }

    private static function canvas(): \GdImage
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }

    private static function encode(\GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
