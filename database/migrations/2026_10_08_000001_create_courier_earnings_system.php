<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('logistics_companies')) {
            Schema::table('logistics_companies', function (Blueprint $table): void {
                $table->unsignedSmallInteger('courier_share_bps')->default(8000);
                $table->unsignedSmallInteger('pickup_weight')->default(30);
                $table->unsignedSmallInteger('transfer_weight')->default(10);
                $table->unsignedSmallInteger('delivery_weight')->default(60);
                $table->unsignedSmallInteger('cod_overdue_days')->default(2);
                $table->unsignedBigInteger('early_cashout_minimum_cents')->nullable();
            });
        }
        Schema::create('courier_payouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('courier_id')->index();
            $table->uuid('company_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('kind')->default('weekly');
            $table->string('status')->default('draft')->index();
            $table->bigInteger('earnings_cents')->default(0);
            $table->unsignedBigInteger('cod_offset_cents')->default(0);
            $table->unsignedBigInteger('net_cents')->default(0);
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });
        Schema::create('courier_earnings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('courier_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('order_id')->nullable()->index();
            $table->uuid('parcel_id')->nullable()->index();
            $table->uuid('leg_id')->nullable();
            $table->uuid('failed_parcel_key')->nullable()->unique();
            $table->string('direction')->default('forward');
            $table->string('type');
            $table->string('status')->default('pending')->index();
            $table->bigInteger('amount_cents');
            $table->bigInteger('remaining_cents');
            $table->unsignedBigInteger('source_leg_cents')->default(0);
            $table->unsignedSmallInteger('rate_bps')->default(0);
            $table->json('task_weights')->nullable();
            $table->unsignedSmallInteger('weight_numerator')->default(0);
            $table->unsignedSmallInteger('weight_denominator')->default(0);
            $table->text('reason')->nullable();
            $table->string('proof_path')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('payout_id')->nullable()->index();
            $table->timestamp('available_at')->nullable();
            $table->timestamps();
            $table->unique(['parcel_id', 'type', 'leg_id', 'direction'], 'courier_task_unique');
            $table->index(['company_id', 'courier_id', 'status']);
        });
        Schema::create('courier_payout_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('payout_id')->constrained('courier_payouts');
            $table->foreignUuid('earning_id')->constrained('courier_earnings');
            $table->bigInteger('amount_cents');
            $table->unique(['payout_id', 'earning_id']);
        });
        Schema::create('courier_cod_collections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('courier_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('order_id')->index();
            $table->uuid('parcel_id')->unique();
            $table->unsignedBigInteger('amount_cents');
            $table->unsignedBigInteger('remaining_cents');
            $table->timestamp('collected_at')->index();
            $table->timestamps();
        });
        Schema::create('courier_cod_remittances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('courier_id')->index();
            $table->uuid('company_id')->index();
            $table->foreignUuid('collection_id')->constrained('courier_cod_collections');
            $table->foreignUuid('payout_id')->nullable()->constrained('courier_payouts');
            $table->unsignedBigInteger('amount_cents');
            $table->uuid('recorded_by');
            $table->string('reference');
            $table->timestamps();
        });
        Schema::create('courier_earning_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('company_id')->index();
            $table->uuid('actor_id');
            $table->uuid('entity_id')->index();
            $table->string('action');
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['courier_earning_audits', 'courier_cod_remittances', 'courier_cod_collections', 'courier_payout_lines', 'courier_earnings', 'courier_payouts'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasTable('logistics_companies')) {
            Schema::table('logistics_companies', fn (Blueprint $table) => $table->dropColumn([
                'courier_share_bps', 'pickup_weight', 'transfer_weight', 'delivery_weight', 'cod_overdue_days', 'early_cashout_minimum_cents',
            ]));
        }
    }
};
