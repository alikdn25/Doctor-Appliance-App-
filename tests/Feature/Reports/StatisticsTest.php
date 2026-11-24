<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCostItem;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Support\Statistics\Statistics;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Statistics (owner's task of November 24): the calculations, the period, the Owner-only access, deleted jobs left out.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company);
    $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $this->customer = Customer::factory()->for($this->company)->create(['lead_source' => 'google_ads']);
    $this->property = Property::factory()->for($this->customer)->create(['city' => 'Burnaby']);
    $this->actingAs($this->owner);

    // A job completed on a local date, with an invoice (net = total − tax) and a payment.
    $this->job = function (string $completed, array $attributes = [], ?int $total = null, int $tax = 0, ?string $method = null, ?string $paidOn = null) {
        return inCompany($this->company, function () use ($completed, $attributes, $total, $tax, $method, $paidOn) {
            $job = ServiceJob::factory()->for($this->property)->create(['brand_id' => $this->brand->id]);
            $job->forceFill([
                'status' => JobStatus::Completed,
                'completed_at' => CarbonImmutable::parse("{$completed} 10:00", 'America/Vancouver')->utc(),
                ...$attributes,
            ])->save();

            if ($total !== null) {
                $invoice = Invoice::factory()->create([
                    'service_job_id' => $job->id, 'currency' => 'CAD', 'issued_on' => $completed,
                    'subtotal' => $total - $tax, 'tax_total' => $tax, 'total' => $total,
                    'taxes' => $tax > 0 ? [['tax_rate_id' => null, 'name' => 'GST', 'rate' => '5', 'amount' => $tax]] : [],
                    'amount_paid' => $method ? $total : 0, 'balance' => $method ? 0 : $total,
                    'status' => $method ? 'paid' : 'unpaid',
                ]);

                if ($method !== null) {
                    $payment = new Payment(['amount' => $total, 'method' => $method, 'received_at' => CarbonImmutable::parse(($paidOn ?? $completed).' 11:00', 'America/Vancouver')->utc()]);
                    $payment->invoice_id = $invoice->id;
                    $payment->currency = 'CAD';
                    $payment->save();
                }
            }

            return $job;
        });
    };
});

function octoberStats(Company $company): array
{
    return inCompany($company, fn () => Statistics::for(
        CarbonImmutable::parse('2026-10-01', 'America/Vancouver'),
        CarbonImmutable::parse('2026-10-31', 'America/Vancouver'),
    ));
}

test('jobs per day: weekdays, weekend and the whole week, over days with a completed job', function () {
    ($this->job)('2026-10-05'); // Monday
    ($this->job)('2026-10-05');
    ($this->job)('2026-10-06'); // Tuesday
    ($this->job)('2026-10-10'); // Saturday
    ($this->job)('2026-11-02'); // outside the period

    $stats = octoberStats($this->company);

    expect($stats['perDay'])->toBe(['total' => 4, 'weekday' => 1.5, 'weekend' => 1.0, 'week' => 1.3, 'days' => 3])
        ->and(collect($stats['weekdays'])->pluck('jobs')->all())->toBe([2, 1, 0, 0, 0, 1, 0]);
});

test('money by payment method with the total and the tax in it; voided and deleted payments are left out', function () {
    ($this->job)('2026-10-05', [], 21000, 1000, 'bank_transfer');
    ($this->job)('2026-10-06', [], 10000, 0, 'cash');
    ($this->job)('2026-10-07', [], 30000, 0, 'card_reader');
    ($this->job)('2026-10-08', [], 5000, 0, 'card_terminal');
    // Paid in November: not in October's money.
    ($this->job)('2026-10-09', [], 7000, 0, 'cash', '2026-11-02');
    $voided = ($this->job)('2026-10-09', [], 4000, 0, 'cash');
    inCompany($this->company, fn () => Payment::query()->whereHas('invoice', fn ($q) => $q->where('service_job_id', $voided->id))->update(['voided_at' => now()]));
    $deleted = ($this->job)('2026-10-09', [], 9999, 0, 'cash');
    $this->delete(route('jobs.destroy', $deleted))->assertRedirect();

    $money = octoberStats($this->company)['money'];

    expect($money['methods'])->toBe([
        ['key' => 'e_transfer', 'amount' => 21000],
        ['key' => 'card', 'amount' => 35000],
        ['key' => 'cash', 'amount' => 10000],
    ])
        ->and($money['total'])->toBe(66000)
        ->and($money['taxes'])->toBe([['name' => 'GST', 'amount' => 1000]]);
});

test('average ticket overall and by appliance type, without taxes', function () {
    ($this->job)('2026-10-05', ['appliance_category' => 'washer'], 21000, 1000, 'cash');
    ($this->job)('2026-10-06', ['appliance_category' => 'washer'], 30000, 0, 'cash');
    ($this->job)('2026-10-07', ['appliance_category' => 'dryer', 'only_diagnostic' => true], 10000);
    ($this->job)('2026-10-08', ['appliance_category' => 'dryer']); // no invoice: not in the ticket

    $stats = octoberStats($this->company);

    expect($stats['averageTicket']['average'])->toBe(20000)
        ->and($stats['averageTicket']['jobs'])->toBe(3)
        ->and(collect($stats['averageTicket']['byAppliance'])->map(fn ($r) => [$r['key'], $r['average'], $r['jobs']])->all())
        ->toBe([['washer', 25000, 2], ['dryer', 10000, 1]])
        // Repairs by appliance leave the diagnostic-only job out.
        ->and(collect($stats['appliances'])->map(fn ($r) => [$r['key'], $r['count']])->all())->toBe([['washer', 2], ['dryer', 1]])
        ->and($stats['diagnostic'])->toBe(['diagnostic' => 1, 'repair' => 3, 'diagnosticRate' => 25.0, 'repairRate' => 75.0])
        ->and($stats['cities'][0])->toBe(['name' => 'Burnaby', 'jobs' => 4, 'average' => 20000])
        // Older "Google Ads" counts as Google.
        ->and($stats['sources'][0]['key'])->toBe('google');
});

test('profit per job = invoiced without taxes − parts tied to the job; average and total', function () {
    $a = ($this->job)('2026-10-05', [], 21000, 1000, 'cash');
    ($this->job)('2026-10-06', [], 10000, 0, 'cash');
    inCompany($this->company, function () use ($a) {
        $cost = new JobCostItem(['description' => 'Pump', 'unit_cost' => 5000, 'quantity' => 1]);
        $cost->service_job_id = $a->id;
        $cost->currency = 'CAD';
        $cost->save();
    });

    expect(octoberStats($this->company)['profit'])->toBe(['total' => 25000, 'average' => 12500, 'jobs' => 2]);
});

test('revenue by month: 12 bars with the change against the month before', function () {
    ($this->job)('2026-09-10', [], 10000, 0, 'cash');
    ($this->job)('2026-10-05', [], 15000, 0, 'cash');

    $months = octoberStats($this->company)['months'];

    expect($months)->toHaveCount(12)
        ->and(end($months))->toBe(['month' => '2026-10-01', 'amount' => 15000, 'change' => 50.0])
        ->and($months[10])->toBe(['month' => '2026-09-01', 'amount' => 10000, 'change' => null]);
});

test('the page is the Owner\'s, picks the period, and the money exports as CSV', function () {
    ($this->job)('2026-10-05', [], 21000, 1000, 'bank_transfer');

    $this->get(route('statistics.index', ['period' => 'this_month']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('statistics/index')
            ->where('period', 'this_month')
            ->where('from', '2026-10-01')
            ->where('to', '2026-10-31')
            ->where('stats.money.total', 21000)
            ->has('reports.profit'));
    $this->get(route('statistics.index', ['period' => 'last_month']))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-09-01')->where('to', '2026-09-30')->where('stats.money.total', 0));
    $this->get(route('statistics.index', ['period' => 'this_week']))
        ->assertInertia(fn (Assert $page) => $page->where('from', '2026-10-19')->where('to', '2026-10-25'));

    $csv = $this->get(route('statistics.export', ['period' => 'this_month']))->assertOk()->streamedContent();
    expect($csv)->toContain('Date,Invoice,Method,Amount,GST,Currency')
        ->and($csv)->toContain('E-transfer,210.00,10.00,CAD');

    $this->actingAs(memberOf($this->company, UserRole::Technician))->get(route('statistics.index'))->assertForbidden();
    $this->actingAs(memberOf($this->company, UserRole::Admin))->get(route('statistics.export'))->assertForbidden();
});

test('another company\'s jobs and payments are not counted', function () {
    ($this->job)('2026-10-05', [], 21000, 1000, 'cash');
    $other = Company::factory()->create();

    $stats = octoberStats($other);

    expect($stats['perDay']['total'])->toBe(0)
        ->and($stats['money']['total'])->toBe(0);
});
