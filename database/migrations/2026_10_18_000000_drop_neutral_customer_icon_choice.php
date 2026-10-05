<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Neutral" is no longer a choice: those customers go back to the face from their first name (or initials). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->where('avatar_style', 'neutral')->update(['avatar_style' => 'auto']);
    }

    public function down(): void
    {
        // Nothing to restore: "auto" shows initials for names that fit both.
    }
};
