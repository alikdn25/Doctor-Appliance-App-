<?php

use App\Console\Commands\FixStoragePermissions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Permission bits of a path, e.g. 0775. */
function mode(string $path): int
{
    clearstatcache(true, $path);

    return fileperms($path) & 07777;
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/private-disk-'.uniqid();
    File::ensureDirectoryExists($this->root);
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('sets folders 0775 and files 0664 on the private disk', function () {
    expect(config('filesystems.disks.local.permissions'))->toBe([
        'file' => ['public' => 0664, 'private' => 0664],
        'dir' => ['public' => 0775, 'private' => 0775],
    ]);
});

it('creates a new folder on the private disk with 0775 and its files with 0664', function () {
    $disk = Storage::build([...config('filesystems.disks.local'), 'root' => $this->root]);

    $disk->put('booking-photos/1/2/photo.jpg', 'image');
    $disk->makeDirectory('companies/1/jobs/3/photos');

    expect(mode($this->root.'/booking-photos'))->toBe(0775)
        ->and(mode($this->root.'/booking-photos/1/2'))->toBe(0775)
        ->and(mode($this->root.'/booking-photos/1/2/photo.jpg'))->toBe(0664)
        ->and(mode($this->root.'/companies/1/jobs/3/photos'))->toBe(0775);
});

it('fixes existing folders to 2775 and files to 0664 once', function () {
    $private = storage_path('app/private');
    $marker = storage_path('app/'.FixStoragePermissions::MARKER);
    File::delete($marker);
    $folder = $private.'/companies/test-'.uniqid();
    mkdir($folder.'/photos', 0700, true);
    chmod($folder, 0700);
    chmod($folder.'/photos', 0700);
    file_put_contents($folder.'/photos/a.jpg', 'image');
    chmod($folder.'/photos/a.jpg', 0600);

    try {
        $this->artisan('app:fix-storage-permissions')->assertSuccessful();

        expect(mode($folder))->toBe(02775)
            ->and(mode($folder.'/photos'))->toBe(02775)
            ->and(mode($folder.'/photos/a.jpg'))->toBe(0664)
            ->and(file_exists($marker))->toBeTrue();

        // Once: a later run changes nothing; --force runs again.
        chmod($folder.'/photos/a.jpg', 0600);
        $this->artisan('app:fix-storage-permissions')->expectsOutputToContain('already fixed')->assertSuccessful();
        expect(mode($folder.'/photos/a.jpg'))->toBe(0600);

        $this->artisan('app:fix-storage-permissions --force')->assertSuccessful();
        expect(mode($folder.'/photos/a.jpg'))->toBe(0664);
    } finally {
        File::deleteDirectory($folder);
        File::delete($marker);
    }
});

it('runs on every deploy', function () {
    expect(file_get_contents(base_path('deploy/deploy.sh')))->toContain('php artisan app:fix-storage-permissions');
});
