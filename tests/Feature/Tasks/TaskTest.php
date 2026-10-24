<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Task;
use App\Notifications\AppAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "+ → Task" (owner's task, Oct 24): a to-do without a customer — title, day, a time or all day, note, who it is for
 * and a reminder (push and email). Shown in the calendar and on My Jobs; private to the company.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['country' => 'CA', 'timezone' => 'America/Vancouver', 'locale' => 'en-CA']);
    $this->owner = memberOf($this->company);
    $this->tech = memberOf($this->company, UserRole::Technician);
    $this->otherTech = memberOf($this->company, UserRole::Technician);
    $this->stranger = memberOf(Company::factory()->create());
    $this->task = fn (array $extra = []) => [
        'title' => 'Call the supplier', 'task_date' => '2026-10-16', 'all_day' => false,
        'start_time' => '09:00', 'end_time' => '09:30', 'note' => 'Ask about the pump', 'remind_minutes' => 15,
        ...$extra,
    ];
    $this->tasks = fn () => inCompany($this->company, fn () => Task::query()->get());
});

test('a task is saved for oneself with its time and reminder in the company time zone', function () {
    $this->actingAs($this->tech)->post(route('tasks.store'), ($this->task)())->assertSessionHasNoErrors();

    $task = ($this->tasks)()->sole();
    expect($task->user_id)->toBe($this->tech->id)
        ->and($task->created_by)->toBe($this->tech->id)
        ->and($task->company_id)->toBe($this->company->id)
        ->and($task->starts_at->toIso8601String())->toBe('2026-10-16T16:00:00+00:00')
        ->and($task->ends_at->toIso8601String())->toBe('2026-10-16T16:30:00+00:00')
        ->and($task->remind_at->toIso8601String())->toBe('2026-10-16T15:45:00+00:00');
});

test('an all-day task has no time and reminds from the start of the working day', function () {
    $this->actingAs($this->tech)->post(route('tasks.store'), ($this->task)(['all_day' => true, 'start_time' => null, 'remind_minutes' => 0]))
        ->assertSessionHasNoErrors();

    $task = ($this->tasks)()->sole();
    expect($task->all_day)->toBeTrue()
        ->and($task->start_time)->toBeNull()
        ->and($task->starts_at)->toBeNull()
        ->and($task->remind_at->toIso8601String())->toBe('2026-10-16T15:00:00+00:00');
});

test('the office gives tasks to teammates; a technician only to themselves', function () {
    $this->actingAs($this->owner)->post(route('tasks.store'), ($this->task)(['user_id' => $this->tech->id]))->assertSessionHasNoErrors();
    expect(($this->tasks)()->sole()->user_id)->toBe($this->tech->id);

    $this->actingAs($this->tech)->post(route('tasks.store'), ($this->task)(['user_id' => $this->otherTech->id]))->assertSessionHasErrors('user_id');
    $this->actingAs($this->owner)->post(route('tasks.store'), ($this->task)(['user_id' => $this->stranger->id]))->assertSessionHasErrors('user_id');
    expect(($this->tasks)())->toHaveCount(1);
});

test('the calendar shows everyone\'s tasks to the office; My Jobs shows the person their own', function () {
    $this->actingAs($this->owner)->post(route('tasks.store'), ($this->task)(['user_id' => $this->tech->id]));
    $this->actingAs($this->otherTech)->post(route('tasks.store'), ($this->task)(['title' => 'Their own']));

    $this->actingAs($this->owner)->get(route('calendar', ['view' => 'day', 'date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 2));
    $this->get(route('calendar', ['view' => 'day', 'date' => '2026-10-17']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    $this->actingAs($this->tech)->get(route('jobs.mine', ['date' => '2026-10-16']))
        ->assertInertia(fn (Assert $page) => $page->has('tasks', 1)
            ->where('tasks.0.title', 'Call the supplier')
            ->where('tasks.0.start_time', '09:00')
            ->where('tasks.0.can_edit', true));
});

test('a technician cannot change someone else\'s task; another company cannot see or touch it', function () {
    $this->actingAs($this->otherTech)->post(route('tasks.store'), ($this->task)());
    $task = ($this->tasks)()->sole();

    $this->actingAs($this->tech)->put(route('tasks.update', $task), ($this->task)(['title' => 'Mine now']))->assertForbidden();
    $this->post(route('tasks.done', $task))->assertForbidden();
    $this->delete(route('tasks.destroy', $task))->assertForbidden();

    $this->actingAs($this->stranger)->put(route('tasks.update', $task), ($this->task)())->assertNotFound();
    $this->delete(route('tasks.destroy', $task))->assertNotFound();
    $this->get(route('calendar', ['view' => 'day', 'date' => '2026-10-16']))->assertInertia(fn (Assert $page) => $page->has('tasks', 0));

    expect($task->fresh())->title->toBe('Call the supplier')->trashed()->toBeFalse();
});

test('done, edit and delete', function () {
    $this->actingAs($this->tech)->post(route('tasks.store'), ($this->task)());
    $task = ($this->tasks)()->sole();

    $this->post(route('tasks.done', $task), ['done' => true]);
    expect($task->fresh()->completed_at)->not->toBeNull();
    $this->post(route('tasks.done', $task), ['done' => false]);
    expect($task->fresh()->completed_at)->toBeNull();

    $this->put(route('tasks.update', $task), ($this->task)(['start_time' => '10:00', 'end_time' => null]))->assertSessionHasNoErrors();
    expect($task->fresh())->start_time->toBe('10:00')->end_time->toBeNull();

    $this->delete(route('tasks.destroy', $task));
    expect($task->fresh()->trashed())->toBeTrue();
});

test('the reminder goes once, by push and email, to the one the task is for; a done task is skipped', function () {
    Notification::fake();
    $this->actingAs($this->owner)->post(route('tasks.store'), ($this->task)(['user_id' => $this->tech->id]));
    $this->post(route('tasks.store'), ($this->task)(['title' => 'Already done']));
    $done = inCompany($this->company, fn () => Task::query()->where('title', 'Already done')->sole());
    $done->forceFill(['completed_at' => now()])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-16 08:40', 'America/Vancouver'));
    $this->artisan('tasks:send-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-16 08:46', 'America/Vancouver'));
    $this->artisan('tasks:send-reminders')->assertSuccessful();
    $this->artisan('tasks:send-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->tech, AppAlert::class, 1);
    Notification::assertSentTo($this->tech, AppAlert::class, fn (AppAlert $alert) => $alert->kind === 'task_reminder'
        && $alert->title === 'Reminder: Call the supplier'
        && str_contains(str_replace("\u{202F}", ' ', $alert->body), 'Fri, Oct 16 · 9:00 a.m.')
        && str_contains($alert->body, 'Ask about the pump'));
    Notification::assertNotSentTo($this->owner, AppAlert::class);
});

test('a new time reminds again', function () {
    $this->actingAs($this->tech)->post(route('tasks.store'), ($this->task)());
    $task = ($this->tasks)()->sole();
    $task->forceFill(['reminded_at' => now()])->saveQuietly();

    $this->put(route('tasks.update', $task), ($this->task)(['start_time' => '11:00', 'end_time' => null]));

    expect($task->fresh()->reminded_at)->toBeNull();
});
