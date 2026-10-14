<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messaging\MessageContext;
use App\Models\BotToken;
use App\Models\ServiceJob;
use App\Notifications\Alerts;
use App\Notifications\AppAlert;
use App\Support\Bot\BookingBot;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The website booking bot's API (routes/api.php). Every action is in the change log marked "bot"; every booking
 * is pushed to the company's Owners.
 */
class BookingBotController extends Controller
{
    public function __construct(private readonly BookingBot $bot) {}

    public function windows(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.BookingBot::MAX_DAYS],
        ]);
        $company = currentCompany();
        $from = isset($data['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $company->timezone)
            : CarbonImmutable::now($company->timezone);

        return response()->json([
            'timezone' => $company->timezone,
            'windows' => $this->bot->freeWindows($company, $from, (int) ($data['days'] ?? 7)),
        ]);
    }

    public function findCustomer(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $customer = $this->bot->findByPhone($data['phone']);

        return response()->json(['found' => $customer !== null, 'customer' => $customer]);
    }

    public function storeCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.unit' => ['nullable', 'string', 'max:50'],
            'address.city' => ['required', 'string', 'max:100'],
            'address.region' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.access_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $this->bot->createCustomer($data);

        return response()->json($result, $result['existing'] ? 200 : 201);
    }

    public function book(Request $request): JsonResponse
    {
        /** @var BotToken $token */
        $token = $request->attributes->get('bot_token');
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'address_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'problem' => ['required', 'string', 'max:1000'],
            'appliance' => ['nullable', 'string', 'max:100'],
        ]);

        $key = "bot-bookings:{$token->id}";

        if (RateLimiter::tooManyAttempts($key, max(1, $token->bookings_per_hour))) {
            return response()->json(['message' => __('bot.errors.too_many_bookings')], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        $job = $this->bot->book($token, $data);
        RateLimiter::hit($key, 3600);

        $this->notifyOwners($job);

        $visit = $job->visits()->first();
        $timezone = currentCompany()->timezone;

        return response()->json([
            'job_number' => $job->number,
            'date' => $visit?->scheduled_start->setTimezone($timezone)->format('Y-m-d'),
            'start' => $visit?->scheduled_start->setTimezone($timezone)->format('H:i'),
            'end' => $visit?->scheduled_end->setTimezone($timezone)->format('H:i'),
        ], 201);
    }

    private function notifyOwners(ServiceJob $job): void
    {
        $company = currentCompany();
        $visit = $job->visits()->first();

        // Date and time in the company's regional format and time zone.
        Alerts::owners($company, new AppAlert(
            'bot_booking',
            __('bot.alert.title', ['number' => $job->number]),
            __('bot.alert.body', [
                'customer' => $job->customer->display_name,
                'date' => (string) MessageContext::date($visit?->scheduled_start),
                'window' => $visit ? MessageContext::window($visit) : '',
                'problem' => (string) $job->description,
            ]),
            route('jobs.show', $job),
        ));
    }
}
