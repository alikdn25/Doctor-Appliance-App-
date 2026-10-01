<?php

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Support\Translations;
use Illuminate\Support\Arr;
use Symfony\Component\Finder\Finder;

test('every translation key used in the React UI exists', function () {
    $translations = Translations::forLocale('en');
    $missing = [];
    $checked = 0;

    $files = Finder::create()->files()->in(resource_path('js'))->name(['*.tsx', '*.ts'])
        ->exclude(['actions', 'routes', 'wayfinder']);

    foreach ($files as $file) {
        // t('group.key'), title: 'group.key', label="group.key"
        preg_match_all("/(?:\\bt\\(|title: |description: |label=)['\"]([a-z_]+\\.[a-z_.]+)['\"]/", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $checked++;

            if (! is_string(Arr::get($translations, $key))) {
                $missing[] = "{$key} ({$file->getRelativePathname()})";
            }
        }
    }

    expect($checked)->toBeGreaterThan(100)
        ->and($missing)->toBe([]);
});

test('enum labels are translated', function () {
    foreach (UserRole::cases() as $role) {
        expect($role->label())->not->toStartWith('roles.');
    }
    foreach (CompanyStatus::cases() as $status) {
        expect($status->label())->not->toStartWith('admin.');
    }
    foreach (SubscriptionStatus::cases() as $status) {
        expect($status->label())->not->toStartWith('admin.');
    }
});
