<?php

use App\Enums\UserRole;
use App\Models\BackupRun;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\User;
use App\Support\Backups\BackupEncryptor;
use App\Support\Backups\BackupManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->root = storage_path('framework/testing/backups-'.Str::random(8));
    $this->files = storage_path('framework/testing/files-'.Str::random(8));
    File::ensureDirectoryExists("{$this->files}/private/companies/1");
    File::put("{$this->files}/private/companies/1/photo.jpg", 'JPEGDATA');

    config([
        'backup.encryption_key' => BackupEncryptor::generateKey(),
        'backup.disk' => 'backups',
        'filesystems.disks.backups' => ['driver' => 'local', 'root' => $this->root, 'throw' => true],
        'backup.file_roots' => ['private' => "{$this->files}/private"],
    ]);
    Storage::forgetDisk('backups');

    $this->admin = User::factory()->create(['is_super_admin' => true]);
});

afterEach(function () {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->files);
});

function seedCompany(string $name): array
{
    $company = Company::factory()->create(['name' => $name]);
    $owner = memberOf($company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $company->id]);
    $customer = Customer::factory()->for($company)->withPhone()->create(['first_name' => "{$name} One"]);
    $second = Customer::factory()->for($company)->create(['first_name' => "{$name} Two"]);
    $property = Property::factory()->for($customer)->create();
    $job = ServiceJob::factory()->for($property)->create(['brand_id' => $brand->id, 'description' => "{$name} washer"]);

    return compact('company', 'owner', 'customer', 'second', 'job');
}

test('an hourly backup is encrypted and stored as hourly, daily and monthly copies', function () {
    seedCompany('Alpha');

    $this->artisan('backup:run')->assertSuccessful();

    $backups = app(BackupManager::class)->list(BackupManager::DATABASE);
    expect(collect($backups)->pluck('class')->sort()->values()->all())->toBe(['daily', 'hourly', 'monthly']);

    $raw = Storage::disk('backups')->get($backups[0]['path']);
    expect(substr($raw, 0, 5))->toBe('FSBK1')
        ->and(str_contains($raw, 'PGDMP'))->toBeFalse()
        ->and(str_contains($raw, 'Alpha One'))->toBeFalse()
        ->and(BackupRun::query()->where('status', 'succeeded')->count())->toBe(1);

    // The next hour adds only an hourly copy.
    $this->travel(1)->hours();
    $this->artisan('backup:run')->assertSuccessful();
    expect(collect(app(BackupManager::class)->list(BackupManager::DATABASE))->countBy('class')->all())
        ->toBe(['hourly' => 2, 'daily' => 1, 'monthly' => 1]);
});

test('a backup restores into a test database with the same data', function () {
    seedCompany('Alpha');
    seedCompany('Beta');
    $this->artisan('backup:run')->assertSuccessful();

    $this->artisan('backup:restore')->assertSuccessful();

    $copy = DB::connection(app(BackupManager::class)->connectionTo('field_service_test_restore'));
    expect($copy->table('companies')->count())->toBe(2)
        ->and($copy->table('customers')->where('first_name', 'Beta Two')->exists())->toBeTrue()
        ->and($copy->table('service_jobs')->count())->toBe(2);
    $copy->disconnect();
});

test('restoring one company brings its data back and leaves the other company alone', function () {
    $a = seedCompany('Alpha');
    $b = seedCompany('Beta');
    $this->artisan('backup:run')->assertSuccessful();

    // After the backup: Alpha loses a customer for good and has one renamed; a new Alpha customer is added;
    // Beta changes too.
    DB::table('customer_phones')->where('customer_id', $a['second']->id)->delete();
    DB::table('customers')->where('id', $a['second']->id)->delete();
    DB::table('customers')->where('id', $a['customer']->id)->update(['first_name' => 'Wrong']);
    DB::table('service_jobs')->where('id', $a['job']->id)->update(['description' => 'Overwritten']);
    $newAlpha = Customer::factory()->for($a['company'])->create(['first_name' => 'Alpha New']);
    DB::table('customers')->where('id', $b['customer']->id)->update(['first_name' => 'Beta Changed']);
    $jobCounter = DB::table('companies')->where('id', $a['company']->id)->value('job_next_number');
    DB::table('companies')->where('id', $a['company']->id)->update(['job_next_number' => $jobCounter + 10]);

    $path = app(BackupManager::class)->list(BackupManager::DATABASE)[0]['path'];

    // A dry run changes nothing.
    $this->artisan('backup:restore-company', ['company' => $a['company']->id, 'path' => $path, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();
    expect(DB::table('customers')->where('id', $a['second']->id)->exists())->toBeFalse();

    // Without confirmation nothing changes either.
    $this->artisan('backup:restore-company', ['company' => $a['company']->id, 'path' => $path])
        ->expectsConfirmation('Restore these rows into the LIVE database?', 'no')
        ->assertSuccessful();
    expect(DB::table('customers')->where('id', $a['second']->id)->exists())->toBeFalse();

    $this->artisan('backup:restore-company', ['company' => $a['company']->id, 'path' => $path])
        ->expectsConfirmation('Restore these rows into the LIVE database?', 'yes')
        ->assertSuccessful();

    expect(DB::table('customers')->where('id', $a['second']->id)->value('first_name'))->toBe('Alpha Two')
        ->and(DB::table('customer_phones')->where('customer_id', $a['second']->id)->exists())->toBeFalse()
        ->and(DB::table('customers')->where('id', $a['customer']->id)->value('first_name'))->toBe('Alpha One')
        ->and(DB::table('service_jobs')->where('id', $a['job']->id)->value('description'))->toBe('Alpha washer')
        ->and(DB::table('customers')->where('id', $newAlpha->id)->exists())->toBeTrue()
        ->and(DB::table('customers')->where('id', $b['customer']->id)->value('first_name'))->toBe('Beta Changed')
        ->and(DB::table('companies')->where('id', $a['company']->id)->value('job_next_number'))->toBe($jobCounter + 10);

    // The live database was backed up before the restore.
    expect(collect(app(BackupManager::class)->list(BackupManager::DATABASE))->where('class', 'event')->count())->toBe(1);

    // New rows still get fresh ids.
    $another = Customer::factory()->for($a['company'])->create();
    expect($another->id)->toBeGreaterThan($newAlpha->id);
});

test('uploaded files are archived, encrypted and can be read back', function () {
    $this->artisan('backup:run', ['--files' => true, '--reason' => 'daily'])->assertSuccessful();

    $backup = app(BackupManager::class)->list(BackupManager::FILES)[0];
    $local = storage_path('framework/testing/files-restore-'.Str::random(6).'.tar.gz');
    app(BackupManager::class)->fetch($backup['path'], $local);

    $listing = Process::run(['tar', '-tzf', $local])->output();
    @unlink($local);

    expect($listing)->toContain('private/companies/1/photo.jpg');
});

test('a failed backup is recorded, alerts the platform owner and fails the command', function () {
    seedCompany('Alpha');
    config(['backup.binaries.pg_dump' => '/nonexistent/pg_dump']);

    $this->artisan('backup:run', ['--reason' => 'deploy'])->assertFailed();

    expect(BackupRun::query()->sole()->status)->toBe('failed')
        ->and($this->admin->notifications()->sole()->data['kind'])->toBe('backup_failed')
        ->and(Storage::disk('backups')->allFiles())->toBe([]);
});

test('expired backups are pruned: hourly after 48 hours, daily after 30 days, monthly after 12 months', function () {
    $disk = Storage::disk('backups');
    $now = CarbonImmutable::parse('2026-10-14 12:00', 'UTC');
    $put = fn (string $class, CarbonImmutable $at, string $kind = 'database') => $disk->put(
        "fieldservice/{$kind}/{$class}/".$at->format('Y/m/d').'/'.($kind === 'files' ? 'files-' : 'db-').$at->format('Ymd-His').($kind === 'files' ? '.tar.gz.enc' : "-{$class}.dump.enc"),
        'x',
    );

    $put('hourly', $now->subHours(47));
    $put('hourly', $now->subHours(49));
    $put('daily', $now->subDays(29));
    $put('daily', $now->subDays(31));
    $put('monthly', $now->subMonths(11));
    $put('monthly', $now->subMonths(13));
    $put('event', $now->subDays(31));
    $put('daily', $now->subDays(31), 'files');
    $put('daily', $now->subDays(2), 'files');

    $deleted = app(BackupManager::class)->prune($now);

    expect($deleted)->toHaveCount(5)
        ->and(collect(app(BackupManager::class)->list())->countBy(fn ($i) => "{$i['kind']}.{$i['class']}")->sortKeys()->all())
        ->toBe(['database.daily' => 1, 'database.hourly' => 1, 'database.monthly' => 1, 'files.daily' => 1]);
});

test('the backup check alerts when the newest backup is too old', function () {
    seedCompany('Alpha');
    $this->artisan('backup:run')->assertSuccessful();
    $this->artisan('backup:run', ['--files' => true, '--reason' => 'daily'])->assertSuccessful();

    $this->artisan('backup:check')->assertSuccessful();

    $this->travel(2)->hours();
    $this->artisan('backup:check')->assertFailed();
    expect($this->admin->notifications()->sole()->data['kind'])->toBe('backup_check');
});

test('before external storage is set up a deployment still gets a local copy; scheduled runs fail loudly', function () {
    config(['backup.encryption_key' => null]);
    seedCompany('Alpha');

    $this->artisan('backup:run', ['--reason' => 'deploy'])->assertSuccessful();
    $local = File::glob(storage_path('app/local-backups/db-*-deploy.dump'));
    expect($local)->not->toBeEmpty();
    File::delete($local);

    $this->artisan('backup:run')->assertFailed();
});
