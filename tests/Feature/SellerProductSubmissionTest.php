<?php

use App\Jobs\ModerateProductJob;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    Schema::create('profiles', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('role');
        $table->string('status')->default('approved');
        $table->string('account_status')->default('active');
        $table->string('first_name');
        $table->string('last_name');
        $table->string('email')->nullable();
        $table->timestamps();
    });

    Schema::create('seller_details', function (Blueprint $table) {
        $table->uuid('profile_id')->primary();
        $table->string('business_name')->nullable();
        $table->string('line_of_business')->nullable();
    });

    Schema::create('products', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('seller_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('category')->nullable();
        $table->string('subcategory')->nullable();
        $table->string('sku')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->decimal('compare_price', 10, 2)->nullable();
        $table->string('promo_code')->nullable();
        $table->integer('stock')->default(0);
        $table->json('images')->default('[]');
        $table->string('status')->default('active');
        $table->string('brand')->nullable();
        $table->string('condition')->nullable();
        $table->json('dimensions')->nullable();
        $table->decimal('weight', 10, 3)->nullable();
        $table->integer('low_stock_threshold')->nullable();
        $table->boolean('has_variants')->default(false);
        $table->json('specifications')->nullable();
        $table->timestamps();
    });

    Schema::create('product_options', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('product_id');
        $table->string('name');
        $table->unsignedSmallInteger('position')->default(0);
        $table->timestamps();
    });

    Schema::create('product_option_values', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('product_option_id');
        $table->string('value');
        $table->unsignedSmallInteger('position')->default(0);
        $table->timestamps();
    });

    Schema::create('product_variants', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('product_id');
        $table->uuid('seller_id')->nullable();
        $table->string('sku')->nullable();
        $table->decimal('price', 12, 2)->nullable();
        $table->decimal('discount_percent', 5, 2)->nullable();
        $table->string('discount_type')->nullable();
        $table->unsignedInteger('stock')->default(0);
        $table->integer('low_stock_threshold')->nullable();
        $table->json('image')->nullable();
        $table->string('status')->default('active');
        $table->timestamps();
    });

    Schema::create('product_variant_option_values', function (Blueprint $table) {
        $table->uuid('product_variant_id');
        $table->uuid('product_option_value_id');
        $table->primary(['product_variant_id', 'product_option_value_id']);
    });

    Schema::create('inventory_movements', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('seller_id');
        $table->uuid('product_id');
        $table->uuid('variant_id')->nullable();
        $table->uuid('order_id')->nullable();
        $table->string('movement_type', 40);
        $table->string('reason', 40)->nullable();
        $table->text('note')->nullable();
        $table->integer('quantity_before');
        $table->integer('quantity_change');
        $table->integer('quantity_after');
        $table->uuid('actor_id')->nullable();
        $table->string('actor_type', 16)->default('seller');
        $table->timestamps();
    });

    config([
        'services.supabase.url' => 'https://supabase.example.test',
        'services.supabase.anon_key' => 'test-anon-key',
        'services.product_moderation.enabled' => true,
    ]);

    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function () {
    Schema::dropIfExists('inventory_movements');
    Schema::dropIfExists('product_variant_option_values');
    Schema::dropIfExists('product_variants');
    Schema::dropIfExists('product_option_values');
    Schema::dropIfExists('product_options');
    Schema::dropIfExists('products');
    Schema::dropIfExists('seller_details');
    Schema::dropIfExists('profiles');
});

it('creates a pending-review product with variants and queues moderation for an active seller', function () {
    $sellerId = createSellerWithLineOfBusiness('Electronics and Gadgets');

    Http::fake([
        'https://supabase.example.test/auth/v1/user' => Http::response([
            'id' => $sellerId,
        ]),
    ]);

    $response = $this->withToken('valid-supabase-token')->postJson('/api/seller/products', [
        'name' => 'New Product',
        'description' => 'A newly submitted product.',
        'subcategory' => 'Phones & Tablets',
        'variants' => [
            [
                'option_values' => [],
                'price' => 149.99,
                'stock' => 12,
            ],
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending_review');

    $product = Product::query()->sole();

    $this->assertModelExists($product);
    expect($product->seller_id)->toBe($sellerId)
        ->and($product->status)->toBe('pending_review')
        // Category is never accepted from the client — it's always the
        // seller's own line_of_business, forced server-side.
        ->and($product->category)->toBe('Electronics and Gadgets');

    Queue::assertPushed(
        ModerateProductJob::class,
        fn (ModerateProductJob $job): bool => $job->productId === $product->id,
    );
});

it('re-queues moderation when an existing product is edited', function () {
    $sellerId = createSellerWithLineOfBusiness('Electronics and Gadgets');

    // Seeded directly (not via the create endpoint) so this test's own
    // dispatch is the only one — ModerateProductJob is ShouldBeUnique,
    // and Queue::fake() never runs/releases it, so a create-then-update
    // in the same test would see the update's dispatch silently
    // suppressed by the create's still-held lock.
    $product = Product::query()->create([
        'seller_id' => $sellerId,
        'name' => 'Existing Product',
        'category' => 'Electronics and Gadgets',
        'subcategory' => 'Phones & Tablets',
        'price' => 100,
        'images' => [],
        'status' => 'active',
    ]);

    Http::fake([
        'https://supabase.example.test/auth/v1/user' => Http::response([
            'id' => $sellerId,
        ]),
    ]);

    $updateResponse = $this->withToken('valid-supabase-token')->putJson("/api/seller/products/{$product->id}", [
        'name' => 'Updated Product Name',
        'subcategory' => 'Phones & Tablets',
        'variants' => [
            ['option_values' => [], 'price' => 120, 'stock' => 5],
        ],
    ]);

    $updateResponse->assertOk()
        ->assertJsonPath('data.status', 'pending_review');

    expect($product->fresh()->status)->toBe('pending_review');

    Queue::assertPushed(
        ModerateProductJob::class,
        fn (ModerateProductJob $job): bool => $job->productId === $product->id,
    );
});

function createSellerWithLineOfBusiness(string $lineOfBusiness): string
{
    $sellerId = (string) Str::uuid();

    Profile::query()->create([
        'id' => $sellerId,
        'role' => 'seller',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Seller',
        'email' => 'seller@example.test',
    ]);

    DB::table('seller_details')->insert([
        'profile_id' => $sellerId,
        'business_name' => 'Test Store',
        'line_of_business' => $lineOfBusiness,
    ]);

    return $sellerId;
}
