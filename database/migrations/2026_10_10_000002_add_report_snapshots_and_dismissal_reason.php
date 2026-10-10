<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->boolean('reporter_is_anonymous')->default(false);
            $table->string('reporter_name_snapshot')->nullable();
            $table->string('target_name_snapshot')->nullable();
            $table->string('seller_name_snapshot')->nullable();
            $table->string('dismissal_reason', 80)->nullable();
            $table->unsignedSmallInteger('reporter_dismissed_count')->default(0);
            $table->timestampTz('urgent_review_due_at')->nullable();
        });

        DB::table('complaints')->whereNotNull('report_reason')->orderBy('id')->chunkById(100, function ($reports): void {
            foreach ($reports as $report) {
                $reporter = DB::table('profiles')->where('id', $report->complainant_id)->first();
                $reporterName = $reporter ? trim($reporter->first_name.' '.($reporter->last_name ?? '')) : null;
                $targetName = null;
                $sellerName = null;

                if ($report->target_product_id) {
                    $product = DB::table('products')->where('id', $report->target_product_id)->first();
                    $targetName = $product?->name;
                    $seller = $product ? DB::table('profiles')->where('id', $product->seller_id)->first() : null;
                    $sellerDetail = $seller ? DB::table('seller_details')->where('profile_id', $seller->id)->first() : null;
                    $sellerName = $sellerDetail?->business_name ?: ($seller ? trim($seller->first_name.' '.($seller->last_name ?? '')) : null);
                } elseif ($report->target_store_id) {
                    $seller = DB::table('profiles')->where('id', $report->target_store_id)->first();
                    $sellerDetail = $seller ? DB::table('seller_details')->where('profile_id', $seller->id)->first() : null;
                    $targetName = $sellerDetail?->business_name ?: ($seller ? trim($seller->first_name.' '.($seller->last_name ?? '')) : null);
                    $sellerName = $targetName;
                }

                DB::table('complaints')->where('id', $report->id)->update([
                    'reporter_name_snapshot' => $reporterName ?: null,
                    'target_name_snapshot' => $targetName,
                    'seller_name_snapshot' => $sellerName,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table): void {
            $table->dropColumn([
                'reporter_is_anonymous',
                'reporter_name_snapshot',
                'target_name_snapshot',
                'seller_name_snapshot',
                'dismissal_reason',
                'reporter_dismissed_count',
                'urgent_review_due_at',
            ]);
        });
    }
};
