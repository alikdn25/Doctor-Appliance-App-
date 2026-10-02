<?php

use App\Enums\EstimateStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Payment;
use App\Models\Property;
use App\Models\ServiceJob;
use App\Models\TaxRate;
use App\Notifications\EstimateDecided;
use App\Support\Billing\PublicDocument;
use App\Support\PrivateMedia;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Revise" on an estimate the customer signed: a new version, the signed one stays in the history.
 */
beforeEach(function () {
    Storage::fake(PrivateMedia::diskName());
    Notification::fake();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));

    $this->company = Company::factory()->create();
    $this->owner = memberOf($this->company, UserRole::Owner);
    $brand = Brand::factory()->create(['company_id' => $this->company->id]);
    $customer = Customer::factory()->for($this->company)->withEmail('maria@example.com')->create();
    $this->job = ServiceJob::factory()->for(Property::factory()->for($customer))->create(['brand_id' => $brand->id]);
    $this->actingAs($this->owner);

    $this->post(route('estimates.store', $this->job), documentPayload(['deposit_type' => 'percent', 'deposit_value' => '10']));
    $this->estimate = inCompany($this->company, fn () => Estimate::sole());
    $this->token = inCompany($this->company, fn () => PublicDocument::token($this->estimate));
});

function signOnline(string $token): void
{
    app(CurrentCompany::class)->forget();
    test()->post(route('documents.public.approve', $token), [
        'signer_name' => 'Maria Garcia', 'signature_type' => 'typed', 'selected_items' => [],
    ])->assertSessionHasNoErrors();
}

test('only an estimate signed online can be revised', function () {
    $this->get(route('estimates.show', $this->estimate))->assertInertia(fn (Assert $page) => $page->where('can.revise', false));
    $this->post(route('estimates.revise', $this->estimate))->assertForbidden();

    signOnline($this->token);

    $this->actingAs($this->owner);
    $this->get(route('estimates.show', $this->estimate))->assertInertia(fn (Assert $page) => $page->where('can.revise', true));
});

test('revising makes a new draft version; the signed one stays in the history', function () {
    signOnline($this->token);
    inCompany($this->company, function () {
        $payment = new Payment(['amount' => 280, 'method' => 'online', 'received_at' => now(), 'provider' => 'square', 'provider_payment_id' => 'P1']);
        $payment->estimate_id = $this->estimate->id;
        $payment->currency = $this->estimate->currency;
        $payment->save();
    });

    $this->actingAs($this->owner);
    $response = $this->post(route('estimates.revise', $this->estimate));

    [$old, $new] = inCompany($this->company, fn () => [$this->estimate->fresh(), Estimate::query()->whereKeyNot($this->estimate->id)->with('items')->sole()]);
    $response->assertRedirect(route('estimates.edit', $new));

    expect($old->status)->toBe(EstimateStatus::Revised)
        ->and($old->revised_at)->not->toBeNull()
        ->and($old->signer_name)->toBe('Maria Garcia')
        ->and($new->status)->toBe(EstimateStatus::Draft)
        ->and($new->number)->toBe($old->number.'-R2')
        ->and($new->revision)->toBe(2)
        ->and($new->revision_root_id)->toBe($old->id)
        ->and($new->approved_at)->toBeNull()
        ->and($new->signer_name)->toBeNull()
        ->and($new->public_token)->toBeNull()
        ->and($new->items)->toHaveCount(2)
        ->and($new->total)->toBe($old->total)
        // The deposit follows the current version.
        ->and(inCompany($this->company, fn () => $new->depositPaid()))->toBe(280)
        ->and(AuditLog::query()->where('action', 'estimate.revised')->exists())->toBeTrue();

    // The signed version is read-only and cannot be sent or converted any more.
    $this->get(route('estimates.edit', $old))->assertForbidden();
    $this->post(route('estimates.convert', $old))->assertForbidden();
    $this->post(route('estimates.send', $old), ['email' => 'maria@example.com', 'message' => 'Hi'])->assertStatus(422);

    // The new version is editable and lists both versions.
    $this->get(route('estimates.show', $new))->assertInertia(fn (Assert $page) => $page
        ->where('can.update', true)
        ->where('document.revised_from', $old->number)
        ->has('document.versions', 2)
        ->where('document.versions.0.status', 'revised'));
});

test('the customer gets a new link; the old one points to it once it was sent', function () {
    signOnline($this->token);
    $this->actingAs($this->owner);
    $this->post(route('estimates.revise', $this->estimate));
    $new = inCompany($this->company, fn () => Estimate::query()->whereKeyNot($this->estimate->id)->sole());

    // Not sent yet: the old page says it was replaced, with no link to the draft.
    $this->get(route('documents.public', $this->token))->assertInertia(fn (Assert $page) => $page
        ->where('document.status', 'revised')
        ->where('estimate.can_approve', false)
        ->where('estimate.latest_url', null));

    $this->post(route('estimates.send', $new), ['email' => 'maria@example.com', 'message' => 'Updated estimate'])->assertRedirect();
    $new = inCompany($this->company, fn () => $new->fresh());
    expect($new->public_token)->not->toBeNull()->not->toBe($this->token);

    $this->get(route('documents.public', $this->token))->assertInertia(fn (Assert $page) => $page
        ->where('estimate.latest_url', route('documents.public', $new->public_token)));

    // The customer signs the new version; it can be revised again (R3).
    signOnline($new->public_token);
    $this->actingAs($this->owner);
    $this->post(route('estimates.revise', $new))->assertRedirect();
    expect(inCompany($this->company, fn () => Estimate::latest('id')->first()->number))->toBe($this->estimate->number.'-R3');
    Notification::assertSentTo($this->owner, EstimateDecided::class);
});

test('a technician who is not on the job cannot revise', function () {
    signOnline($this->token);
    $this->actingAs(memberOf($this->company, UserRole::Technician));

    $this->post(route('estimates.revise', $this->estimate))->assertForbidden();
});


test('revising a signed estimate keeps the individual tax selections', function () {
    $tax = TaxRate::factory()->create(['company_id' => $this->company->id]);
    $this->put(route('estimates.update', $this->estimate), documentPayload([
        'tax_rate_ids' => [$tax->id], 'items' => [
            ['description' => 'Labor', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => [$tax->id]],
            ['description' => 'Exempt', 'quantity' => '1', 'unit_price' => '100', 'taxable' => true, 'tax_rate_ids' => []],
        ],
    ]))->assertSessionHasNoErrors();
    $old = inCompany($this->company, fn () => $this->estimate->fresh('items'));
    signOnline($this->token);
    $this->actingAs($this->owner);
    $this->post(route('estimates.revise', $old))->assertRedirect();
    $new = inCompany($this->company, fn () => Estimate::query()->where('revised_from_id', $old->id)->with('items')->sole());
    expect($new->items->pluck('tax_rate_ids')->all())->toBe([[$tax->id], []])
        ->and($new->taxes)->toBe($old->taxes);
});
