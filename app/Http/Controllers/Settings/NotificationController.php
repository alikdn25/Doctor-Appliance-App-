<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\FcmToken;
use App\Models\PushSubscription;
use App\Notifications\Channels\WebPushChannel;
use App\Support\Jobs\JobPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The person's notifications (kept in the app) and push on this phone or browser.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'notifications' => $user->notifications()->limit(100)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? null,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                // Buttons of a push notification with actions (owner's task of November 19).
                'actions' => $n->data['actions'] ?? [],
                'action_url' => $n->data['action_url'] ?? null,
                'read' => $n->read_at !== null,
                'at' => JobPresenter::iso($n->created_at),
            ])->values(),
            // The morning summary of this company (owner's task of November 18).
            'morning' => [
                'enabled' => (bool) ($user->currentMembership()?->morning_summary_enabled ?? true),
                'time' => $user->currentMembership()?->morning_summary_time ?? '07:00',
            ],
            'push' => [
                'available' => WebPushChannel::configured(),
                'publicKey' => config('webpush.public_key'),
                'devices' => PushSubscription::query()->where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function morning(Request $request): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'time' => ['required', 'date_format:H:i']]);
        $request->user()->currentMembership()?->update(['morning_summary_enabled' => $data['enabled'], 'morning_summary_time' => $data['time']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('automation.today.saved')]);

        return back();
    }

    public function read(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'starts_with:https://', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // One row per device: a device moving to another person (shared phone) moves with it.
        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => hash('sha256', $data['endpoint'])]);
        $subscription->fill([
            'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'],
            'auth_token' => $data['keys']['auth'],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
        $subscription->user_id = $request->user()->id;
        $subscription->save();

        return response()->json(['ok' => true]);
    }

    /**
     * The Android app registers its Firebase token on every start while signed in (owner's task of November 22). One
     * row per install: a phone signed in by someone else moves to that person, so nobody gets another's pushes.
     */
    public function fcm(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:20', 'max:512']]);

        $token = FcmToken::query()->firstOrNew(['token_hash' => hash('sha256', $data['token'])]);
        $token->fill(['token' => $data['token'], 'platform' => 'android', 'last_seen_at' => now()]);
        $token->user_id = $request->user()->id;
        $token->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Signing out in the app stops its pushes.
     */
    public function forgetFcm(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        FcmToken::query()->where('user_id', $request->user()->id)->where('token_hash', hash('sha256', $data['token']))->delete();

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);
        PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
