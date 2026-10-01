<?php

use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;

test('runAs restores the previous company even when the callback throws', function () {
    $tenancy = new CurrentCompany;
    $a = (new Company)->forceFill(['id' => 1]);
    $b = (new Company)->forceFill(['id' => 2]);
    $tenancy->set($a);

    expect($tenancy->runAs($b, fn () => $tenancy->id()))->toBe(2)
        ->and($tenancy->id())->toBe(1);

    try {
        $tenancy->runAs($b, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($tenancy->id())->toBe(1);
});
