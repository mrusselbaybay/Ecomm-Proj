<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dpo_profile', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->text('address');
            $table->string('npc_registration_no')->nullable();
            $table->timestamps();
        });

        DB::table('dpo_profile')->insert([
            'id' => (string) Str::uuid(),
            'name' => '[DPO_NAME]',
            'email' => '[DPO_EMAIL]',
            'address' => '[BUSINESS_ADDRESS]',
            'npc_registration_no' => '[NPC_REGISTRATION_NO]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dpo_profile');
    }
};
