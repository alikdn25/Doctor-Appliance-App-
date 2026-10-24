<?php

namespace App\Support;

/**
 * The version of the running app, for "New version available" (owner's task, October 24). It changes with every
 * deploy: the deploy script writes the commit to storage/app/release, and the built assets change with the code.
 */
final class AppVersion
{
    public static function releaseFile(): string
    {
        return storage_path('app/release');
    }

    public static function current(): string
    {
        $release = is_file(self::releaseFile()) ? trim((string) file_get_contents(self::releaseFile())) : '';
        $manifest = public_path('build/manifest.json');
        $assets = is_file($manifest) ? (string) md5_file($manifest) : '';

        return substr(sha1($release.'|'.$assets), 0, 12);
    }
}
