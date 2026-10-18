<?php

namespace App\Enums;

/**
 * The platform's subscription plans (SPEC §11.2). Prices live with the billing module, not here.
 */
enum SubscriptionPlan: string
{
    case Solo = 'solo';
    case Team = 'team';
    case Pro = 'pro';

    public function label(): string
    {
        return __("admin.plans.{$this->value}");
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $plan) => ['value' => $plan->value, 'label' => $plan->label()], self::cases());
    }
}
