<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Repairs the folders and files made in storage/app/private before the private disk had its permissions set (folders
 * were made 0700, so the web server and the queue worker could not write into each other's photo folders): folders
 * 2775 (new files keep the group), files 0664. Runs once (a marker file records it); --force runs it again. A path
 * that cannot be changed (another owner) is counted and skipped, never stops the deploy.
 */
#[Signature('app:fix-storage-permissions {--force : Run again even when it already ran}')]
#[Description('Set folders in storage/app/private to 2775 and files to 0664 (once)')]
class FixStoragePermissions extends Command
{
    public const MARKER = 'storage-permissions-fixed';

    public function handle(): int
    {
        $root = storage_path('app/private');
        $marker = storage_path('app/'.self::MARKER);

        if (! $this->option('force') && is_file($marker)) {
            $this->info('Storage permissions were already fixed.');

            return self::SUCCESS;
        }

        if (! is_dir($root)) {
            $this->info('No storage/app/private yet.');

            return self::SUCCESS;
        }

        [$dirs, $files, $failed] = [0, 0, 0];
        $set = function (SplFileInfo $item) use (&$dirs, &$files, &$failed): void {
            if ($item->isLink()) {
                return;
            }
            $dir = $item->isDir();

            if (@chmod($item->getPathname(), $dir ? 02775 : 0664)) {
                $dir ? $dirs++ : $files++;
            } else {
                $failed++;
            }
        };

        $set(new SplFileInfo($root));
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $set($item);
        }

        file_put_contents($marker, now()->toIso8601String()."\n");
        $this->info("Folders: {$dirs}, files: {$files}, not changed: {$failed}.");

        return self::SUCCESS;
    }
}
