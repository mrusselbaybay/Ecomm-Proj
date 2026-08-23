<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Product status contract (shared with Buyer/Admin):
 *   - Seller creates/edits a product -> status = 'pending_review'
 *   - Admin verifies                 -> status = 'active'
 *   - Buyer sees                     -> only status = 'active'
 *   - Admin removes                  -> status = 'archived'
 *   - Admin restores                 -> status = 'pending_review'
 *
 * The seller Inventory UI (resources/js/seller/composables/useSellerProducts.js)
 * writes to public.products directly via the Supabase client using the
 * seller's own session (anon key + RLS), not through this Laravel app. A
 * frontend check alone would be trivial to bypass with a hand-crafted
 * request, so the "seller can never set active/archived" rule has to be
 * enforced where the write actually lands: the database.
 *
 * This trigger forces the workflow at the row level:
 *   - Any INSERT into products always lands as 'pending_review',
 *     regardless of what the client sent (only sellers create rows here).
 *   - Any UPDATE made by the row's own seller (auth.uid() = seller_id,
 *     the Supabase session identity) always resets status back to
 *     'pending_review', regardless of what the client sent.
 *   - Updates made outside that seller's own session context (i.e. not
 *     matching auth.uid() = seller_id — such as a future admin
 *     verify/remove/restore action) are left untouched, so this does not
 *     implement or interfere with the admin actions that are explicitly
 *     out of scope here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            // Supabase/production runs pgsql; local sqlite test DBs don't
            // have a products table at all (it isn't Laravel-migrated
            // there either), so there's nothing to attach a trigger to.
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_product_status_workflow()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    NEW.status := 'pending_review';
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF auth.uid() IS NOT NULL AND auth.uid() = OLD.seller_id THEN
                        NEW.status := 'pending_review';
                    END IF;

                    RETURN NEW;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_products_status_workflow ON public.products');

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_products_status_workflow
              BEFORE INSERT OR UPDATE ON public.products
              FOR EACH ROW EXECUTE FUNCTION public.enforce_product_status_workflow();
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_products_status_workflow ON public.products');
        DB::statement('DROP FUNCTION IF EXISTS public.enforce_product_status_workflow()');
    }
};