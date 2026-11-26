<?php

namespace App\Http\Controllers\Jobs;

use App\Enums\ApplianceCategory;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\ServiceJob;
use App\Services\AI\AiLimitReached;
use App\Services\AI\AiService;
use App\Services\AI\AiUnavailable;
use App\Services\AI\BookingEditor;
use App\Services\AI\VoiceCloser;
use App\Support\Billing\ChargeLines;
use App\Support\Billing\DocumentTotals;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Voice on the job (owner's task of November 18): "Quick edit" of the booking (the AI returns only the fields to
 * change; the form is filled, nothing is saved) and "Close by voice" (the AI fills note, invoice lines, My expenses,
 * payment and status; an editable preview; only Confirm & send or Save only saves).
 */
class JobVoiceController extends Controller
{
    public function quickEdit(Request $request, ServiceJob $job, BookingEditor $editor): JsonResponse
    {
        Gate::authorize('update', $job);
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'current' => ['present', 'array'],
            'current.*' => ['nullable', 'string', 'max:5000'],
        ]);

        $technicians = Membership::query()->assignable()->with('user')->get()
            ->filter(fn (Membership $m) => $m->user !== null)
            ->map(fn (Membership $m) => ['id' => $m->user->id, 'name' => $m->user->name])->values()->all();
        $current = collect($data['current'])->only(['date', 'start_time', 'end_time', 'technician', 'first_name', 'last_name', 'phone', 'email', 'address', 'problem', 'notes'])->all();

        return $this->ai(function () use ($editor, $data, $current, $technicians, $request) {
            $changes = $editor->edit($data['text'], $current, $technicians, currentCompany(), $request->user());

            return $changes === []
                ? response()->json(['changes' => [], 'message' => __('automation.quick_edit.not_understood')])
                : response()->json(['changes' => $changes]);
        });
    }

    public function readClose(Request $request, ServiceJob $job, VoiceCloser $closer): JsonResponse
    {
        Gate::authorize('work', $job);
        $text = $request->validate(['text' => ['required', 'string', 'max:5000']])['text'];

        return $this->ai(function () use ($closer, $job, $text, $request) {
            $reading = $closer->read($job, $text, $request->user());

            return response()->json([
                'draft' => $reading,
                'money' => VoiceCloser::totals($reading, currentCompany()),
                'taxes' => array_map(fn (array $t) => ['id' => $t['tax_rate_id'], 'name' => $t['name'], 'rate' => DocumentTotals::rateLabel($t['rate'])], ChargeLines::taxes()),
            ]);
        });
    }

    /**
     * The preview's money again after the ticks of a line's taxes or "with tax" were changed (code only).
     */
    public function previewClose(Request $request, ServiceJob $job): JsonResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate([
            ...self::lineRules(),
            'amounts_include_tax' => ['required', 'boolean'],
        ]);

        return response()->json(['money' => VoiceCloser::totals(['lines' => self::named($data['lines'] ?? []), 'amounts_include_tax' => $data['amounts_include_tax']], currentCompany())]);
    }

    public function confirmClose(Request $request, ServiceJob $job, VoiceCloser $closer): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:5000'],
            ...self::lineRules(),
            'amounts_include_tax' => ['required', 'boolean'],
            'payment' => ['required', Rule::in(VoiceCloser::PAYMENTS)],
            'status' => ['required', Rule::in(VoiceCloser::STATUSES)],
            'part_name' => ['nullable', 'string', 'max:255'],
            'appliance_type' => ['nullable', Rule::enum(ApplianceCategory::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            // My expenses: what was paid for the job (never on the invoice).
            'expenses' => ['array', 'max:20'],
            'expenses.*.description' => ['nullable', 'string', 'max:255'],
            'expenses.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            // Confirm & send (true) texts the invoice or the receipt; Save only (false) sends nothing.
            'send' => ['boolean'],
        ]);
        $data['lines'] = self::named($data['lines'] ?? []);
        $data['expenses'] ??= [];

        Inertia::flash('toast', $closer->confirm($job, $data, $request->user(), $request->boolean('send')));

        // Closed (Confirm & send or Save only): straight to Today (owner's task of November 26); a part to order
        // keeps the job open and the job page.
        return $job->refresh()->closed_at !== null ? to_route('jobs.mine') : back();
    }

    /**
     * A line left without a name is "Service".
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function named(array $lines): array
    {
        return array_values(array_map(fn (array $line) => [
            ...$line,
            'description' => trim((string) ($line['description'] ?? '')) ?: __('assistant.invoice.default_line'),
        ], $lines));
    }

    /**
     * Invoice lines of Close by voice: each with its amount and the company taxes ticked on it.
     *
     * @return array<string, list<mixed>>
     */
    private static function lineRules(): array
    {
        return [
            'lines' => ['array', 'max:20'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'lines.*.kind' => ['required', Rule::in(['service', 'part'])],
            'lines.*.tax_rate_ids' => ['nullable', 'array', 'max:10'],
            'lines.*.tax_rate_ids.*' => ['integer'],
        ];
    }

    /**
     * @param  Closure(): JsonResponse  $call
     */
    private function ai(Closure $call): JsonResponse
    {
        if (! app(AiService::class)->configured()) {
            return response()->json(['message' => __('jobs.quick_booking.not_set_up')], 503);
        }

        try {
            return $call();
        } catch (AiLimitReached) {
            return response()->json(['message' => __('jobs.quick_booking.limit')], 429);
        } catch (AiUnavailable) {
            return response()->json(['message' => __('jobs.quick_booking.failed')], 503);
        }
    }
}
