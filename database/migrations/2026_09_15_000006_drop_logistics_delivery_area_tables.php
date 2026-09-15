<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery areas (province + several municipalities, each with a rider
 * roster + round-robin rotation) are replaced by
 * logistics_barangay_assignments (one barangay, at most one rider) — see
 * App\Services\ParcelIntakeService / App\Services\ParcelAutoAssignService.
 *
 * Dropped rather than migrated: as of writing this only held a handful of
 * dev rows (6 areas / 35 municipality rows / 1 rider appointment), and
 * there's no lossless mapping from "several municipalities, several riders,
 * round robin" to "one barangay, one rider" anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('logistics_delivery_area_riders');
        Schema::dropIfExists('logistics_delivery_area_municipalities');
        Schema::dropIfExists('logistics_delivery_areas');
    }

    public function down(): void
    {
        // Irreversible by design — see class docblock. Re-running the
        // original create migrations is the only way back, and the data
        // that was in these tables is gone.
    }
};
