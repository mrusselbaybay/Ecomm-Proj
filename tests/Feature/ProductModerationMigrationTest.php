<?php

use App\Models\ProductModerationLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('creates and rolls back the product moderation log table', function () {
    Schema::create('products', function (Blueprint $table) {
        $table->uuid('id')->primary();
    });

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_18_103310_create_product_moderation_logs_table.php');

    $migration->up();

    expect(Schema::hasColumns('product_moderation_logs', [
        'id',
        'product_id',
        'ai_status',
        'ai_confidence_score',
        'ai_flagged_signals',
        'ai_reasoning',
        'final_status',
        'needs_human_review',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $log = ProductModerationLog::factory()->create();

    $this->assertModelExists($log);

    $migration->down();

    expect(Schema::hasTable('product_moderation_logs'))->toBeFalse();

    Schema::dropIfExists('products');
});
