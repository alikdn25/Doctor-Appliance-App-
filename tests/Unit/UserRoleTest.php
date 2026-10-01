<?php

use App\Enums\UserRole;

test('only Owner, Admin and Technician are assignable in stage 0', function () {
    expect(UserRole::assignable())->toBe([UserRole::Owner, UserRole::Admin, UserRole::Technician]);
});

test('Owner and Admin require two-factor authentication', function (UserRole $role, bool $required) {
    expect($role->requiresTwoFactor())->toBe($required);
})->with([
    [UserRole::Owner, true],
    [UserRole::Admin, true],
    [UserRole::Technician, false],
    [UserRole::Subcontractor, false],
    [UserRole::Collector, false],
]);
