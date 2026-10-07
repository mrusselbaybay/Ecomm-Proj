<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('delivery_attempt_count')->default(0);
            $table->unsignedInteger('delivery_attempt_limit')->nullable();
            $table->timestampTz('delivery_retry_at')->nullable();
            $table->timestampTz('delivery_return_flagged_at')->nullable();
        });
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->uuid('parcel_assignment_id')->index();
            $table->uuid('courier_id');
            $table->unsignedInteger('attempt_number');
            $table->string('reason_code', 64);
            $table->string('reason_label');
            $table->text('description')->nullable();
            $table->timestampTz('failed_at');
            $table->unique(['order_id', 'attempt_number']);
        });
        $this->updateStatusConstraint(true);
    }

    public function down(): void
    {
        DB::table('orders')->whereIn('status', ['Failed Delivery Attempt', 'Needs Dispatcher Review'])->update(['status' => 'In Transit']);
        DB::table('parcel_assignments')->whereIn('status', ['failed_attempt', 'needs_dispatcher_review'])->update(['status' => 'handed_off']);
        $this->updateStatusConstraint(false);
        Schema::dropIfExists('delivery_attempts');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['delivery_attempt_count', 'delivery_attempt_limit', 'delivery_retry_at', 'delivery_return_flagged_at']));
    }

    private function updateStatusConstraint(bool $extended): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $statuses = "'New','Confirmed','Processing','Packed','Ready for Pickup','In Transit','Delivered','Cancelled','Rejected'";
        if ($extended) {
            $statuses .= ",'Failed Delivery Attempt','Needs Dispatcher Review'";
        }
        DB::statement('ALTER TABLE public.orders DROP CONSTRAINT IF EXISTS orders_status_check');
        DB::statement("ALTER TABLE public.orders ADD CONSTRAINT orders_status_check CHECK (status IN ($statuses))");
    }
};
