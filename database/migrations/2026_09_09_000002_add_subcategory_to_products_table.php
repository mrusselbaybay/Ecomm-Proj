<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some lines of business cover different enough products that one flat
 * variant/specification template doesn't fit all of them — e.g. a "Pet
 * Supplies" seller might list dog TOYS (Size/Color/Material) on one
 * product and FOOD (Pack Weight/Flavor) on another. `line_of_business`
 * stays fixed per seller account (seller_details), but `subcategory` is
 * chosen PER PRODUCT since one seller plausibly lists across several of
 * their line's subcategories — see App\Support\CategoryFieldConfig's
 * class docblock for the full model.
 *
 * Nullable: a category with no subcategory concept
 * (CategoryFieldConfig::hasSubcategories() === false) never sets this.
 */
return new class extends Migration
{
    public function up(): void
    {
        // products isn't a Laravel-migrated table (it lives in the
        // Supabase/pgsql database only) — nothing to alter on sqlite,
        // where it doesn't exist.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasColumn('products', 'subcategory')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('subcategory')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasColumn('products', 'subcategory')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('subcategory');
            });
        }
    }
};
