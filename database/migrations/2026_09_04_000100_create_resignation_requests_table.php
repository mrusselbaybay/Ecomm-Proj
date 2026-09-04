<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A courier who has been accepted by a logistics company (an 'accepted'
 * row in courier_applications) can ask to leave. They attach a resignation
 * letter; the company reviews it. On approval the courier is freed —
 * their accepted application flips to 'withdrawn' and they're pulled from
 * that company's delivery areas — so they can apply elsewhere. On
 * rejection they stay employed and may submit a fresh letter later.
 *
 * At most one 'pending' request per courier is enforced in the controller
 * (not a DB constraint) so a rejected/cancelled row doesn't block a retry.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resignation_requests')) {
            return;
        }

        Schema::create('resignation_requests', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            $table->uuid('courier_profile_id');
            $table->uuid('logistics_company_id');
            // The 'accepted' courier_applications row this resigns from —
            // nullable so an odd data state can't block a submission.
            $table->uuid('courier_application_id')->nullable();

            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');

            $table->string('letter_original_name')->nullable();
            $table->string('letter_path')->nullable();
            $table->unsignedBigInteger('letter_size')->nullable();

            // The courier's own note (optional) and the company's note on
            // approve/reject (a reason is required on reject).
            $table->text('reason')->nullable();
            $table->text('decision_note')->nullable();

            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->index(['courier_profile_id', 'status']);
            $table->index(['logistics_company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resignation_requests');
    }
};
