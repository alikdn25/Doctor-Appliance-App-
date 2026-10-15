<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * UX-REDESIGN §25 and §7.1, approved by the owner on October 15, 2026:
 * - companies.arrival_windows: the arrival windows a company offers when booking (any number, may overlap, e.g.
 *   9–11, 10–12, 11–1). Empty = the default windows (9–11, 11–1, 1–3, 3–5).
 * - company_user.shows_in_calendar: the person goes on calls, so booking offers them as the technician. On for
 *   Owners and Technicians, off for the other roles (each can be changed on the Team screen).
 * Adds columns only; existing data is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->json('arrival_windows')->nullable());
        Schema::table('company_user', fn (Blueprint $table) => $table->boolean('shows_in_calendar')->default(false));

        DB::table('company_user')->whereIn('role', ['owner', 'technician'])->update(['shows_in_calendar' => true]);
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('arrival_windows'));
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('shows_in_calendar'));
    }
};
