<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BusinessExpense;
use App\Models\BusinessExpenseCategory;
use App\Models\Company;
use App\Models\User;
use App\Models\TaxRate;
use App\Support\PrivateMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2030-06-12 16:00:00');
    $this->company = Company::factory()->create(['currency' => 'CAD', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->forceCreate([
        'name' => 'Fuel', 'created_by' => $this->owner->id,
    ]));
    Storage::fake(PrivateMedia::diskName());
    $this->actingAs($this->owner);
});

function overheadPayload(array $overrides = []): array
{
    return array_replace([
        'category_id' => test()->category->id, 'spent_on' => '2030-06-12',
        'description' => 'Van fuel', 'merchant' => 'Fuel station', 'amount' => '80.25', 'tax_amount' => '9.63',
        'notes' => 'Company van.',
    ], $overrides);
}

function overheadRecord(Company $company, User $user, BusinessExpenseCategory $category, array $overrides = []): BusinessExpense
{
    return inCompany($company, fn () => BusinessExpense::query()->forceCreate(array_replace([
        'category_id' => $category->id, 'spent_on' => '2030-06-12', 'description' => 'Fuel',
        'amount' => 8000, 'tax_amount' => 960, 'currency' => $company->currency, 'created_by' => $user->id,
    ], $overrides)));
}

test('an expense saves price and tax separately with a private receipt and server-owned identity', function () {
    $this->post(route('expenses.store'), overheadPayload([
        'receipt' => UploadedFile::fake()->image('fuel.jpg'), 'currency' => 'USD', 'company_id' => 999,
        'created_by' => $this->tech->id, 'service_job_id' => 999,
    ]))->assertRedirect(route('expenses.index', ['from' => '2030-06-01', 'to' => '2030-06-30']))->assertSessionHasNoErrors();

    $expense = inCompany($this->company, fn () => BusinessExpense::query()->sole());
    expect($expense->amount)->toBe(8025)->and($expense->tax_amount)->toBe(963)
        ->and($expense->total())->toBe(8988)->and($expense->currency)->toBe('CAD')
        ->and($expense->company_id)->toBe($this->company->id)->and($expense->created_by)->toBe($this->owner->id)
        ->and($expense->receipt_path)->toStartWith('companies/'.$this->company->id.'/business-expenses/');
    Storage::disk(PrivateMedia::diskName())->assertExists($expense->receipt_path);
    $this->get(route('expenses.receipt', $expense))->assertOk();
    $this->get(route('expenses.edit', $expense))->assertInertia(fn (Assert $page) => $page
        ->where('expense.amount', 8025)->where('expense.tax_amount', 963)->where('expense.notes', 'Company van.'));
});

test('members can create categories inline and reuse names without creating duplicates', function () {
    $this->actingAs($this->tech)->post(route('expenses.store'), overheadPayload([
        'category_id' => null, 'new_category' => ' Lunches ', 'amount' => '20.00', 'tax_amount' => '1.00',
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('expenses.store'), overheadPayload(['category_id' => null, 'new_category' => 'lunches']))
        ->assertRedirect()->assertSessionHasNoErrors();
    $category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->where('normalized_name', 'lunches')->sole());
    expect($category->name)->toBe('Lunches')->and($category->created_by)->toBe($this->tech->id)
        ->and(inCompany($this->company, fn () => BusinessExpense::query()->where('category_id', $category->id)->count()))->toBe(2);
});

test('saving a backdated expense opens its month so it remains visible', function () {
    $this->post(route('expenses.store'), overheadPayload(['spent_on' => '2029-01-15']))
        ->assertRedirect(route('expenses.index', ['from' => '2029-01-01', 'to' => '2029-01-31']))->assertSessionHasNoErrors();
    $this->get(route('expenses.index', ['from' => '2029-01-01', 'to' => '2029-01-31']))
        ->assertInertia(fn (Assert $page) => $page->where('expenses.total', 1)->where('expenses.data.0.spent_on', '2029-01-15'));
});

test('categories can be renamed and archived without losing old expenses', function () {
    $this->actingAs($this->tech)->post(route('expense-categories.store'), ['name' => 'Tools'])->assertRedirect()->assertSessionHasNoErrors();
    $category = inCompany($this->company, fn () => BusinessExpenseCategory::query()->where('name', 'Tools')->sole());
    $expense = overheadRecord($this->company, $this->tech, $category);
    $this->put(route('expense-categories.update', $category), ['name' => 'Equipment', 'is_active' => false])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->where('expenses.data.0.category', 'Equipment')->where('categories.0.is_active', false));
    $this->post(route('expenses.store'), overheadPayload(['category_id' => $category->id]))->assertSessionHasErrors('category_id');
    $this->post(route('expenses.store'), overheadPayload(['category_id' => null, 'new_category' => 'equipment']))->assertSessionHasErrors('new_category');
    $this->put(route('expenses.update', $expense), overheadPayload(['category_id' => $category->id]))->assertSessionHasNoErrors();
    $this->put(route('expense-categories.update', $this->category), ['name' => 'Changed'])->assertForbidden();
    $this->actingAs($this->owner)->post(route('expense-categories.store'), ['name' => ' equipment '])->assertSessionHasErrors('name');
});

test('the index shows category figures across the full period without mixing currencies', function () {
    overheadRecord($this->company, $this->owner, $this->category, ['amount' => 8025, 'tax_amount' => 963]);
    overheadRecord($this->company, $this->tech, $this->category, ['amount' => 1000, 'tax_amount' => 50]);
    overheadRecord($this->company, $this->owner, $this->category, ['amount' => 2500, 'tax_amount' => 125, 'currency' => 'USD']);
    overheadRecord($this->company, $this->owner, $this->category, ['spent_on' => '2030-05-31', 'amount' => 99999]);
    $tools = inCompany($this->company, fn () => BusinessExpenseCategory::query()->create(['name' => 'Tools']));
    overheadRecord($this->company, $this->owner, $tools);

    $this->get(route('expenses.index', ['category' => $tools->id]))->assertInertia(fn (Assert $page) => $page
        ->component('expenses/index')->where('expenses.total', 1)
        ->where('categories.0.totals', [
            ['currency' => 'CAD', 'price' => 9025, 'tax' => 1013, 'total' => 10038],
            ['currency' => 'USD', 'price' => 2500, 'tax' => 125, 'total' => 2625],
        ])->missing('totals')->missing('totalExpenses'));
});

test('technicians only see their own entries totals exports and receipts', function () {
    $mine = overheadRecord($this->company, $this->tech, $this->category);
    $other = overheadRecord($this->company, $this->owner, $this->category, [
        'description' => 'Private office purchase', 'receipt_path' => 'office.jpg',
    ]);
    Storage::disk(PrivateMedia::diskName())->put('office.jpg', 'receipt');
    $this->actingAs($this->tech)->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->where('expenses.total', 1)->where('expenses.data.0.id', $mine->id)
        ->where('categories.0.totals.0.total', 8960)->where('auth.can.viewBusinessExpenses', true));
    $this->get(route('expenses.receipt', $other))->assertForbidden();
    $this->get(route('expenses.edit', $other))->assertForbidden();
    $this->put(route('expenses.update', $other), overheadPayload())->assertForbidden();
    $this->delete(route('expenses.destroy', $other))->assertForbidden();
    $csv = $this->get(route('expenses.download'))->assertOk()->streamedContent();
    expect($csv)->toContain('Fuel')->not->toContain('Private office purchase');
});

test('editing retains the historical currency and receipts until a replacement is uploaded', function () {
    $expense = overheadRecord($this->company, $this->owner, $this->category, [
        'receipt_path' => 'old.jpg', 'receipt_name' => 'old.jpg', 'receipt_mime' => 'image/jpeg',
    ]);
    Storage::disk(PrivateMedia::diskName())->put('old.jpg', 'old receipt');
    $this->company->update(['currency' => 'JPY']);
    $this->put(route('expenses.update', $expense), overheadPayload(['amount' => '15.25', 'tax_amount' => '0.75']))->assertSessionHasNoErrors();
    expect($expense->fresh()->currency)->toBe('CAD')->and($expense->fresh()->total())->toBe(1600)
        ->and($expense->fresh()->receipt_path)->toBe('old.jpg');
    $this->post(route('expenses.update', $expense), overheadPayload([
        '_method' => 'put', 'receipt' => UploadedFile::fake()->image('new.jpg'),
    ]))->assertRedirect()->assertSessionHasNoErrors();
    expect($expense->fresh()->receipt_path)->not->toBe('old.jpg');
    Storage::disk(PrivateMedia::diskName())->assertExists('old.jpg');
    Storage::disk(PrivateMedia::diskName())->assertExists($expense->fresh()->receipt_path);
    expect(inCompany($this->company, fn () => AuditLog::query()->where('action', 'business_expense.updated')->count()))->toBe(2);
});

test('removing an expense hides it from totals but retains its record and receipt', function () {
    $expense = overheadRecord($this->company, $this->owner, $this->category, ['receipt_path' => 'keep.jpg']);
    Storage::disk(PrivateMedia::diskName())->put('keep.jpg', 'receipt');
    $this->delete(route('expenses.destroy', $expense))->assertRedirect();
    $this->assertSoftDeleted($expense);
    Storage::disk(PrivateMedia::diskName())->assertExists('keep.jpg');
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->where('expenses.total', 0)->where('categories.0.totals', []));
    $this->get(route('expenses.receipt', $expense))->assertNotFound();
});

test('foreign companies cannot supply categories or access expenses receipts or exports', function () {
    $foreignCompany = Company::factory()->create();
    $foreignUser = memberOf($foreignCompany);
    $foreignCategory = inCompany($foreignCompany, fn () => BusinessExpenseCategory::query()->create(['name' => 'Fuel']));
    $foreign = overheadRecord($foreignCompany, $foreignUser, $foreignCategory, ['description' => 'Foreign purchase', 'receipt_path' => 'foreign.jpg']);
    overheadRecord($this->company, $this->owner, $this->category);

    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page->where('expenses.total', 1)->has('categories', 1));
    $this->post(route('expenses.store'), overheadPayload(['category_id' => $foreignCategory->id]))->assertSessionHasErrors('category_id');
    $this->get(route('expenses.edit', $foreign))->assertNotFound();
    $this->get(route('expenses.receipt', $foreign))->assertNotFound();
    $this->put(route('expenses.update', $foreign), overheadPayload())->assertNotFound();
    $this->delete(route('expenses.destroy', $foreign))->assertNotFound();
    $this->put(route('expense-categories.update', $foreignCategory), ['name' => 'Stolen'])->assertNotFound();
    expect($this->get(route('expenses.download'))->streamedContent())->not->toContain('Foreign purchase');
});

test('price and tax precision follow zero and three decimal currencies', function (string $currency, string $amount, string $tax, int $minor, int $minorTax) {
    $this->company->update(['currency' => $currency]);
    $this->post(route('expenses.store'), overheadPayload(['amount' => $amount, 'tax_amount' => $tax]))->assertSessionHasNoErrors();
    $expense = inCompany($this->company, fn () => BusinessExpense::query()->sole());
    expect($expense->amount)->toBe($minor)->and($expense->tax_amount)->toBe($minorTax)->and($expense->currency)->toBe($currency);
    $this->post(route('expenses.store'), overheadPayload(['amount' => $currency === 'JPY' ? '1.1' : '1.0001']))->assertSessionHasErrors('amount');
})->with([['JPY', '1500', '150', 1500, 150], ['KWD', '10.125', '0.375', 10125, 375]]);

test('invalid amounts dates categories and receipt files are rejected', function (array $overrides, string $field) {
    $this->post(route('expenses.store'), overheadPayload($overrides))->assertSessionHasErrors($field);
    expect(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(0);
})->with([
    [['amount' => '-1.00'], 'amount'], [['tax_amount' => '-0.01'], 'tax_amount'],
    [['amount' => '1.001'], 'amount'], [['spent_on' => '2030-02-30'], 'spent_on'],
    [['category_id' => null], 'category_id'], [['description' => '   '], 'description'],
]);

test('unsupported files and oversized receipts are rejected without storing data', function () {
    $this->post(route('expenses.store'), overheadPayload(['receipt' => UploadedFile::fake()->create('script.html', 1, 'text/html')]))->assertSessionHasErrors('receipt');
    $this->post(route('expenses.store'), overheadPayload(['receipt' => UploadedFile::fake()->create('large.pdf', 15361, 'application/pdf')]))->assertSessionHasErrors('receipt');
    expect(inCompany($this->company, fn () => BusinessExpense::query()->count()))->toBe(0);
    expect(Storage::disk(PrivateMedia::diskName())->allFiles())->toBe([]);
});

test('CSV exports dates price tax and currency and protects spreadsheet text', function () {
    overheadRecord($this->company, $this->owner, $this->category, ['description' => '=SUM(A1:A2)', 'amount' => 8025, 'tax_amount' => 963]);
    overheadRecord($this->company, $this->owner, $this->category, ['spent_on' => '2030-05-30', 'description' => 'Outside period']);
    $response = $this->get(route('expenses.download', ['from' => '2030-06-01', 'to' => '2030-06-12']));
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();
    expect($csv)->toContain('Price,Tax,Total,Currency')->toContain('80.25,9.63,89.88,CAD')
        ->toContain("'=SUM(A1:A2)")->not->toContain('Outside period');
});

test('period validation search and pagination do not truncate category figures', function () {
    for ($number = 0; $number < 30; $number++) {
        overheadRecord($this->company, $this->owner, $this->category, ['description' => 'Fuel '.$number, 'amount' => 100, 'tax_amount' => 10]);
    }
    $this->get(route('expenses.index'))->assertInertia(fn (Assert $page) => $page
        ->where('expenses.total', 30)->has('expenses.data', 25)->where('categories.0.totals.0.total', 3300));
    $this->get(route('expenses.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('expenses.data', 5)->where('categories.0.totals.0.total', 3300));
    $this->get(route('expenses.index', ['search' => 'Fuel 29']))->assertInertia(fn (Assert $page) => $page->where('expenses.total', 1));
    $this->get(route('expenses.index', ['from' => '2030-06-15', 'to' => '2030-06-01']))->assertSessionHasErrors('to');
    $this->get(route('expenses.download', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
});

test('reserved roles cannot access business expenses or categories', function (UserRole $role) {
    $user = memberOf($this->company, $role);
    $this->actingAs($user)->get(route('expenses.index'))->assertForbidden();
    $this->get(route('expenses.create'))->assertForbidden();
    $this->get(route('expenses.download'))->assertForbidden();
    $this->post(route('expenses.store'), overheadPayload())->assertForbidden();
    $this->post(route('expense-categories.store'), ['name' => 'Tools'])->assertForbidden();
})->with([UserRole::Collector, UserRole::Subcontractor]);

test('guest access redirects to login', function () {
    auth()->logout();
    $this->get(route('expenses.index'))->assertRedirect(route('login'));
    $this->post(route('expenses.store'), overheadPayload())->assertRedirect(route('login'));
});

test('named receipt taxes have no count limit and the server sums actual amounts', function () {
    $rates = TaxRate::factory()->count(7)->create(['company_id' => $this->company->id, 'rate' => 1]);
    $this->post(route('expenses.store'), overheadPayload([
        'use_named_taxes' => true, 'tax_rate_ids' => $rates->modelKeys(), 'tax_amount' => '999',
        'tax_amounts' => $rates->mapWithKeys(fn ($rate) => [$rate->id => '0.81'])->all(),
    ]))->assertSessionHasNoErrors();
    $expense = inCompany($this->company, fn () => BusinessExpense::query()->sole());
    expect($expense->taxes)->toHaveCount(7)->and($expense->tax_amount)->toBe(567)->and($expense->total())->toBe(8592);
    $rate = $rates->first();
    $saved = collect($expense->taxes)->firstWhere('tax_rate_id', $rate->id);
    $rate->update(['name' => 'New name', 'rate' => 25, 'is_active' => false]);
    $this->put(route('expenses.update', $expense), overheadPayload([
        'use_named_taxes' => true, 'tax_rate_ids' => [$rate->id], 'tax_amounts' => [$rate->id => '2.50'],
    ]))->assertSessionHasNoErrors();
    expect($expense->fresh()->tax_amount)->toBe(250)->and($expense->fresh()->taxes[0]['name'])->toBe($saved['name'])
        ->and($expense->fresh()->taxes[0]['rate'])->toBe($saved['rate']);
    $csv = $this->get(route('expenses.download'))->assertOk()->streamedContent();
    expect($csv)->toContain('Tax breakdown')->toContain($saved['name'].' ('.$saved['rate'].'%): 2.50');
    // Empty arrays are omitted by multipart forms. The explicit mode still clears all taxes.
    $this->put(route('expenses.update', $expense), overheadPayload(['use_named_taxes' => true]))->assertSessionHasNoErrors();
    expect($expense->fresh()->taxes)->toBe([])->and($expense->fresh()->tax_amount)->toBe(0);
});

test('receipt tax choices reject foreign disabled and invalid money values', function () {
    $foreign = TaxRate::factory()->create();
    $disabled = TaxRate::factory()->create(['company_id' => $this->company->id, 'is_active' => false]);
    foreach ([$foreign->id, $disabled->id] as $id) {
        $this->post(route('expenses.store'), overheadPayload(['use_named_taxes' => true, 'tax_rate_ids' => [$id], 'tax_amounts' => [$id => '1']]))
            ->assertSessionHasErrors('tax_rate_ids.0');
    }
    $rate = TaxRate::factory()->create(['company_id' => $this->company->id]);
    foreach (['-1', '1.123'] as $amount) {
        $this->post(route('expenses.store'), overheadPayload(['use_named_taxes' => true, 'tax_rate_ids' => [$rate->id], 'tax_amounts' => [$rate->id => $amount]]))
            ->assertSessionHasErrors('tax_amounts.'.$rate->id);
    }
});

test('company employee filters and summaries cover the whole period and export without mixing currencies', function () {
    for ($i = 0; $i < 26; $i++) {
        overheadRecord($this->company, $this->tech, $this->category, ['description' => 'Employee fuel', 'amount' => 100, 'tax_amount' => 5]);
    }
    overheadRecord($this->company, $this->tech, $this->category, ['amount' => 300, 'tax_amount' => 30, 'currency' => 'USD']);
    overheadRecord($this->company, $this->owner, $this->category, ['description' => 'Office fuel']);
    $this->get(route('expenses.index', ['employee' => $this->tech->id]))->assertInertia(fn (Assert $page) => $page
        ->where('companyView', true)->has('employees', 2)->where('filters.employee', (string) $this->tech->id)
        ->where('expenses.total', 27)->has('expenses.data', 25)->has('employeeTotals', 2)
        ->where('employeeTotals.0.id', $this->tech->id)->where('employeeTotals.0.currency', 'CAD')
        ->where('employeeTotals.0.price', 2600)->where('employeeTotals.0.tax', 130)->where('employeeTotals.0.total', 2730)
        ->where('employeeTotals.1.currency', 'USD')->where('employeeTotals.1.total', 330)
        ->where('categories.0.totals.0.total', 2730));
    $csv = $this->get(route('expenses.download', ['employee' => $this->tech->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('Employee fuel')->not->toContain('Office fuel');
    $this->actingAs($this->tech)->get(route('expenses.index', ['employee' => $this->owner->id]))->assertInertia(fn (Assert $page) => $page
        ->where('companyView', false)->where('employees', [])->where('employeeTotals', [])
        ->where('filters.employee', '')->where('expenses.total', 27));
    $foreign = memberOf();
    $this->actingAs($this->owner)->get(route('expenses.index', ['employee' => $foreign->id]))->assertSessionHasErrors('employee');
    $this->get(route('expenses.download', ['employee' => $foreign->id]))->assertSessionHasErrors('employee');
});
