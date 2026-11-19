<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's task of November 19, part 4: To Do and the Parts page.
 *
 * - suppliers: the company's parts suppliers with an address (Settings → Suppliers).
 * - job_parts: several parts per job (to order → ordered → on hand), with the AI's number marked "check".
 * - tasks: type, address (geocoded), link to a job, repeat, the day it was first planned (for "carried over"),
 *   and the supplier of an automatic "Buy at {supplier}" task.
 * - company_user.today_panes: the person's last choice of Jobs / To Do on the Today screen.
 *
 * The part already saved on a job (service_jobs.part_*) is copied into job_parts for jobs that wait for a part.
 * The old columns are kept (nothing is deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->string('name', 160);
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'name']);
        });

        Schema::create('job_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('service_job_id')->constrained();
            $table->string('name', 255);
            $table->string('part_number', 100)->nullable();
            // The number came from the AI and nobody has corrected or confirmed it yet ("check").
            $table->boolean('number_unverified')->default(false);
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            // to_order → ordered → on_hand.
            $table->string('status', 20)->default('to_order');
            $table->date('ordered_on')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'service_job_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('kind', 20)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('service_job_id')->nullable()->constrained()->nullOnDelete();
            // none / weekly / monthly; repeated_at = the next one has been made.
            $table->string('repeat', 10)->default('none');
            $table->timestamp('repeated_at')->nullable();
            // The day it was first planned; moved undone tasks show "carried over N days".
            $table->date('planned_date')->nullable();
            // An automatic "Buy at {supplier}" task.
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('company_user', function (Blueprint $table) {
            $table->string('today_panes', 10)->nullable();
        });

        $this->copyExistingParts();
    }

    /**
     * Jobs that wait for a part keep it: one job_parts row per job, the supplier by its name.
     */
    private function copyExistingParts(): void
    {
        $jobs = DB::table('service_jobs')
            ->whereNull('deleted_at')
            ->whereIn('status', ['need_to_order_parts', 'waiting_for_parts'])
            ->get(['id', 'company_id', 'status', 'part_name', 'part_number', 'part_supplier', 'part_ordered_on', 'part_suggestion']);

        foreach ($jobs as $job) {
            $supplierId = null;
            $supplier = trim((string) $job->part_supplier);
            if ($supplier !== '') {
                $supplierId = DB::table('suppliers')->where('company_id', $job->company_id)->whereRaw('lower(name) = ?', [mb_strtolower($supplier)])->value('id')
                    ?? DB::table('suppliers')->insertGetId(['company_id' => $job->company_id, 'name' => mb_substr($supplier, 0, 160), 'created_at' => now(), 'updated_at' => now()]);
            }

            $suggestion = is_string($job->part_suggestion) ? json_decode($job->part_suggestion, true) : null;
            $number = trim((string) $job->part_number);
            $suggested = $number === '' && is_array($suggestion) ? trim((string) ($suggestion['part_number'] ?? '')) : '';
            $ordered = $job->status === 'waiting_for_parts';

            DB::table('job_parts')->insert([
                'company_id' => $job->company_id,
                'service_job_id' => $job->id,
                'name' => mb_substr(trim((string) $job->part_name) ?: 'Part', 0, 255),
                'part_number' => $number !== '' ? $number : ($suggested !== '' ? $suggested : null),
                'number_unverified' => $number === '' && $suggested !== '',
                'quantity' => 1,
                'supplier_id' => $supplierId,
                'status' => $ordered ? 'ordered' : 'to_order',
                'ordered_on' => $ordered ? ($job->part_ordered_on ?? now()->toDateString()) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('company_user', fn (Blueprint $table) => $table->dropColumn('today_panes'));
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_job_id');
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['kind', 'address', 'latitude', 'longitude', 'repeat', 'repeated_at', 'planned_date']);
        });
        Schema::dropIfExists('job_parts');
        Schema::dropIfExists('suppliers');
    }
};
