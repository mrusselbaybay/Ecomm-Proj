<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sellers have exactly one product category — their own
 * seller_details.line_of_business — so every product they own must carry
 * that value, never a value chosen on the client.
 *
 * The seller Inventory form already sends the category as a read-only
 * field pre-filled from line_of_business (see Inventory.vue), but that's
 * a UI convenience only: the actual write still goes straight from the
 * browser to Supabase (resources/js/seller/composables/useSellerProducts.js),
 * so a tampered request could submit any category value. This extends the
 * same trigger used for the status workflow (2026_08_23_000000 /
 * 2026_08_23_000001) to also force category server-side:
 *
 *   - INSERT: category is always overwritten with the product's own
 *     seller's current line_of_business.
 *   - UPDATE made by the product's own seller (auth.uid() = seller_id):
 *     category is always overwritten with that seller's current
 *     line_of_business too, so it can never be changed via edit.
 *   - Updates outside that seller's own session (e.g. a future admin
 *     action) are left untouched, same as the status logic.
 *
 * If no seller_details row is found for the seller (shouldn't happen —
 * business_name/line_of_business are required at seller registration),
 * the category is left as submitted rather than nulling it out, so this
 * never turns into a hard failure on an edge case outside this task's
 * scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.enforce_product_status_workflow()
            RETURNS trigger AS $$
            DECLARE
                v_line_of_business text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    NEW.status := 'pending_review';

                    SELECT line_of_business INTO v_line_of_business
                    FROM public.seller_details
                    WHERE profile_id = NEW.seller_id;

                    IF v_line_of_business IS NOT NULL THEN
                        NEW.category := v_line_of_business;
                    END IF;

                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF auth.uid() IS NOT NULL AND auth.uid() = OLD.seller_id THEN
                        IF NEW.status IS DISTINCT FROM OLD.status THEN
                            IF NEW.status = 'archived' THEN
                                NEW.status := 'archived';
                            ELSE
                                NEW.status := 'pending_review';
                            END IF;
                        ELSE
                            NEW.status := 'pending_review';
                        END IF;

                        SELECT line_of_business INTO v_line_of_business
                        FROM public.seller_details
                        WHERE profile_id = NEW.seller_id;

                        IF v_line_of_business IS NOT NULL THEN
                            NEW.category := v_line_of_business;
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public;
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Revert to the previous function body (status workflow only, no
        // category enforcement).
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
                        IF NEW.status IS DISTINCT FROM OLD.status THEN
                            IF NEW.status = 'archived' THEN
                                NEW.status := 'archived';
                            ELSE
                                NEW.status := 'pending_review';
                            END IF;
                        ELSE
                            NEW.status := 'pending_review';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public;
        SQL);
    }
};