<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Technician = 'technician';
    case Subcontractor = 'subcontractor';
    case Collector = 'collector';

    /**
     * Roles that can be assigned from the UI in the current stage.
     * Subcontractor and Collector arrive in Stage 2.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Owner, self::Admin, self::Technician];
    }

    public function label(): string
    {
        return __("roles.{$this->value}");
    }

    public function requiresTwoFactor(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function assignableOptions(): array
    {
        return array_map(
            fn (self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::assignable(),
        );
    }
}
