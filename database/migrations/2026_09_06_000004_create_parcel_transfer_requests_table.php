<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A logistics company holding a picked-up parcel it can't deliver itself
 * (the buyer is in another island-group region — see
 * ParcelAssignment::STATUS_TRANSFERRED) no longer hands the parcel
 * straight to another company. It raises a *transfer request* instead:
 * the receiving company must accept it before custody actually moves.
 *
 *   - pending ..... the origin company has asked; the origin
 *                   parcel_assignments row sits at
 *                   STATUS_TRANSFER_PENDING and can't be re-routed or
 *                   delivered locally until this is answered.
 *   - accepted .... the receiving company took it — the origin row
 *                   closes as STATUS_TRANSFERRED and a fresh
 *                   "to be delivered" row opens at the target
 *                   (ParcelIntakeService::createTransferReceipt), linked
 *                   back here through `resulting_assignment_id`.
 *   - rejected .... the receiving company declined — the origin row goes
 *                   back to STATUS_HANDED_OFF (no rider) so the origin
 *                   company can try another company or deliver it after
 *                   all. `response_note` carries the reason.
 *   - cancelled ... the origin company withdrew the request before it
 *                   was answered; the origin row likewise returns to
 *                   STATUS_HANDED_OFF.
 *
 * At most one 'pending' request per parcel_assignments row is enforced in
 * the controller (not a DB constraint) so a rejected/cancelled row never
 * blocks a fresh attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_transfer_requests')) {
            return;
        }

        Schema::create('parcel_transfer_requests', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                $table->uuid('id')->default(DB::raw('gen_random_uuid()'))->primary();
            } else {
                $table->uuid('id')->primary();
            }

            // The origin company's parcel_assignments row this request is
            // trying to move.
            $table->uuid('parcel_assignment_id');
            // Denormalised so the receiving company's inbox can show the
            // parcel without joining through to the origin row.
            $table->uuid('order_id');
            $table->uuid('from_company_id');
            $table->uuid('to_company_id');

            // pending | accepted | rejected | cancelled
            $table->string('status', 20)->default('pending');

            // Who raised it (origin staff) and who answered it (receiving
            // staff, on accept/reject).
            $table->uuid('requested_by');
            $table->timestampTz('requested_at');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            // The receiving company's note when it rejects (optional).
            $table->text('response_note')->nullable();

            // The new parcel_assignments row opened at the target company
            // once the request is accepted.
            $table->uuid('resulting_assignment_id')->nullable();

            $table->timestampsTz();

            $table->index(['to_company_id', 'status']);
            $table->index(['from_company_id', 'status']);
            $table->index(['parcel_assignment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_transfer_requests');
    }
};
