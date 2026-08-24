<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Refines the trigger from 2026_08_23_000000_enforce_product_status_workflow.
 *
 * The seller Inventory "Delete" action no longer hard-deletes a product —
 * it now soft-archives it (sets status = 'archived') so the row and its
 * order history survive. The original trigger unconditionally reset
 * status back to 'pending_review' on every update made by the product's
 * own seller, which would have silently undone that archive action.
 *
 * This version distinguishes the two cases using NEW.status IS DISTINCT
 * FROM OLD.status, which is true only when the client's UPDATE actually
 * included a new status value:
 *   - Seller explicitly sets status = 'archived' (the Delete action)
 *     -> allowed, row becomes 'archived'.
 *   - Seller explicitly sets status to anything else (e.g. tampering to
 *     force 'active') -> blocked, forced back to 'pending_review'.
 *   - Seller edits ordinary fields without touching status at all
 *     -> unchanged behavior: reset to 'pending_review' for re-review.
 *
 * INSERT behavior (always 'pending_review') and the "updates outside the
 * product's own seller session are left untouched" behavior (e.g. a
 * future admin verify/restore action) are unchanged from the original
 * migration.
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

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Revert to the original function body (no self-archive allowance).
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
    }
};