<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Appliance;
use App\Models\BusinessExpense;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobCostItem;
use App\Models\Payment;
use App\Models\Property;
use App\Support\Jobs\JobPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deleted items: customers, addresses, appliances, estimates, invoices, payments and costs deleted in the last
 * 30 days, with Restore (like Deleted jobs). Nothing is removed for good: older items only leave the list.
 */
class TrashController extends Controller
{
    public const DAYS = 30;

    /** type => model class */
    private const TYPES = [
        'customer' => Customer::class,
        'property' => Property::class,
        'appliance' => Appliance::class,
        'estimate' => Estimate::class,
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        'expense' => BusinessExpense::class,
        'cost' => JobCostItem::class,
    ];

    public function index(Request $request): Response
    {
        $this->authorizeOffice($request);

        $items = collect(self::TYPES)
            ->flatMap(fn (string $class, string $type) => $this->trashed($class)
                ->limit(200)
                ->get()
                // Addresses and appliances deleted with their customer come back with it.
                ->reject(fn (Model $model) => $this->deletedWithParent($type, $model))
                ->map(fn (Model $model) => $this->present($type, $model)))
            ->sortByDesc('deleted_at')
            ->values();

        return Inertia::render('trash/index', [
            'days' => self::DAYS,
            'items' => $items,
        ]);
    }

    public function restore(Request $request, string $type, int $id): RedirectResponse
    {
        $this->authorizeOffice($request);
        abort_unless(isset(self::TYPES[$type]), 404);

        /** @var Model $model */
        $model = $this->trashed(self::TYPES[$type])->whereKey($id)->firstOrFail();

        DB::transaction(function () use ($model) {
            // An address or appliance comes back with the customer (and address) it belongs to.
            if ($model instanceof Appliance) {
                $property = Property::withTrashed()->find($model->property_id);
                $property?->customer()->withTrashed()->first()?->restore();
                $property?->restore();
            }

            if ($model instanceof Property) {
                $model->customer()->withTrashed()->first()?->restore();
            }

            $model->restore();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('trash.restored', ['name' => $this->label($type, $model)])]);

        return to_route('trash.index');
    }

    /**
     * @param  class-string<Model>  $class
     * @return Builder<Model>
     */
    private function trashed(string $class): Builder
    {
        return $class::onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('deleted_at');
    }

    private function deletedWithParent(string $type, Model $model): bool
    {
        return match ($type) {
            'property' => Customer::onlyTrashed()->whereKey($model->getAttribute('customer_id'))
                ->where('deleted_at', $model->getRawOriginal('deleted_at'))->exists(),
            'appliance' => Property::onlyTrashed()->whereKey($model->getAttribute('property_id'))
                ->where('deleted_at', $model->getRawOriginal('deleted_at'))->exists(),
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(string $type, Model $model): array
    {
        $detail = match ($type) {
            'property' => $model instanceof Property ? $model->fullAddress() : null,
            'appliance' => $model instanceof Appliance ? trim(implode(' ', array_filter([$model->type?->label(), $model->manufacturer, $model->model_number]))) : null,
            'estimate', 'invoice' => $model->getAttribute('customer_id') ? Customer::withTrashed()->find($model->getAttribute('customer_id'))?->display_name : null,
            default => null,
        };

        return [
            'key' => "{$type}-{$model->getKey()}",
            'type' => $type,
            'id' => $model->getKey(),
            'label' => $this->label($type, $model),
            'detail' => $detail,
            'deleted_at' => JobPresenter::iso($model->getAttribute('deleted_at')),
            'restorable_until' => JobPresenter::iso($model->getAttribute('deleted_at')?->copy()->addDays(self::DAYS)),
        ];
    }

    private function label(string $type, Model $model): string
    {
        return match ($type) {
            'property' => (string) Customer::withTrashed()->whereKey($model->getAttribute('customer_id'))->value('display_name'),
            'appliance' => $model instanceof Appliance ? (string) $model->type?->label() : '',
            default => method_exists($model, 'auditLabel') ? $model->auditLabel() : (string) ($model->getAttribute('description') ?: '#'.$model->getKey()),
        };
    }

    private function authorizeOffice(Request $request): void
    {
        abort_unless($request->user()?->hasRole(UserRole::Owner, UserRole::Admin), 403);
    }
}
