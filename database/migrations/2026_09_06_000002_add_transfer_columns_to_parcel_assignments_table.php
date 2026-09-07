<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-region "To Transfer" support. A parcel whose seller and buyer are
 * in different island-group regions can't be delivered by the company
 * that first received it — it flags `is_transfer`, and once a courier has
 * collected it that company picks a `transfer_to_company_id` to hand it
 * to. The handover is immediate and needs no courier of its own: the row
 * closes as STATUS_TRANSFERRED and a brand new ParcelAssignment row opens
 * for the target company already tagged "to be delivered" —
 * `previous_assignment_id` links that new row back to the one it came
 * from.
 *
 * That means one order can now legitimately have more than one
 * ParcelAssignment row over its lifetime (one per company it has passed
 * through), so the original one-row-per-order unique index on `order_id`
 * is dropped here. Order::parcelAssignment() is updated (see the model)
 * to resolve to the latest row via latestOfMany() so every existing
 * caller keeps seeing "the current one" without change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
        });

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->boolean('is_transfer')->default(false)->after('status');
            $table->uuid('transfer_to_company_id')->nullable()->after('logistics_company_id');
            $table->uuid('previous_assignment_id')->nullable()->after('id');
            $table->timestampTz('transferred_at')->nullable()->after('handed_off_at');
            $table->string('transfer_photo_path')->nullable()->after('delivery_photo_path');

            $table->index('order_id');
            $table->index('transfer_to_company_id');
            $table->index('previous_assignment_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('parcel_assignments')) {
            return;
        }

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'is_transfer', 'transfer_to_company_id', 'previous_assignment_id',
                'transferred_at', 'transfer_photo_path',
            ]);
        });

        Schema::table('parcel_assignments', function (Blueprint $table) {
            $table->unique('order_id');
        });
    }
};
