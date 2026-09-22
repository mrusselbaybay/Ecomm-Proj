<?php

use App\Models\Product;
use App\Models\ProductModerationLog;
use App\Models\Profile;
use App\Models\SellerComplianceAction;
use App\Models\SellerNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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

    // Supabase-managed in production (no Laravel migration owns it — see
    // 2025_01_01_000000_disable_db_level_status_audit_triggers.php); the
    // suspend action writes to it directly, so it needs to exist here too.
    Schema::create('status_audit_log', function (Blueprint $table) {
        $table->id();
        $table->string('entity_type');
        $table->uuid('entity_id');
        $table->string('old_status')->nullable();
        $table->string('new_status');
        $table->text('reason')->nullable();
        $table->uuid('changed_by')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('seller_notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('seller_id');
        $table->string('type', 40);
        $table->string('title');
        $table->text('body')->nullable();
        $table->text('data')->nullable();
        $table->uuid('order_id')->nullable();
        $table->string('dedupe_key')->nullable();
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
        $table->unique(['seller_id', 'dedupe_key']);
    });

    config([
        'services.supabase.url' => 'https://supabase.example.test',
        'services.supabase.anon_key' => 'test-anon-key',
    ]);

    Http::preventStrayRequests();
});

afterEach(function () {
    Schema::dropIfExists('seller_notifications');
    Schema::dropIfExists('status_audit_log');
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

it('shows an AI auto-verified product in compliance history, attributed to AI Moderation', function () {
    $admin = authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id, status: 'active');

    // Mirrors exactly what ModerateProductJob writes for an auto-approved
    // product: a verify action with no admin attached.
    SellerComplianceAction::query()->create([
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'action' => 'verify',
        'reason' => null,
        'notes' => 'Auto-processed by AI moderation: clean.',
        'admin_id' => null,
    ]);

    $response = $this->withToken('valid-supabase-token')
        ->getJson('/api/admin/compliance/products?history=1');

    $response->assertOk();

    $productPayload = collect($response->json('products.data'))->firstWhere('id', $product->id);

    expect($productPayload)->not->toBeNull()
        ->and($productPayload['compliance_actions'][0]['action'])->toBe('verify')
        ->and($productPayload['compliance_actions'][0]['admin'])->toBe('AI Moderation');
});

it('does not still show an AI auto-verified product in the main review queue', function () {
    authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id, status: 'active');

    SellerComplianceAction::query()->create([
        'seller_id' => $seller->id,
        'product_id' => $product->id,
        'action' => 'verify',
        'admin_id' => null,
    ]);

    $response = $this->withToken('valid-supabase-token')
        ->getJson('/api/admin/compliance/products');

    $response->assertOk();

    expect(collect($response->json('products.data'))->firstWhere('id', $product->id))->toBeNull();
});

it('verifying a product records compliance history and notifies the seller', function () {
    Mail::fake();
    $admin = authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id);

    $response = $this->withToken('valid-supabase-token')
        ->postJson("/api/admin/compliance/products/{$product->id}/actions", [
            'action' => 'verify',
        ]);

    $response->assertOk();

    expect($product->fresh()->status)->toBe('active');

    $action = SellerComplianceAction::query()->where('product_id', $product->id)->first();
    expect($action->action)->toBe('verify')
        ->and($action->admin_id)->toBe($admin->id);

    $notification = SellerNotification::query()->where('seller_id', $seller->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->type)->toBe('product_verified')
        ->and($notification->dedupe_key)->toBe("product_verified:{$action->id}");
});

it('verifying an already-active product is a no-op and does not duplicate history or notifications', function () {
    Mail::fake();
    authenticatedAdmin();
    $seller = createSellerProfile();
    $product = createProductForCompliance($seller->id);

    $endpoint = "/api/admin/compliance/products/{$product->id}/actions";
    $this->withToken('valid-supabase-token')->postJson($endpoint, ['action' => 'verify'])->assertOk();
    $this->withToken('valid-supabase-token')->postJson($endpoint, ['action' => 'verify'])->assertOk();

    expect(SellerComplianceAction::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and(SellerNotification::query()->where('seller_id', $seller->id)->count())->toBe(1);
});

it('flagging, removing, and suspending each record compliance history and notify the seller', function () {
    Mail::fake();
    authenticatedAdmin();

    $sellerForFlag = createSellerProfile();
    $productForFlag = createProductForCompliance($sellerForFlag->id);
    $this->withToken('valid-supabase-token')
        ->postJson("/api/admin/compliance/products/{$productForFlag->id}/actions", [
            'action' => 'warn',
            'reason' => 'Misleading product images.',
        ])->assertOk();

    $sellerForRemove = createSellerProfile();
    $productForRemove = createProductForCompliance($sellerForRemove->id);
    $this->withToken('valid-supabase-token')
        ->postJson("/api/admin/compliance/products/{$productForRemove->id}/actions", [
            'action' => 'remove',
            'reason' => 'Prohibited item.',
        ])->assertOk();

    $sellerForSuspend = createSellerProfile();
    $productForSuspend = createProductForCompliance($sellerForSuspend->id);
    $this->withToken('valid-supabase-token')
        ->postJson("/api/admin/compliance/products/{$productForSuspend->id}/actions", [
            'action' => 'suspend',
            'reason' => 'Repeated policy violations.',
        ])->assertOk();

    expect($productForRemove->fresh()->status)->toBe('archived')
        ->and($sellerForSuspend->fresh()->account_status)->toBe('suspended');

    expect(SellerNotification::query()->where('seller_id', $sellerForFlag->id)->value('type'))->toBe('product_flagged')
        ->and(SellerNotification::query()->where('seller_id', $sellerForRemove->id)->value('type'))->toBe('product_removed')
        ->and(SellerNotification::query()->where('seller_id', $sellerForSuspend->id)->value('type'))->toBe('seller_suspended');
});

it('verify all bulk-verifies every pending product through the same logic as a manual verify', function () {
    Mail::fake();
    $admin = authenticatedAdmin();

    $sellerA = createSellerProfile();
    $productA = createProductForCompliance($sellerA->id);
    $sellerB = createSellerProfile();
    $productB = createProductForCompliance($sellerB->id);
    // An already-active product must be left alone by Verify All.
    $sellerC = createSellerProfile();
    $productC = createProductForCompliance($sellerC->id, status: 'active');

    $response = $this->withToken('valid-supabase-token')
        ->postJson('/api/admin/compliance/products/verify-all');

    $response->assertOk();
    expect($response->json('verified'))->toBe(2);

    expect($productA->fresh()->status)->toBe('active')
        ->and($productB->fresh()->status)->toBe('active');

    $actionA = SellerComplianceAction::query()->where('product_id', $productA->id)->first();
    $actionB = SellerComplianceAction::query()->where('product_id', $productB->id)->first();

    expect($actionA->action)->toBe('verify')
        ->and($actionA->admin_id)->toBe($admin->id)
        ->and($actionB->action)->toBe('verify')
        // productC was already active — verify-all must not touch it.
        ->and(SellerComplianceAction::query()->where('product_id', $productC->id)->count())->toBe(0);

    expect(SellerNotification::query()->where('seller_id', $sellerA->id)->where('type', 'product_verified')->exists())->toBeTrue()
        ->and(SellerNotification::query()->where('seller_id', $sellerB->id)->where('type', 'product_verified')->exists())->toBeTrue();
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

function createProductForCompliance(string $sellerId, string $status = 'pending_review'): Product
{
    $product = new Product([
        'seller_id' => $sellerId,
        'name' => 'Test Product '.Str::random(6),
        'description' => 'A product awaiting compliance review.',
        'category' => 'Electronics and Gadgets',
        'price' => 149.99,
        'images' => [],
        'status' => $status,
    ]);
    $product->id = (string) Str::uuid();
    $product->save();

    return $product;
}
