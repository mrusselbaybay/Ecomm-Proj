<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `delivery_status` to both rider detail tables — the flag behind the
 * driver app's "Go online" toggle on the Settings screen
 * (driver_settings_screen.dart -> PUT /api/driver/availability).
 *
 *   - 'available'   : rider is on shift and can be handed deliveries
 *   - 'unavailable' : rider is off (the default)
 *
 * Driver and courier share the same mobile app and the same self-service
 * Settings screen (see EnsureUserIsDriver), so the column lives on
 * whichever detail table backs the signed-in role — resolved by
 * DriverProfileController::deliveryDetail().
 *
 * Like the other Supabase-native tables (see
 * 2026_08_18_000000_create_supabase_baseline_tables), driver_details /
 * courier_details are NOT Laravel-migrated by the feature suite — it
 * builds its own per-test schema. The Schema::hasTable() guard makes this
 * a no-op there. Against the real Supabase/pgsql database `php artisan
 * migrate` runs it for real. Equivalent hand SQL:
 *
 *   alter table public.courier_details
 *     add column if not exists delivery_status varchar(255) not null default 'unavailable';
 *   alter table public.driver_details
 *     add column if not exists delivery_status varchar(255) not null default 'unavailable';
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['courier_details', 'driver_details'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'delivery_status')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                $t->string('delivery_status')->default('unavailable');
            });
        }
    }

    public function down(): void
    {
        // Never drop columns on the real database — it isn't ours. Only
        // reversible on a throwaway (non-pgsql) test database.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'delivery_status')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->dropColumn('delivery_status');
                });
            }
        }
    }
};
