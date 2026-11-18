<?php

namespace App\Support\Push;

use App\Models\Company;
use App\Models\PushAction;
use App\Models\User;
use App\Notifications\AppAlert;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Push notifications with buttons (owner's task of November 19). Android shows up to two buttons under a notification
 * (Chrome); the third (if any) stays in the bell list. A button either opens a page (Draft, Call, Text again) or runs
 * an operation on the server; the tap itself is the confirmation, so the notification's text always says what the
 * button will do. "Later" shows the same notification again an hour later.
 *
 * Operations are registered by the features that offer them (see AppServiceProvider::boot via PushActions::define).
 */
class PushActions
{
    public const LATER = 'later';

    public const LATER_MINUTES = 60;

    public const LIFETIME_DAYS = 3;

    /** @var array<string, Closure(PushAction, array<string, mixed>, User): string> */
    private static array $operations = [];

    /**
     * An operation a button can run: receives the stored row, the button and the person; returns what happened.
     *
     * @param  Closure(PushAction, array<string, mixed>, User): string  $handler
     */
    public static function define(string $op, Closure $handler): void
    {
        self::$operations[$op] = $handler;
    }

    /**
     * Sends a notification with buttons to one person (bell list + push).
     *
     * @param  array<string, array<string, mixed>>  $buttons  key => ['title' => ..., 'url' => ...] (opens a page) or ['title' => ..., 'op' => ..., ...] (runs on the server)
     */
    public static function notify(User $user, string $kind, string $title, string $body, ?string $url, array $buttons): PushAction
    {
        $action = PushAction::query()->create([
            'user_id' => $user->id,
            'token' => Str::random(48),
            'kind' => $kind,
            'title' => mb_substr($title, 0, 250),
            'body' => $body,
            'url' => $url,
            'actions' => $buttons,
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        self::deliver($action);

        return $action;
    }

    /**
     * The usual "Later" button.
     *
     * @return array{title: string}
     */
    public static function later(): array
    {
        return ['title' => __('push.later')];
    }

    public static function deliver(PushAction $action): void
    {
        try {
            $action->user->notify(new AppAlert($action->kind, $action->title, $action->body, $action->url, mail: false,
                actions: collect($action->actions)->map(fn (array $button, string $key) => [
                    'action' => $key, 'title' => (string) $button['title'], 'url' => $button['url'] ?? null,
                ])->values()->all(),
                actionUrl: route('push-actions.run', ['token' => $action->token, 'action' => '__action__'])));
        } catch (Throwable $e) {
            Log::warning('Push with actions failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A tap on a button that runs on the server. Returns the line shown to the person afterwards.
     */
    public static function run(string $token, string $key, User $user): string
    {
        $action = PushAction::withoutCompanyScope()->where('token', $token)->first();
        abort_if($action === null || $action->user_id !== $user->id, 404);
        $button = $action->actions[$key] ?? null;
        abort_if(! is_array($button), 404);

        if ($action->used_at !== null) {
            return __('push.already_done');
        }
        if ($action->expires_at->isPast()) {
            return __('push.expired');
        }

        $company = Company::query()->findOrFail($action->company_id);
        abort_unless($user->memberships()->where('company_id', $company->id)->where('is_active', true)->exists(), 403);

        return app(CurrentCompany::class)->runAs($company, function () use ($action, $key, $button, $user) {
            if ($key === self::LATER) {
                $action->update(['resend_at' => now()->addMinutes(self::LATER_MINUTES)]);

                return __('push.later_done');
            }

            $handler = self::$operations[$button['op'] ?? ''] ?? null;
            abort_if($handler === null, 404);

            try {
                $result = $handler($action, $button, $user);
            } catch (ValidationException $e) {
                return (string) collect($e->errors())->flatten()->first();
            }
            $action->update(['used_at' => now(), 'resend_at' => null]);

            return $result;
        });
    }

    /**
     * Every minute: notifications put off with "Later" come back.
     */
    public static function resendDue(): int
    {
        $count = 0;
        PushAction::withoutCompanyScope()->with('user')->whereNull('used_at')->where('resend_at', '<=', now())
            ->where('expires_at', '>', now())->get()
            ->each(function (PushAction $action) use (&$count) {
                $company = Company::query()->find($action->company_id);
                $action->forceFill(['resend_at' => null])->saveQuietly();
                if ($company === null || $action->user === null) {
                    return;
                }
                app(CurrentCompany::class)->runAs($company, fn () => self::deliver($action));
                $count++;
            });

        return $count;
    }
}
