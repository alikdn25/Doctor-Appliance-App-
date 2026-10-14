<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DataSnapshot;
use App\Providers\AppServiceProvider;
use App\Support\Database\MigrationGuard;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

test('the watchdog counts records daily and tells the Owner about a sudden drop', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, UserRole::Owner);
    $tech = memberOf($company, UserRole::Technician);
    $customers = Customer::factory()->for($company)->count(12)->create();

    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:10', 'America/Vancouver'));
    $this->artisan('ops:watchdog')->assertSuccessful();
    expect(DataSnapshot::query()->where('company_id', $company->id)->value('customers'))->toBe(12);

    // A second run the same day does nothing; other hours do nothing.
    $this->artisan('ops:watchdog')->assertSuccessful();
    expect(DataSnapshot::query()->count())->toBe(1);

    // One customer deleted: under 10 and 8% of 12 is over 5% → alert.
    $customers->first()->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:05', 'America/Vancouver'));
    $this->artisan('ops:watchdog')->assertSuccessful();

    $alert = $owner->notifications()->sole();
    expect($alert->data['kind'])->toBe('data_drop')
        ->and($alert->data['body'])->toContain('customers 12 → 11')
        ->and($tech->notifications()->count())->toBe(0);
});

test('the watchdog stays quiet when records only grow', function () {
    $company = Company::factory()->create();
    $owner = memberOf($company, UserRole::Owner);
    Customer::factory()->for($company)->count(3)->create();

    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'America/Vancouver'));
    $this->artisan('ops:watchdog')->assertSuccessful();
    Customer::factory()->for($company)->create();
    $this->travelTo(CarbonImmutable::parse('2026-10-20 07:00', 'America/Vancouver'));
    $this->artisan('ops:watchdog')->assertSuccessful();

    expect($owner->notifications()->count())->toBe(0);
});

test('destructive database commands and seeding are refused in production', function () {
    app()->detectEnvironment(fn () => 'production');
    (new AppServiceProvider(app()))->boot();

    $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
    $this->artisan('db:wipe', ['--force' => true])->assertFailed();

    $starting = fn (string $command, array $input = []) => event(new CommandStarting(
        $command, new ArrayInput($input), new NullOutput,
    ));
    expect(fn () => $starting('db:seed'))->toThrow(RuntimeException::class, 'Seeding is not allowed in production');
    expect(fn () => $starting('migrate', ['--seed' => true]))->toThrow(RuntimeException::class, 'Seeding is not allowed in production');
    expect(fn () => (new DemoSeeder)->run())->toThrow(RuntimeException::class, 'Demo data cannot be seeded in production.');
    expect(Customer::withoutCompanyScope()->count())->toBe(0);
});

test('a migration that drops or renames columns is caught unless the owner approved it', function () {
    $dir = storage_path('framework/testing/migration-guard');
    File::ensureDirectoryExists($dir);
    File::put("{$dir}/2099_01_01_000000_drop_notes.php", <<<'PHP'
        <?php
        return new class extends Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                // dropColumn in a comment is fine
                Illuminate\Support\Facades\Schema::table('customers', fn ($table) => $table->dropColumn('notes'));
            }

            public function down(): void
            {
            }
        };
        PHP);
    File::put("{$dir}/2099_01_01_000001_add_column.php", <<<'PHP'
        <?php
        return new class extends Illuminate\Database\Migrations\Migration
        {
            public function up(): void
            {
                Illuminate\Support\Facades\Schema::table('customers', fn ($table) => $table->string('nickname')->nullable());
            }

            public function down(): void
            {
                Illuminate\Support\Facades\Schema::table('customers', fn ($table) => $table->dropColumn('nickname'));
            }
        };
        PHP);

    expect(MigrationGuard::unapproved(File::glob("{$dir}/*.php")))->toBe(['2099_01_01_000000_drop_notes' => ['drop column']]);

    File::deleteDirectory($dir);
});

test('every migration in the repository that removes data is approved by the owner', function () {
    expect(MigrationGuard::unapproved(File::glob(database_path('migrations/*.php'))))->toBe([]);
});
