<?php

use App\Support\Customers\EmptyAddresses;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's fixes of October 19:
 * - "Work alone / with a team" per company. A company with one active member starts in "alone" mode and its open
 *   unassigned visits go to the Owner.
 * - No address without an address: jobs, estimates and invoices on an empty address move to the customer's address
 *   when the customer has exactly one; empty addresses left without jobs go to the trash (soft delete, restorable).
 * - The price book item "Labor (per hour)" is called "Labor".
 */
return new class extends Migration
{
    private const OPEN_VISITS = ['scheduled', 'on_the_way', 'in_progress'];

    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->boolean('works_alone')->default(false));

        $now = now();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $members = DB::table('company_user')->where('company_id', $companyId)->where('is_active', true)->get();
            if ($members->count() !== 1) {
                continue;
            }

            DB::table('companies')->where('id', $companyId)->update(['works_alone' => true]);
            $ownerId = $members->first()->user_id;
            $unassigned = DB::table('job_visits')
                ->where('company_id', $companyId)
                ->whereIn('status', self::OPEN_VISITS)
                ->whereNotExists(fn ($q) => $q->from('job_visit_user')->whereColumn('job_visit_user.job_visit_id', 'job_visits.id'))
                ->pluck('id');
            foreach ($unassigned as $visitId) {
                DB::table('job_visit_user')->insert([
                    'company_id' => $companyId, 'job_visit_id' => $visitId, 'user_id' => $ownerId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        EmptyAddresses::clean();

        DB::table('services')->whereIn('name', ['Labor (per hour)', 'Labour (per hour)'])
            ->update(['name' => DB::raw("replace(name, ' (per hour)', '')")]);
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('works_alone'));
    }
};
