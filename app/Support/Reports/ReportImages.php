<?php

namespace App\Support\Reports;

/**
 * Photos of reports (owner's task of November 25): an upload becomes a JPEG with the long side at most 1600 px, turned
 * the way the camera held it (EXIF orientation); a PDF gets smaller copies so the file stays under 8 MB.
 * Formats GD cannot read (e.g. HEIC) are kept as they came.
 */
class ReportImages
{
    public const MAX_SIDE = 1600;

    /** JPEG bytes no larger than $maxSide, or null when the bytes are not an image GD can read. */
    public static function jpeg(string $bytes, int $maxSide = self::MAX_SIDE, int $quality = 82): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }

        $image = self::orient($image, $bytes);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height, 1));

        if ($scale < 1) {
            $resized = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
            imagedestroy($image);
            $image = $resized;
        } else {
            // A PNG with transparency gets a white background in the JPEG.
            $flat = imagecreatetruecolor($width, $height);
            imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $flat;
        }

        ob_start();
        imagejpeg($image, null, $quality);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** A data URI for the PDF, or null when the photo cannot be read. */
    public static function dataUri(string $bytes, int $maxSide, int $quality): ?string
    {
        $jpeg = self::jpeg($bytes, $maxSide, $quality);

        return $jpeg === null ? null : 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    private static function orient(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data') || ! str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
