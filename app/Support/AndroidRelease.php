<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The APK on the server and its version (owner's task of November 22). GitHub Actions copies android.apk and
 * android.json ({"version_code": 42, "version_name": "1.0.42", "built_at": "…", "commit": "…"}) next to each other
 * (config payments.android_apk_path). An APK without the JSON has no known version.
 */
class AndroidRelease
{
    /**
     * @return array{version_code: int, version_name: string|null, built_at: CarbonImmutable|null, commit: string|null, size: int, url: string}|null
     */
    public static function current(): ?array
    {
        $apk = (string) config('payments.android_apk_path');

        if (! is_file($apk)) {
            return null;
        }

        $meta = [];
        $json = dirname($apk).'/android.json';
        if (is_file($json)) {
            $decoded = json_decode((string) file_get_contents($json), true);
            $meta = is_array($decoded) ? $decoded : [];
        }

        $builtAt = null;
        try {
            $builtAt = is_string($meta['built_at'] ?? null) ? CarbonImmutable::parse($meta['built_at']) : CarbonImmutable::createFromTimestamp((int) filemtime($apk));
        } catch (Throwable) {
            $builtAt = null;
        }

        return [
            'version_code' => (int) ($meta['version_code'] ?? 0),
            'version_name' => is_scalar($meta['version_name'] ?? null) ? (string) $meta['version_name'] : null,
            'built_at' => $builtAt,
            'commit' => is_string($meta['commit'] ?? null) ? substr($meta['commit'], 0, 12) : null,
            'size' => (int) filesize($apk),
            'url' => route('download.android', ['v' => (int) ($meta['version_code'] ?? 0)]),
        ];
    }
}
