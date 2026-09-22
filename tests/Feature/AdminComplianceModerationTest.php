<?php

use App\Models\Product;
use App\Models\ProductModerationLog;
use App\Models\Profile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
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
        $table->timestamps();
    });

    Schema::create('product_moderation_logs', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('product_id')->nullable();
        $table->string('ai_status');
        $table->decimal('ai_confidence_score', 5, 4);
        $table->json('ai_flagged_signals');
        $table->text('ai_reasoning');
        $table->string('final_status');
        $table->boolean('needs_human_review');
        $table->timestamps();
    });

    Schema::create('seller_compliance_actions', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('seller_id');
        $table->uuid('product_id')->nullable();
        $table->string('action', 40);
        $table->text('reason')->nullable();
        $table->text('notes')->nullable();
        $table->uuid('admin_id')->nullable();
        $table->timestamps();
    });

    config([
        'services.supabase.url' => 'https://supabase.example.test',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::preventStrayRequests();
});

afterEach(function () {
    Schema::dropIfExists('seller_compliance_actions');
    Schema::dropIfExists('product_moderation_logs');
    Schema::dropIfExists('products');
    Schema::dropIfExists('seller_details');
    Schema::dropIfExists('profiles');
});

it('includes the latest moderation result for each product', function () {
    $admin = authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id);

    ProductModerationLog::query()->create([
        'id' => (string) Str::uuid(),
        'product_id' => $product->id,
        'ai_status' => 'NEEDS_REVIEW',
        'ai_confidence_score' => 0.6200,
        'ai_flagged_signals' => ['violence'],
        'ai_reasoning' => 'OpenAI moderation flagged this category for human review.',
        'final_status' => 'pending_human_review',
        'needs_human_review' => true,
    ]);

    $response = $this->withToken('valid-supabase-token')
        ->getJson('/api/admin/compliance/products');

    $response->assertOk();

    $productPayload = collect($response->json('products.data'))
        ->firstWhere('id', $product->id);

    expect($productPayload['moderation'])->toMatchArray([
        'ai_status' => 'NEEDS_REVIEW',
        'confidence_score' => 0.62,
        'flagged_signals' => ['violence'],
        'final_status' => 'pending_human_review',
        'needs_human_review' => true,
    ]);
});

it('returns a null moderation result for products that have not been reviewed', function () {
    authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id);

    $response = $this->withToken('valid-supabase-token')
        ->getJson('/api/admin/compliance/products');

    $response->assertOk();

    $productPayload = collect($response->json('products.data'))
        ->firstWhere('id', $product->id);

    expect($productPayload['moderation'])->toBeNull();
});

function authenticatedAdmin(): Profile
{
    $adminId = (string) Str::uuid();

    $admin = Profile::query()->create([
        'id' => $adminId,
        'role' => 'admin',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Admin',
        'last_name' => 'User',
        'email' => 'admin@example.test',
    ]);

    Http::fake([
        'https://supabase.example.test/auth/v1/user' => Http::response(['id' => $adminId]),
    ]);

    return $admin;
}

function createSellerProfile(): Profile
{
    return Profile::query()->create([
        'id' => (string) Str::uuid(),
        'role' => 'seller',
        'status' => 'approved',
        'account_status' => 'active',
        'first_name' => 'Test',
        'last_name' => 'Seller',
        'email' => 'seller@example.test',
    ]);
}

function createProductForCompliance(string $sellerId): Product
{
    $product = new Product([
        'seller_id' => $sellerId,
        'name' => 'Test Product',
        'description' => 'A product awaiting compliance review.',
        'category' => 'Electronics and Gadgets',
        'price' => 149.99,
        'images' => [],
        'status' => 'pending_review',
    ]);
    $product->id = (string) Str::uuid();
    $product->save();

    return $product;
}
