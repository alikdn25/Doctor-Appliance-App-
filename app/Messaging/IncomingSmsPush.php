<?php

namespace App\Messaging;

use App\Enums\UserRole;
use App\Models\Message;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Push\PushActions;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A push for every incoming text (owner's task of November 19): the AI's one line (who writes and what they want) and
 * the buttons — the main proposed action, done on tap (the notification says exactly what it does), Draft (the chat
 * with the reply draft in the box) and Later (the same notification again in an hour). Without the AI: the text
 * itself, Draft and Later. Goes to the active Owners and Admins. Must run in a tenant context.
 */
class IncomingSmsPush
{
    public static function send(Message $message): void
    {
        if ($message->direction !== Message::INBOUND || $message->from === null) {
            return;
        }

        $company = currentCompany();
        $name = $message->customer?->display_name ?? PhoneNumber::display((string) $message->from, $company->country);
        $summary = $message->ai_triage['summary'] ?? null;
        $title = $summary ? "{$name}: {$summary}" : __('push.incoming_title', ['name' => $name]);
        $body = mb_strimwidth($message->body !== '' ? $message->body : __('push.photo'), 0, 140, '…');
        $buttons = SmsThreads::replyButtons($message, (string) $message->from);
        if (isset($buttons['main'])) {
            $body = __('push.main_will', ['action' => $buttons['main']['does']])."\n".$body;
        }

        $office = User::query()->where('is_active', true)
            ->whereHas('memberships', fn ($q) => $q->where('company_id', $company->id)->where('is_active', true)
                ->whereIn('role', [UserRole::Owner->value, UserRole::Admin->value]))
            ->get();

        foreach ($office as $user) {
            try {
                PushActions::notify($user, 'sms_incoming', $title, $body, route('messages.index', ['phone' => $message->from]), $buttons);
            } catch (Throwable $e) {
                Log::warning('Incoming text push failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
