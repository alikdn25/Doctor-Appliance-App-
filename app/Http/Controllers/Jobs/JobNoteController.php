<?php

namespace App\Http\Controllers\Jobs;

use App\Http\Controllers\Controller;
use App\Models\JobNote;
use App\Models\ServiceJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Team notes on a job (the Notes tab): anyone who works on the job adds one; the author or the office corrects or removes it.
 */
class JobNoteController extends Controller
{
    public function store(Request $request, ServiceJob $job): RedirectResponse
    {
        Gate::authorize('work', $job);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']], [], ['body' => __('jobs.notes_tab.note')]);

        $note = new JobNote(['body' => trim($data['body'])]);
        $note->job()->associate($job);
        $note->user()->associate($request->user());
        $note->save();

        return back();
    }

    /** Any note can be corrected later (owner's task of November 25): the author or the office; the change is logged. */
    public function update(Request $request, JobNote $note): RedirectResponse
    {
        Gate::authorize('work', $note->job);
        abort_unless($note->user_id === $request->user()->id || Gate::allows('update', $note->job), 403);
        $data = $request->validate(['body' => ['nullable', 'string', 'max:5000']], [], ['body' => __('jobs.notes_tab.note')]);

        $body = trim((string) ($data['body'] ?? ''));
        if ($body !== '') {
            $note->forceFill(['body' => $body])->save();
        }

        return back();
    }

    public function destroy(Request $request, JobNote $note): RedirectResponse
    {
        Gate::authorize('work', $note->job);
        abort_unless($note->user_id === $request->user()->id || Gate::allows('update', $note->job), 403);

        $note->delete();

        return back();
    }
}
