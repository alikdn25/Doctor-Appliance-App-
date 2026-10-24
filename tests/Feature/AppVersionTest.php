<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Support\AppVersion;
use Illuminate\Support\Facades\File;

/*
 * "New version available" (owner's task, October 24): the app compares the version it loaded with /app-version.
 */
afterEach(fn () => File::delete(AppVersion::releaseFile()));

test('the version changes with every deploy and is never cached', function () {
    File::put(AppVersion::releaseFile(), "abc123\n");
    $before = AppVersion::current();

    $this->get(route('app-version'))
        ->assertOk()
        ->assertJson(['version' => $before])
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

    File::put(AppVersion::releaseFile(), "def456\n");
    expect(AppVersion::current())->not->toBe($before);
    $this->get(route('app-version'))->assertJson(['version' => AppVersion::current()]);
});

test('every page carries the version it was built with', function () {
    File::put(AppVersion::releaseFile(), 'abc123');
    $company = Company::factory()->create();
    $tech = memberOf($company, UserRole::Technician);

    $props = $this->actingAs($tech)->get(route('jobs.mine'))->viewData('page')['props'];
    expect($props['appVersion'])->toBe(AppVersion::current());
});
