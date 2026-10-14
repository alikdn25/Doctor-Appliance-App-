<?php

namespace App\Notifications;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Who gets an alert: the company's Owners, or the platform owner (super-admins) for server matters.
 */
class Alerts
{
    public static function owners(Company $company, AppAlert $alert): void
    {
        $owners = User::query()
            ->where('is_active', true)
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)
                ->where('is_active', true)
                ->where('role', UserRole::Owner->value))
            ->get();

        self::send($owners->all(), $alert);
    }

    public static function platform(AppAlert $alert): void
    {
        self::send(User::query()->where('is_super_admin', true)->where('is_active', true)->get()->all(), $alert);

        if (TelegramChannel::configured()) {
            self::send([Notification::route(TelegramChannel::class, 'ops')], $alert);
        }

        // Always in the server log too, so an alert is never lost when no channel works.
        Log::channel(config('backup.alerts.log_channel', 'stack'))->error("{$alert->title}: {$alert->body}");
    }

    /**
     * @param  list<object>  $notifiables
     */
    private static function send(array $notifiables, AppAlert $alert): void
    {
        foreach ($notifiables as $notifiable) {
            try {
                $notifiable->notify($alert);
            } catch (Throwable $e) {
                // One broken channel (mail, push) must not stop the others or the caller.
                Log::warning('Alert delivery failed', ['kind' => $alert->kind, 'error' => $e->getMessage()]);
            }
        }
    }
}
