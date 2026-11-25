<?php

namespace App\Support\Ui;

use Inertia\Inertia;

/**
 * "Saved" with an Open button after a form is saved (owner's task of November 25). The form's own words (e.g. "Job
 * #1005 created") go under it.
 */
class SavedToast
{
    public static function flash(string $url, ?string $details = null): void
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('common.saved'),
            'description' => $details,
            'action' => ['label' => __('common.open'), 'url' => $url],
        ]);
    }
}
