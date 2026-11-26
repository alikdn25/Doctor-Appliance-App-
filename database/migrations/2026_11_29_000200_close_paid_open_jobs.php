<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Data fix (owner's task of November 26): open jobs whose invoices are all paid, with no visit booked after the last
 * payment, are closed once (jobs:close-paid). Sends no email or SMS; every job closed is in the change log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('jobs:close-paid');
    }

    public function down(): void
    {
        // A data fix: nothing to undo.
    }
};
