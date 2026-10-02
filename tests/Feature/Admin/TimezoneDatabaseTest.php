<?php

use App\Models\User;
use App\Support\TimezoneDatabase;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('versions are read from PHP\'s bundled database', function (string $php, ?string $version) {
    expect((new TimezoneDatabase($php))->version())->toBe($version);
})->with([
    'bundled' => ['2025.2', '2025b'],
    'first release' => ['2026.1', '2026a'],
    'letter form' => ['2026c', '2026c'],
    'garbage' => ['banana', null],
    'system without file' => ['0.system', null],
]);

test('with the system database the version is read from tzdata.zi', function () {
    $file = tempnam(sys_get_temp_dir(), 'tz');
    file_put_contents($file, "# version 2026b\n# dataform rearguard\nR d 1916 o - Ap 30 23 1 S\n");

    expect((new TimezoneDatabase('0.system', $file))->version())->toBe('2026b');

    file_put_contents($file, "no version here\n");
    expect((new TimezoneDatabase('0.system', $file))->version())->toBeNull();

    unlink($file);
});

test('the release date is estimated two months per release letter', function (string $version, string $date) {
    expect((new TimezoneDatabase($version))->estimatedReleaseDate()->toDateString())->toBe($date);
})->with([
    ['2026a', '2026-01-01'],
    ['2026b', '2026-03-01'],
    ['2026d', '2026-07-01'],
    ['2026z', '2026-12-01'],
]);

test('a database older than six months is outdated', function () {
    $tz = new TimezoneDatabase('2026b');

    expect($tz->isOutdated(CarbonImmutable::parse('2026-08-31')))->toBeFalse()
        ->and($tz->isOutdated(CarbonImmutable::parse('2026-09-02')))->toBeTrue()
        ->and((new TimezoneDatabase('0.system'))->isOutdated())->toBeNull();
});

test('the super-admin panel warns about an outdated database', function () {
    $this->travelTo('2030-06-01');
    $this->app->instance(TimezoneDatabase::class, new TimezoneDatabase('2026.2'));

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.companies.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tzdata.version', '2026b')
            ->where('tzdata.released', 'Mar 2026')
            ->where('tzdata.outdated', true));
});

test('a recent database shows no warning', function () {
    $this->travelTo('2026-05-01');
    $this->app->instance(TimezoneDatabase::class, new TimezoneDatabase('2026b'));

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.companies.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tzdata.outdated', false));
});

test('an unknown version is reported', function () {
    $this->app->instance(TimezoneDatabase::class, new TimezoneDatabase('0.system', '/nonexistent/tzdata.zi'));

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(route('admin.companies.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tzdata.version', null)->where('tzdata.outdated', null));
});
