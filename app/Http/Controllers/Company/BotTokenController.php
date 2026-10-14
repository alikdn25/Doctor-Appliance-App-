<?php

namespace App\Http\Controllers\Company;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\BotToken;
use App\Services\AuditLogger;
use App\Support\Jobs\JobPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Owner-only: API tokens of the website booking bot (role "Bot"). A new token is shown once.
 */
class BotTokenController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeOwner($request);

        return Inertia::render('company/booking-bot', [
            'tokens' => BotToken::query()->orderByDesc('id')->get()->map(fn (BotToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'hint' => $token->token_hint,
                'requests_per_minute' => $token->requests_per_minute,
                'bookings_per_hour' => $token->bookings_per_hour,
                'last_used_at' => JobPresenter::iso($token->last_used_at),
                'revoked_at' => JobPresenter::iso($token->revoked_at),
                'created_at' => JobPresenter::iso($token->created_at),
            ])->values(),
            'apiUrl' => url('/api/bot/v1'),
            // The plain token, once, right after it was created.
            'newToken' => $request->session()->get('bot_token_plain'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeOwner($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'bookings_per_hour' => ['required', 'integer', 'min:1', 'max:50'],
            'requests_per_minute' => ['required', 'integer', 'min:5', 'max:120'],
        ]);

        [$token, $plain] = BotToken::issue(currentCompany(), $data['name'], $request->user());
        $token->update(['bookings_per_hour' => $data['bookings_per_hour'], 'requests_per_minute' => $data['requests_per_minute']]);
        $audit->record('bot_token.created', $token, ['name' => $token->name, 'hint' => $token->token_hint]);

        return to_route('company.booking-bot')->with('bot_token_plain', $plain);
    }

    public function destroy(Request $request, BotToken $token, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeOwner($request);

        if ($token->revoked_at === null) {
            $token->forceFill(['revoked_at' => now()])->save();
            $audit->record('bot_token.revoked', $token, ['name' => $token->name, 'hint' => $token->token_hint]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('bot.settings.revoked')]);

        return to_route('company.booking-bot');
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::Owner), 403);
    }
}
