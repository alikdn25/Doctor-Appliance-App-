<?php

use App\Enums\UserRole;
use App\Models\BackupRun;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\JobStatusChange;
use App\Models\Message;
use App\Models\ProblemReport;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Services\AI\AiResponse;
use App\Services\AI\Problems\ProblemCollector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Fakes\FakeAiTransport;

/**
 * Find problems: every check is collected by code inside the company, technical checks of the platform only for a
 * platform administrator, one request to the assistant model, links only to found records, Owner only, never on a
 * schedule.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'parts_stale_days' => 7, 'estimate_stale_days' => 3]);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician, ['name' => 'Tim']);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->property = Property::factory()->for($this->customer)->create();
    config(['ai.models.assistant' => 'claude-sonnet-5-5', 'ai.daily_limit' => 100]);
    $this->actingAs($this->owner);
    $this->conversation = (string) Str::uuid();
});

function collectFor($user): array
{
    return inCompany(test()->company, fn () => app(ProblemCollector::class)->collect($user));
}

function check(array $findings, string $name): ?array
{
    return collect([...$findings['business'], ...$findings['technical']])->firstWhere('check', $name);
}

test('each business check is collected', function () {
    inCompany($this->company, function () {
        $job = fn (array $a = []) => ServiceJob::factory()->for($this->property)->create($a);

        Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $job()->id, 'status' => 'unpaid', 'total' => 5000, 'balance' => 5000, 'due_on' => '2026-11-01', 'number' => 'INV-OLD']);
        Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $job()->id, 'status' => 'unpaid', 'total' => 5000, 'balance' => 5000, 'due_on' => '2026-11-30', 'number' => 'INV-NOTDUE']);

        $parts = $job(['status' => 'waiting_for_parts']);
        JobStatusChange::create(['service_job_id' => $parts->id, 'from_status' => 'in_progress', 'to_status' => 'waiting_for_parts'])->forceFill(['created_at' => now()->subDays(10)])->save();
        $fresh = $job(['status' => 'need_to_order_parts']);
        JobStatusChange::create(['service_job_id' => $fresh->id, 'from_status' => 'in_progress', 'to_status' => 'need_to_order_parts'])->forceFill(['created_at' => now()->subDays(2)])->save();

        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $job()->id, 'status' => 'draft', 'sent_at' => now()->subDays(5), 'number' => 'EST-OLD']);
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $job()->id, 'status' => 'draft', 'sent_at' => now()->subDay(), 'number' => 'EST-NEW']);

        // A visit that ended yesterday on a job still open.
        ServiceJob::factory()->for($this->property)->withVisit($this->tech, [
            'scheduled_start' => now()->subDay()->subHours(2), 'scheduled_end' => now()->subDay(),
        ])->create(['status' => 'scheduled', 'number' => 5555]);

        // Two visits of Tim at the same time tomorrow.
        foreach ([1, 2] as $n) {
            ServiceJob::factory()->for($this->property)->withVisit($this->tech, [
                'scheduled_start' => now()->addDay()->setTime(17, 0)->utc(), 'scheduled_end' => now()->addDay()->setTime(19, 0)->utc(),
            ])->create(['status' => 'scheduled']);
        }

        // A customer without a phone and address; a duplicate by phone.
        Customer::factory()->for($this->company)->create(['first_name' => 'Nophone']);
        Property::factory()->for(Customer::factory()->for($this->company)->withPhone('604 555 0142')->create(['first_name' => 'Janet']))->create();
    });

    $findings = collectFor($this->owner);

    expect(array_column(check($findings, 'overdue_invoices')['items'], 'label'))->toHaveCount(1)
        ->and(check($findings, 'overdue_invoices')['items'][0]['label'])->toContain('INV-OLD')
        ->and(check($findings, 'parts_waiting')['count'])->toBe(1)
        ->and(check($findings, 'estimates_waiting')['items'][0]['label'])->toContain('EST-OLD')
        ->and(check($findings, 'estimates_waiting')['count'])->toBe(1)
        ->and(check($findings, 'unclosed_past_jobs')['items'][0]['label'])->toContain('5555')
        ->and(check($findings, 'schedule_overlaps')['items'][0]['label'])->toContain('Tim')
        ->and(collect(check($findings, 'customers_missing_info')['items'])->pluck('label')->implode(' '))->toContain('Nophone')
        ->and(check($findings, 'duplicate_customers')['items'][0]['detail'])->toContain('+16045550142');
});

test('the limits come from the company settings', function () {
    $this->company->update(['parts_stale_days' => 1, 'estimate_stale_days' => 10]);
    inCompany($this->company, function () {
        $job = ServiceJob::factory()->for($this->property)->create(['status' => 'need_to_order_parts']);
        JobStatusChange::create(['service_job_id' => $job->id, 'from_status' => 'in_progress', 'to_status' => 'need_to_order_parts'])->forceFill(['created_at' => now()->subDays(2)])->save();
        Estimate::factory()->create(['currency' => 'CAD', 'service_job_id' => $job->id, 'status' => 'draft', 'sent_at' => now()->subDays(5)]);
    });

    $findings = collectFor($this->owner);
    expect(check($findings, 'parts_waiting')['count'])->toBe(1)->and(check($findings, 'estimates_waiting'))->toBeNull();
});

test('technical checks: texts not sent for the company; log, queue and backups for the platform admin only', function () {
    inCompany($this->company, fn () => Message::create(['customer_id' => $this->customer->id, 'direction' => 'outbound', 'channel' => 'sms', 'kind' => 'general', 'to' => '+16045550142', 'body' => 'x', 'status' => 'failed', 'status_reason' => 'Carrier rejected']));
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Jobs\\DeliverSmsMessage']), 'exception' => "RuntimeException: Boom\n#0 trace", 'failed_at' => now()->subDay()]);
    BackupRun::create(['kind' => 'database', 'reason' => 'daily', 'status' => 'failed', 'error' => 'Disk full', 'started_at' => now()->subDay()]);
    $log = storage_path('logs/problems-test.log');
    file_put_contents($log, implode("\n", [
        '['.now()->subHour()->format('Y-m-d H:i:s').'] testing.ERROR: Undefined array key 12 {"exception":"..."}',
        '['.now()->subHours(2)->format('Y-m-d H:i:s').'] testing.ERROR: Undefined array key 7 {"exception":"..."}',
        '['.now()->subHours(3)->format('Y-m-d H:i:s').'] testing.WARNING: Square API error (square.refund) {"status":500}',
        '['.now()->subDays(20)->format('Y-m-d H:i:s').'] testing.ERROR: Old error',
    ])."\n");

    try {
        $owner = collectFor($this->owner);
        expect(check($owner, 'sms_not_sent')['items'][0]['label'])->toContain('Carrier rejected')
            ->and(check($owner, 'log_errors'))->toBeNull()
            ->and(check($owner, 'failed_queue_jobs'))->toBeNull()
            ->and(check($owner, 'failed_backups'))->toBeNull();

        $admin = memberOf($this->company, UserRole::Owner, ['is_super_admin' => true]);
        $platform = collectFor($admin);
        $log = collect(check($platform, 'log_errors')['items']);
        expect($log->firstWhere('label', 'Undefined array key N ')['detail'] ?? $log->first()['detail'])->toStartWith('2 times')
            ->and($log->pluck('label')->implode(' '))->not->toContain('Old error')
            ->and(collect(check($platform, 'square_errors')['items'])->pluck('label')->implode(' '))->toContain('Square API error')
            ->and(check($platform, 'failed_queue_jobs')['items'][0]['label'])->toContain('DeliverSmsMessage')->toContain('Boom')
            ->and(check($platform, 'failed_backups')['items'][0]['detail'])->toContain('Disk full');
    } finally {
        @unlink(storage_path('logs/problems-test.log'));
    }
});

test('another company\'s data never shows', function () {
    $other = Company::factory()->create();
    $otherCustomer = Customer::factory()->for($other)->create(['first_name' => 'Secret']);
    $otherJob = ServiceJob::factory()->for(Property::factory()->for($otherCustomer))->create();
    inCompany($other, fn () => Invoice::factory()->create(['currency' => 'CAD', 'service_job_id' => $otherJob->id, 'status' => 'unpaid', 'balance' => 9999, 'total' => 9999, 'due_on' => '2026-01-01', 'number' => 'INV-SECRET']));

    $findings = collectFor($this->owner);

    expect(json_encode($findings))->not->toContain('INV-SECRET')->not->toContain('Secret');
});

test('one request to the assistant model; links only to found records', function () {
    $invoice = inCompany($this->company, fn () => Invoice::factory()->create(['currency' => 'CAD',
        'service_job_id' => ServiceJob::factory()->for($this->property)->create()->id, 'status' => 'unpaid', 'total' => 5000, 'balance' => 5000, 'due_on' => '2026-11-01',
    ]));
    $fake = FakeAiTransport::install(fn (array $r) => [
        'summary' => '1 problem.',
        'problems' => [[
            'severity' => 'high', 'kind' => 'business', 'title' => 'Collect the overdue invoice', 'why' => 'Owed 15 days.', 'action' => 'Call Jane.',
            'links' => [['type' => 'invoice', 'id' => $invoice->id], ['type' => 'invoice', 'id' => 999999]], 'claude_task' => 'ignored for business',
        ]],
    ]);

    $this->postJson(route('assistant.find-problems'), ['conversation' => $this->conversation])
        ->assertOk()
        ->assertJsonPath('items.0.kind', 'problems')
        ->assertJsonPath('items.0.problems.0.title', 'Collect the overdue invoice')
        ->assertJsonCount(1, 'items.0.problems.0.links')
        ->assertJsonPath('items.0.problems.0.links.0.url', route('invoices.show', $invoice->id, false))
        ->assertJsonPath('items.0.problems.0.claude_task', null);

    expect($fake->requests)->toHaveCount(1)
        ->and($fake->requests[0]['model'])->toBe('claude-sonnet-5-5')
        ->and($fake->requests[0]['messages'][0]['content'])->toContain('overdue_invoices');
});

test('technical problems carry a task for Claude Code', function () {
    $admin = memberOf($this->company, UserRole::Owner, ['is_super_admin' => true]);
    BackupRun::create(['kind' => 'database', 'reason' => 'daily', 'status' => 'failed', 'error' => 'Disk full', 'started_at' => now()->subDay()]);
    FakeAiTransport::install(fn () => ['summary' => 'Backups fail.', 'problems' => [[
        'severity' => 'high', 'kind' => 'technical', 'title' => 'Backups fail', 'why' => 'Disk full.', 'action' => 'Free space.', 'links' => [],
        'claude_task' => "Fix the daily database backup: it fails with 'Disk full'. Add a test.",
    ]]]);

    $this->actingAs($admin)->postJson(route('assistant.find-problems'), ['conversation' => $this->conversation])
        ->assertOk()
        ->assertJsonPath('items.0.problems.0.claude_task', "Fix the daily database backup: it fails with 'Disk full'. Add a test.");
});

test('with nothing found it says so and asks no AI', function () {
    $fake = FakeAiTransport::install('never');

    $this->postJson(route('assistant.find-problems'), ['conversation' => $this->conversation])
        ->assertOk()
        ->assertJsonPath('items.0.summary', __('assistant.problems.none'))
        ->assertJsonCount(0, 'items.0.problems');

    expect($fake->requests)->toBe([]);
});

test('asking the assistant what needs fixing runs the same check', function () {
    inCompany($this->company, fn () => Customer::factory()->for($this->company)->create(['first_name' => 'Nophone']));
    $fake = FakeAiTransport::install(function (array $r) {
        if ($r['schema'] !== null) {
            return ['summary' => 'Fix customer data.', 'problems' => []];
        }
        $last = end($r['messages']);

        return ($last['content'][0]['type'] ?? null) === 'tool_result'
            ? new AiResponse([['type' => 'text', 'text' => 'Customer data needs fixing.']], 'end_turn')
            : new AiResponse([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'find_problems', 'input' => []]], 'tool_use');
    });

    $this->postJson(route('assistant.ask'), ['question' => 'Что надо исправить?', 'conversation' => $this->conversation])
        ->assertOk()
        ->assertJsonPath('items.1.kind', 'problems');

    expect(ProblemReport::withoutCompanyScope()->count())->toBe(1)
        ->and(collect($fake->requests)->pluck('model')->unique()->all())->toBe(['claude-sonnet-5-5']);
});

test('only the Owner can run it', function () {
    FakeAiTransport::install('x');
    foreach ([UserRole::Admin, UserRole::Technician] as $role) {
        $this->actingAs(memberOf($this->company, $role))->postJson(route('assistant.find-problems'), ['conversation' => (string) Str::uuid()])->assertForbidden();
    }
});

test('it never runs on a schedule', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => (string) ($e->command ?? $e->description ?? ''))->implode("\n");

    expect($events)->not->toContain('problem')->not->toContain('Problem')->not->toContain('assistant');
});
