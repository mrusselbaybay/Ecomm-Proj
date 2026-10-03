<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller capability review state, separate from the profile's own
 * registration status, so an approved buyer can apply to sell (Switch
 * Account) without their account going back to "pending". Existing rows
 * default to 'approved' — they were approved with their seller signup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_details', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_details', 'application_status')) {
                $table->string('application_status', 20)->default('approved');
            }
            if (! Schema::hasColumn('seller_details', 'application_reason')) {
                $table->text('application_reason')->nullable();
            }
            if (! Schema::hasColumn('seller_details', 'applied_at')) {
                $table->timestampTz('applied_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('seller_details', function (Blueprint $table) {
            $table->dropColumn(['application_status', 'application_reason', 'applied_at']);
        });
    }
};
