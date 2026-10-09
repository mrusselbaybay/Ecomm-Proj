<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Store page content a seller can supply: a banner image (a path inside the
 * public `store-banners` Supabase Storage bucket), a short description and
 * the store's own return policy. All nullable: existing stores keep
 * rendering their fallbacks, and business_name / line_of_business are
 * untouched.
 *
 * Sellers can also write their own seller_details row directly through
 * Supabase (RLS "update own"), so the same limits the Laravel endpoint
 * validates are enforced here as CHECK constraints on Postgres: plain-text
 * length caps, and a banner path that must live in the seller's own folder
 * (only the service role can upload into that bucket, so a path there was
 * validated by Laravel when it was uploaded).
 *
 * Mirrored verbatim on feature/seller, which owns the settings endpoint.
 */
return new class extends Migration
{
    public const DESCRIPTION_MAX = 1000;

    public const RETURN_POLICY_MAX = 2000;

    public function up(): void
    {
        if (! Schema::hasTable('seller_details')) {
            return;
        }

        Schema::table('seller_details', function (Blueprint $table) {
            if (! Schema::hasColumn('seller_details', 'banner_path')) {
                $table->string('banner_path', 512)->nullable();
            }

            if (! Schema::hasColumn('seller_details', 'description')) {
                $table->text('description')->nullable();
            }

            if (! Schema::hasColumn('seller_details', 'return_policy')) {
                $table->text('return_policy')->nullable();
            }
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_description_length');
        DB::statement('ALTER TABLE public.seller_details ADD CONSTRAINT seller_details_description_length CHECK (description IS NULL OR char_length(description) <= '.self::DESCRIPTION_MAX.')');

        DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_return_policy_length');
        DB::statement('ALTER TABLE public.seller_details ADD CONSTRAINT seller_details_return_policy_length CHECK (return_policy IS NULL OR char_length(return_policy) <= '.self::RETURN_POLICY_MAX.')');

        DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_banner_path_owner');
        DB::statement("ALTER TABLE public.seller_details ADD CONSTRAINT seller_details_banner_path_owner CHECK (banner_path IS NULL OR (banner_path LIKE profile_id::text || '/%' AND banner_path NOT LIKE '%..%'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('seller_details')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_description_length');
            DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_return_policy_length');
            DB::statement('ALTER TABLE public.seller_details DROP CONSTRAINT IF EXISTS seller_details_banner_path_owner');
        }

        Schema::table('seller_details', function (Blueprint $table) {
            foreach (['banner_path', 'description', 'return_policy'] as $column) {
                if (Schema::hasColumn('seller_details', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
