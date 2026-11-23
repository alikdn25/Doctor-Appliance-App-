<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\QuickBookingParser;
use App\Support\Customers\CustomerFinder;
use App\Support\Jobs\BookingPhotos;
use App\Support\PrivateMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Quick booking (Owner and Office): one big text field (typed, dictated with the phone keyboard's microphone, or an
 * SMS pasted in) and Parse. The AI reads the text into the booking form's fields and a customer already in the book is
 * found by phone, then by name and address. Nothing is saved here: the filled Book customer form opens and the person
 * presses Save. Up to 5 photos (a screenshot of the customer's message, a rating plate) can go with or instead of the
 * text; they wait in the sender's private folder and become the job's photos when the booking is saved.
 */
class QuickBookingController extends Controller
{
    public function show(): Response
    {
        Gate::authorize('useQuickBooking');

        return Inertia::render('jobs/quick-booking');
    }

    public function parse(Request $request, QuickBookingParser $parser, CustomerFinder $finder): JsonResponse
    {
        Gate::authorize('useQuickBooking');

        $data = $request->validate([
            'text' => ['required_without:photos', 'nullable', 'string', 'max:5000'],
            // Photos or screenshots (the customer's message, a rating plate), already shrunk on the phone.
            'photos' => ['nullable', 'array', 'max:'.BookingPhotos::MAX],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:6144'],
        ]);
        $text = (string) ($data['text'] ?? '');

        if (! app(AiService::class)->configured()) {
            return response()->json(['message' => __('jobs.quick_booking.not_set_up')], 503);
        }

        $photos = BookingPhotos::store(array_values($request->file('photos', [])), currentCompany()->id, $request->user()->id);

        try {
            $fields = $parser->parse($text, currentCompany(), $request->user(), $photos);
        } catch (AiLimitReached) {
            return response()->json(['message' => __('jobs.quick_booking.limit')], 429);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('jobs.quick_booking.failed')], 503);
        }

        $found = $finder->find($fields['phone'], $fields['first_name'], $fields['last_name'], $fields['address']['line1'] ?? null);

        return response()->json([
            'fields' => $fields,
            'customer' => $found === null ? null : JobController::customerOption($found['customer']),
            'matched_by' => $found['by'] ?? null,
            'photos' => self::photoList(array_column($photos, 'id')),
        ]);
    }

    /**
     * A booking photo waiting to be attached, only to the person who sent it.
     */
    public function photo(Request $request, string $photo): StreamedResponse
    {
        Gate::authorize('useQuickBooking');

        $path = BookingPhotos::find($photo, currentCompany()->id, $request->user()->id);
        abort_if($path === null, 404);

        return PrivateMedia::response($path);
    }

    /**
     * Photo ids with their preview links, for the booking form.
     *
     * @param  list<string>  $ids
     * @return list<array{id: string, url: string}>
     */
    public static function photoList(array $ids): array
    {
        return array_map(fn (string $id) => ['id' => $id, 'url' => route('jobs.quick-booking.photo', ['photo' => $id], false)], $ids);
    }
}
