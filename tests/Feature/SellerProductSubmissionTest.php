<?php

use App\Jobs\ModerateProductJob;
use App\Models\Product;
use App\Models\Profile;
use Illuminate\Database\Schema\Blueprint;
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

    config([
        'services.supabase.url' => 'https://supabase.example.test',
        'services.supabase.anon_key' => 'test-anon-key',
        'services.product_moderation.enabled' => true,
    ]);

    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function () {
    Schema::dropIfExists('products');
    Schema::dropIfExists('profiles');
});

it('creates a pending product and queues moderation for an active seller', function () {
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

    Http::fake([
        'https://supabase.example.test/auth/v1/user' => Http::response([
            'id' => $sellerId,
        ]),
    ]);

    $response = $this->withToken('valid-supabase-token')->postJson('/api/seller/products', [
        'name' => 'New Product',
        'description' => 'A newly submitted product.',
        'category' => 'Electronics and Gadgets',
        'price' => 149.99,
        'stock' => 12,
        'images' => [],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending_review');

    $product = Product::query()->sole();

    $this->assertModelExists($product);
    expect($product->seller_id)->toBe($sellerId)
        ->and($product->status)->toBe('pending_review');

    Queue::assertPushed(
        ModerateProductJob::class,
        fn (ModerateProductJob $job): bool => $job->productId === $product->id,
    );
});

it('keeps the submission endpoint inert while moderation is disabled', function () {
    config(['services.product_moderation.enabled' => false]);

    $sellerId = (string) Str::uuid();

    Profile::query()->create([
        'id' => $sellerId,
        'role' => 'seller',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Seller',
    ]);

    Http::fake([
        'https://supabase.example.test/auth/v1/user' => Http::response([
            'id' => $sellerId,
        ]),
    ]);

    $this->withToken('valid-supabase-token')
        ->postJson('/api/seller/products', [
            'name' => 'Disabled Product',
            'category' => 'Electronics and Gadgets',
            'price' => 50,
            'stock' => 2,
        ])
        ->assertServiceUnavailable();

    expect(Product::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});
