<?php

use App\Enums\UserRole;
use App\Models\AssistantAction;
use App\Models\AssistantMessage;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobVisit;
use App\Models\Message;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Services\AI\AiResponse;
use App\Services\AI\Assistant\AssistantTools;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\FakeAiTransport;

/**
 * The AI assistant: Owner only, always on AI_MODEL_ASSISTANT, read-only tools inside the user's company, actions only
 * after Confirm and written to the change log as "AI assistant".
 */
beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['sms_mode' => 'automatic', 'timezone' => 'America/Vancouver']);
    $this->owner = memberOf($this->company);
    $this->customer = Customer::factory()->for($this->company)->withPhone('604-555-0142')->create(['first_name' => 'Jane', 'last_name' => 'Cooper']);
    $this->job = ServiceJob::factory()->for(Property::factory()->for($this->customer))->withVisit($this->owner, [
        'scheduled_start' => CarbonImmutable::parse('2026-11-17 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-11-17 11:00', 'America/Vancouver')->utc(),
    ])->create();
    $this->invoice = inCompany($this->company, fn () => Invoice::factory()->create([
        'service_job_id' => $this->job->id, 'currency' => 'CAD', 'total' => 25000, 'balance' => 25000, 'status' => 'unpaid',
        'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'number' => 'INV-77',
    ]));

    // Another company with its own customer, job and invoice.
    $this->other = Company::factory()->create();
    $otherOwner = memberOf($this->other);
    $this->otherCustomer = Customer::factory()->for($this->other)->create(['first_name' => 'Secret', 'last_name' => 'Person']);
    $this->otherJob = ServiceJob::factory()->for(Property::factory()->for($this->otherCustomer))->withVisit($otherOwner, [
        'scheduled_start' => CarbonImmutable::parse('2026-11-17 09:00', 'America/Vancouver')->utc(),
        'scheduled_end' => CarbonImmutable::parse('2026-11-17 11:00', 'America/Vancouver')->utc(),
    ])->create();
    $this->otherInvoice = inCompany($this->other, fn () => Invoice::factory()->create([
        'service_job_id' => $this->otherJob->id, 'currency' => 'CAD', 'total' => 99900, 'balance' => 99900, 'status' => 'unpaid',
        'issued_on' => '2026-10-01', 'due_on' => '2026-10-31', 'number' => 'INV-OTHER',
    ]));

    config(['ai.models.assistant' => 'claude-sonnet-5-5', 'ai.daily_limit' => 100]);
    $this->actingAs($this->owner);
    $this->conversation = (string) Str::uuid();
});

/** Runs one tool as the owner, in the owner's company. */
function tool(string $name, array $input = []): array
{
    return inCompany(test()->company, fn () => json_decode(app(AssistantTools::class)->run($name, $input, test()->owner, test()->conversation), true));
}

test('only the Owner can use the assistant', function () {
    FakeAiTransport::install('Hi');
    $this->get(route('assistant.index'))->assertOk();

    foreach ([UserRole::Admin, UserRole::Technician] as $role) {
        $user = memberOf($this->company, $role);
        $this->actingAs($user)->get(route('assistant.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('assistant.ask'), ['question' => 'x', 'conversation' => $this->conversation])->assertForbidden();
    }
});

test('the assistant always uses the assistant model and runs tools until it answers', function () {
    $fake = FakeAiTransport::install(function (array $request) {
        $last = end($request['messages']);

        return ($last['content'][0]['type'] ?? null) === 'tool_result'
            ? new AiResponse([['type' => 'text', 'text' => 'Jane Cooper owes $250.00 on INV-77.']], 'end_turn', ['input_tokens' => 10, 'output_tokens' => 5])
            : new AiResponse([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'unpaid_invoices', 'input' => []]], 'tool_use', ['input_tokens' => 10, 'output_tokens' => 5]);
    });

    $this->postJson(route('assistant.ask'), ['question' => 'Who owes us?', 'conversation' => $this->conversation])
        ->assertOk()
        ->assertJsonPath('items.0.text', 'Who owes us?')
        ->assertJsonPath('items.1.text', 'Jane Cooper owes $250.00 on INV-77.');

    expect(array_unique(array_column($fake->requests, 'model')))->toBe(['claude-sonnet-5-5'])
        ->and($fake->requests[0]['tools'])->toEqual(AssistantTools::definitions());

    // The tool result went back to the AI: this company's invoice only.
    $result = json_decode(end($fake->requests[1]['messages'])['content'][0]['content'], true);
    expect(array_column($result['invoices'], 'number'))->toBe(['INV-77'])
        ->and($result['invoices'][0]['days_overdue'])->toBe(16);
});

test('the conversation is only appended to: earlier messages go back unchanged', function () {
    $fake = FakeAiTransport::install(fn () => new AiResponse([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'], ['type' => 'text', 'text' => 'Answer']], 'end_turn'));

    $this->postJson(route('assistant.ask'), ['question' => 'One', 'conversation' => $this->conversation])->assertOk();
    $this->postJson(route('assistant.ask'), ['question' => 'Two', 'conversation' => $this->conversation])->assertOk();

    $first = $fake->requests[0];
    $second = $fake->requests[1];
    expect(array_slice($second['messages'], 0, 2))->toBe([...$first['messages'], ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-1'], ['type' => 'text', 'text' => 'Answer']]]])
        ->and($second['system'])->toBe($first['system'])
        ->and($second['tools'])->toEqual($first['tools']);
});

test('tools never see another company', function () {
    expect(tool('find_customers', ['query' => 'Secret'])['count'])->toBe(0)
        ->and(tool('customer_history', ['customer_id' => $this->otherCustomer->id]))->toHaveKey('error')
        ->and(array_column(tool('unpaid_invoices')['invoices'], 'number'))->toBe(['INV-77'])
        ->and(array_column(tool('jobs_on_dates', ['from' => '2026-11-17', 'to' => '2026-11-17'])['visits'], 'job_id'))->toBe([$this->job->id])
        ->and(tool('revenue', ['from' => '2026-10-01', 'to' => '2026-10-31'])['invoices_issued']['CAD']['count'])->toBe(1)
        ->and(tool('propose_payment_reminder', ['invoice_id' => $this->otherInvoice->id]))->toHaveKey('error')
        ->and(tool('propose_reschedule', ['visit_id' => $this->otherJob->visits()->withoutGlobalScopes()->first()->id, 'date' => '2026-11-18', 'start_time' => '13:00']))->toHaveKey('error');

    expect(tool('find_customers', ['query' => 'Cooper'])['customers'][0]['customer_id'])->toBe($this->customer->id)
        ->and(tool('customer_history', ['customer_id' => $this->customer->id])['invoices'][0]['number'])->toBe('INV-77');
});

test('jobs by status include the new Need to order parts group', function () {
    inCompany($this->company, fn () => $this->job->forceFill(['status' => 'need_to_order_parts'])->save());

    expect(array_column(tool('jobs_by_status', ['status' => 'need_to_order_parts'])['jobs'], 'job_id'))->toBe([$this->job->id])
        ->and(tool('jobs_by_status', ['status' => 'waiting_for_parts'])['count'])->toBe(0);
});

test('a payment reminder is only proposed: nothing is sent until Confirm', function () {
    $proposal = tool('propose_payment_reminder', ['invoice_id' => $this->invoice->id]);
    expect($proposal['proposed'])->toBeTrue();

    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    expect($action->status)->toBe('pending')
        ->and(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);

    $this->postJson(route('assistant.actions.confirm', $action))->assertOk()->assertJsonPath('action.status', 'confirmed');

    $message = inCompany($this->company, fn () => Message::query()->sole());
    expect($message->customer_id)->toBe($this->customer->id)->and($message->kind->value)->toBe('invoice_link');

    $log = AuditLog::query()->where('action', 'assistant.payment_reminder')->sole();
    expect($log->source)->toBe('ai_assistant')->and($log->company_id)->toBe($this->company->id)->and($log->user_id)->toBe($this->owner->id);

    // Confirmed once only.
    $this->postJson(route('assistant.actions.confirm', $action))->assertStatus(409);
    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(1);
});

test('Cancel drops the action and nothing changes', function () {
    tool('propose_reschedule', ['visit_id' => inCompany($this->company, fn () => $this->job->visits()->first()->id), 'date' => '2026-11-18', 'start_time' => '13:00']);
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    $before = inCompany($this->company, fn () => JobVisit::query()->sole()->scheduled_start->toIso8601String());

    $this->postJson(route('assistant.actions.cancel', $action))->assertOk()->assertJsonPath('action.status', 'cancelled');
    $this->postJson(route('assistant.actions.confirm', $action))->assertStatus(409);

    expect(inCompany($this->company, fn () => JobVisit::query()->sole()->scheduled_start->toIso8601String()))->toBe($before);
});

test('a confirmed move changes the visit and is logged as the AI assistant', function () {
    tool('propose_reschedule', ['visit_id' => inCompany($this->company, fn () => $this->job->visits()->first()->id), 'date' => '2026-11-18', 'start_time' => '13:00']);
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $this->postJson(route('assistant.actions.confirm', $action))->assertOk()->assertJsonPath('action.status', 'confirmed');

    $visit = inCompany($this->company, fn () => JobVisit::query()->sole());
    expect($visit->scheduled_start->setTimezone('America/Vancouver')->format('Y-m-d H:i'))->toBe('2026-11-18 13:00')
        ->and($visit->scheduled_end->setTimezone('America/Vancouver')->format('H:i'))->toBe('15:00');
    expect(AuditLog::query()->where('action', 'assistant.reschedule')->sole()->source)->toBe('ai_assistant');
});

test('a booking goes through the Quick booking parser and opens the form, saving nothing', function () {
    config(['ai.models.booking' => 'claude-haiku-5-5']);
    $fake = FakeAiTransport::install(fn (array $r) => $r['schema'] === null ? 'ok' : [
        'first_name' => 'Jane', 'last_name' => 'Cooper', 'phone' => '604-555-0142', 'email' => null, 'address' => null, 'unit' => null,
        'buzzer' => null, 'appliance_type' => 'dryer', 'brand' => null, 'model' => null, 'problem' => 'No heat', 'date' => null,
        'arrival_now' => false, 'arrival_start' => null, 'arrival_end' => null, 'notes' => null,
    ]);
    tool('propose_booking', ['text' => 'Jane Cooper 604 555 0142 dryer no heat']);
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());
    $jobs = ServiceJob::withoutCompanyScope()->count();

    $this->postJson(route('assistant.actions.confirm', $action))
        ->assertOk()
        ->assertJsonPath('booking.fields.problem', 'No heat')
        ->assertJsonPath('booking.customer.id', $this->customer->id);

    expect($fake->requests[0]['model'])->toBe('claude-haiku-5-5')
        ->and(ServiceJob::withoutCompanyScope()->count())->toBe($jobs);
});

test('another person\'s or another company\'s action cannot be confirmed', function () {
    tool('propose_payment_reminder', ['invoice_id' => $this->invoice->id]);
    $action = inCompany($this->company, fn () => AssistantAction::query()->sole());

    $secondOwner = memberOf($this->company);
    $outsider = memberOf($this->other);
    $this->actingAs($secondOwner)->postJson(route('assistant.actions.confirm', $action))->assertForbidden();

    $this->actingAs($outsider)->postJson(route('assistant.actions.confirm', $action))->assertNotFound();

    expect(inCompany($this->company, fn () => Message::query()->count()))->toBe(0);
});

test('the assistant has no tool that deletes anything', function () {
    $names = array_column(AssistantTools::definitions(), 'name');

    expect(collect($names)->filter(fn ($n) => preg_match('/delete|remove|destroy|void|cancel/i', $n))->all())->toBe([]);
});

test('another person\'s conversation id is refused', function () {
    FakeAiTransport::install('Hi');
    inCompany($this->company, fn () => AssistantMessage::create(['user_id' => memberOf($this->company)->id, 'conversation' => $this->conversation, 'role' => 'user', 'content' => [], 'text' => 'mine']));

    $this->postJson(route('assistant.ask'), ['question' => 'x', 'conversation' => $this->conversation])->assertForbidden();
});
