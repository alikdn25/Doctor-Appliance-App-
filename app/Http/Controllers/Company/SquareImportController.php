<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\SquareImport;
use App\Models\SquareImportCustomer;
use App\Payments\PaymentProviders;
use App\Services\SquareImport\SquareImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Import from Square" (Owner only): read the customers, review the matches, import customers and the invoice
 * history in the background with progress, and undo a whole import.
 */
class SquareImportController extends Controller
{
    public function show(SquareImporter $importer, PaymentProviders $providers): Response
    {
        Gate::authorize('managePayments', currentCompany());

        $connection = $importer->connection(currentCompany());
        $missing = [];
        $error = null;

        if ($connection !== null) {
            try {
                $missing = $importer->missingScopes($connection);
            } catch (Throwable) {
                $error = __('square_import.check_failed');
            }
        }

        $latest = SquareImport::query()->latest('id')->first();

        return Inertia::render('company/square-import', [
            'connected' => $connection !== null,
            // Square is set up on this installation and serves the company's country: offer to connect it here.
            'canConnect' => $canConnect = array_key_exists('square', $providers->availableFor(currentCompany())),
            // The one message shown without a connection: "Connect Square first." with the button, or only
            // "Square is not available for your company yet." when it cannot be connected.
            'notice' => $connection !== null ? null : ($canConnect ? 'connect' : 'not_available'),
            'account' => $connection?->account_name,
            'missingScopes' => $missing,
            'checkError' => $error,
            'current' => $latest === null ? null : self::present($latest),
            'matches' => $latest?->status === SquareImport::REVIEW ? self::matches($latest) : [],
            'history' => SquareImport::query()->with('user')->latest('id')->limit(20)->get()->map(fn (SquareImport $i) => self::present($i))->values(),
        ]);
    }

    public function start(Request $request, SquareImporter $importer): RedirectResponse
    {
        Gate::authorize('managePayments', currentCompany());

        abort_if($importer->connection(currentCompany()) === null, 422);

        $busy = SquareImport::query()->whereIn('status', [SquareImport::SCANNING, SquareImport::REVIEW, SquareImport::IMPORTING])->exists();
        if (! $busy) {
            $importer->start($request->user());
        }

        return to_route('square-import.show');
    }

    public function status(SquareImport $import): JsonResponse
    {
        Gate::authorize('managePayments', currentCompany());

        return response()->json(self::present($import));
    }

    public function run(Request $request, SquareImport $import, SquareImporter $importer): RedirectResponse
    {
        Gate::authorize('managePayments', currentCompany());
        abort_unless($import->status === SquareImport::REVIEW, 409);

        $data = $request->validate([
            'decisions' => ['array'],
            'decisions.*' => [Rule::in(['merge', 'skip', 'new'])],
            'all' => ['nullable', Rule::in(['merge', 'skip', 'new'])],
        ]);

        $importer->decide($import, $data['decisions'] ?? [], $data['all'] ?? null);
        $importer->run($import);

        return to_route('square-import.show');
    }

    public function discard(SquareImport $import): RedirectResponse
    {
        Gate::authorize('managePayments', currentCompany());
        abort_unless($import->status === SquareImport::REVIEW, 409);

        $import->rows()->delete();
        $import->delete();

        return to_route('square-import.show');
    }

    public function undo(Request $request, SquareImport $import, SquareImporter $importer): RedirectResponse
    {
        Gate::authorize('managePayments', currentCompany());
        abort_unless($import->status === SquareImport::DONE, 409);

        $importer->undo($import, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('square_import.undone')]);

        return to_route('square-import.show');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(SquareImport $import): array
    {
        return [
            'id' => $import->id,
            'status' => $import->status,
            'step' => $import->step,
            'progress' => ['done' => $import->progress_done, 'total' => $import->progress_total],
            'counts' => $import->counts ?? [],
            'error' => $import->error,
            'by' => $import->user?->name,
            'created_at' => $import->created_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'undone_at' => $import->undone_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function matches(SquareImport $import): array
    {
        return $import->rows()->where('kind', 'match')->with('customer.primaryPhone')->orderBy('id')->limit(500)->get()
            ->map(fn (SquareImportCustomer $row) => [
                'id' => $row->id,
                'square' => [
                    'name' => trim(implode(' ', array_filter([$row->data['first_name'] ?? null, $row->data['last_name'] ?? null]))) ?: ($row->data['company_name'] ?? '—'),
                    'phone' => $row->data['phone'] ?? null,
                    'email' => $row->data['email'] ?? null,
                ],
                'customer' => ['id' => $row->customer?->id, 'name' => $row->customer?->display_name, 'phone' => $row->customer?->primaryPhone?->number],
                'matched_by' => $row->matched_by,
                'decision' => $row->decision,
            ])->values()->all();
    }
}
