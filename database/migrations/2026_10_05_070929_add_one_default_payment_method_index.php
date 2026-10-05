<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * At most one default (is_primary) payment method per buyer, enforced by
 * the database as well as by PaymentMethodController's transactions.
 * Additive: if a buyer somehow has several defaults, all but the newest
 * are cleared first so the index can be built; no rows are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('buyer_payment_methods')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE buyer_payment_methods SET is_primary = false
            WHERE is_primary = true AND id NOT IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY buyer_profile_id ORDER BY created_at DESC) AS position
                    FROM buyer_payment_methods
                    WHERE is_primary = true
                ) ranked
                WHERE position = 1
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS buyer_payment_methods_one_default ON buyer_payment_methods (buyer_profile_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS buyer_payment_methods_one_default');
    }
};
