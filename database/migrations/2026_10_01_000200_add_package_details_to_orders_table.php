<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Package weight/size the seller enters while packing (PrepareOrders),
 * read by logistics in the parcel details drawer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('package_weight', 10, 3)->nullable()->after('shipping_service'); // kg
            $table->string('package_size', 20)->nullable()->after('package_weight'); // Small | Medium | Large
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['package_weight', 'package_size']));
    }
};
