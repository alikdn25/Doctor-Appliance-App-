<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Support\AppVersion;
use Illuminate\Support\Facades\File;

/*
 * Installed app on Android showed "No connection" with the internet working. The service worker now carries the
 * running version (a new one installs with each deploy), shows the offline page only on a real network failure,
 * and the manifest's start page opens for signed-in and signed-out people alike.
 */
afterEach(fn () => File::delete(AppVersion::releaseFile()));

test('the service worker carries the running version and is never cached', function () {
    File::put(AppVersion::releaseFile(), "abc123\n");
    $before = AppVersion::current();

    $response = $this->get('/sw.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/');

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('no-cache');
    expect($response->getContent())
        ->toContain("const VERSION = '{$before}';")
        ->not->toContain('__SW_VERSION__');

    File::put(AppVersion::releaseFile(), "def456\n");
    expect($this->get('/sw.js')->getContent())
        ->toContain("const VERSION = '".AppVersion::current()."';")
        ->not->toContain($before);
});

test('the service worker is not a static file, so the web server never serves a stale copy', function () {
    expect(file_exists(public_path('sw.js')))->toBeFalse();
});

test('the service worker takes over at once and drops old caches', function () {
    $script = $this->get('/sw.js')->getContent();

    expect($script)
        ->toContain('self.skipWaiting()')
        ->toContain('self.clients.claim()')
        ->toContain('caches.delete(key)')
        ->toContain("redirect: 'follow'")
        ->toContain("cache: 'no-store'")
        // Offline page only when there is no network or the request failed; server answers pass through.
        ->toContain('self.navigator.onLine === false')
        ->toContain('Response.redirect(response.url, 303)');
});

test('the service worker works for signed-in people too', function () {
    $this->actingAs(memberOf(Company::factory()->create(), UserRole::Technician));

    expect($this->get('/sw.js')->assertOk()->getContent())->toContain('const VERSION =');
});

test('the offline page retries with a fresh service worker and no cache', function () {
    $page = (string) file_get_contents(public_path('offline.html'));

    expect($page)
        ->toContain('registration.update()')
        ->toContain('caches.delete(key)')
        ->toContain('window.location.reload()');
});

test('the start page opens for a signed-out person: it leads to sign-in', function () {
    $start = $this->get('/manifest.webmanifest')->assertOk()->json('start_url');
    expect($this->get('/manifest.webmanifest')->json('scope'))->toBe('/');
    expect($start)->toStartWith('/');

    $this->get($start)->assertRedirect(route('login'));
    $this->get(route('login'))->assertOk();
});

test('the start page opens for every role that can be given in the app', function (UserRole $role) {
    $start = $this->get('/manifest.webmanifest')->json('start_url');
    $this->actingAs(memberOf(Company::factory()->create(), $role));

    $this->get($start)->assertOk();
})->with(UserRole::assignable());
