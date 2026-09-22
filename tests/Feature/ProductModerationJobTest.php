<?php

use App\Jobs\ModerateProductJob;
use App\Models\Product;
use App\Models\ProductModerationLog;
use App\Services\ProductApprovalRouter;
use App\Services\ProductModerationClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Schema::create('products', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('seller_id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('category')->nullable();
        $table->string('subcategory')->nullable();
        $table->string('brand')->nullable();
        $table->string('condition')->nullable();
        $table->decimal('price', 10, 2)->default(0);
        $table->json('images')->default('[]');
        $table->string('status')->default('pending_review');
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

    config([
        'services.product_moderation.enabled' => true,
        'services.product_moderation.mode' => 'review_only',
        'services.product_moderation.url' => 'https://api.openai.com/v1/moderations',
        'services.product_moderation.token' => 'test-token',
        'services.product_moderation.model' => 'omni-moderation-latest',
        'services.product_moderation.connect_timeout' => 1,
        'services.product_moderation.timeout' => 2,
    ]);

    Http::preventStrayRequests();
});

afterEach(function () {
    Schema::dropIfExists('product_moderation_logs');
    Schema::dropIfExists('products');
});

it('stores a clean OpenAI result for human review in review-only mode', function () {
    $product = createPendingProductForModeration();

    Http::fake([
        'https://api.openai.com/v1/moderations' => Http::response(openAiResponseForProductJob()),
    ]);

    runModerationJob($product);

    $moderationLog = ProductModerationLog::query()->first();

    expect($product->fresh()?->status)->toBe('pending_review')
        ->and(ProductModerationLog::query()->count())->toBe(1)
        ->and($moderationLog?->ai_status->value)->toBe('APPROVE')
        ->and($moderationLog?->final_status->value)->toBe('pending_human_review')
        ->and($moderationLog?->needs_human_review)->toBeTrue();

    Http::assertSent(function (Request $request) use ($product): bool {
        $requestData = $request->data();

        return $request->url() === 'https://api.openai.com/v1/moderations'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $requestData['model'] === 'omni-moderation-latest'
            && str_contains($requestData['input'][0]['text'], $product->name)
            && $requestData['input'][1]['image_url']['url'] === 'https://cdn.example.test/product.jpg'
            && count($requestData['input']) === 2;
    });
});

it('maps flagged OpenAI categories to a human review log', function () {
    $product = createPendingProductForModeration();

    Http::fake([
        'https://api.openai.com/v1/moderations' => Http::response(openAiResponseForProductJob(flagged: true)),
    ]);

    runModerationJob($product);

    $moderationLog = ProductModerationLog::query()->first();

    expect($product->fresh()?->status)->toBe('pending_review')
        ->and($moderationLog?->ai_status->value)->toBe('NEEDS_REVIEW')
        ->and($moderationLog?->ai_flagged_signals)->toBe(['violence'])
        ->and($moderationLog?->final_status->value)->toBe('pending_human_review');
});

it('only activates a clean product when automatic decisions are explicitly enabled', function () {
    config(['services.product_moderation.mode' => 'automatic']);

    $product = createPendingProductForModeration();

    Http::fake([
        'https://api.openai.com/v1/moderations' => Http::response(openAiResponseForProductJob()),
    ]);

    runModerationJob($product);

    expect($product->fresh()?->status)->toBe('active')
        ->and(ProductModerationLog::query()->first()?->final_status->value)->toBe('auto_approved');
});

it('leaves the product pending when the moderation API fails', function () {
    $product = createPendingProductForModeration();

    Http::fake([
        'https://api.openai.com/v1/moderations' => Http::response([
            'message' => 'Moderation service unavailable.',
        ], 503),
    ]);

    expect(fn () => runModerationJob($product))->toThrow(RequestException::class);

    expect($product->fresh()?->status)->toBe('pending_review')
        ->and(ProductModerationLog::query()->count())->toBe(0);
});

it('leaves the product pending when OpenAI returns an invalid response', function () {
    $product = createPendingProductForModeration();

    Http::fake([
        'https://api.openai.com/v1/moderations' => Http::response(['results' => []]),
    ]);

    expect(fn () => runModerationJob($product))->toThrow(ValidationException::class);

    expect($product->fresh()?->status)->toBe('pending_review')
        ->and(ProductModerationLog::query()->count())->toBe(0);
});

it('does not overwrite an administrator decision made during moderation', function () {
    $product = createPendingProductForModeration();

    Http::fake(function () use ($product) {
        Product::query()->whereKey($product->id)->update(['status' => 'archived']);

        return Http::response(openAiResponseForProductJob());
    });

    runModerationJob($product);

    expect($product->fresh()?->status)->toBe('archived')
        ->and(ProductModerationLog::query()->count())->toBe(0);
});

function createPendingProductForModeration(): Product
{
    $product = new Product([
        'seller_id' => (string) Str::uuid(),
        'name' => 'Test Product',
        'description' => 'A product submitted for automated moderation.',
        'category' => 'Electronics and Gadgets',
        'price' => 199.99,
        'images' => [
            ['url' => 'https://cdn.example.test/product.jpg', 'isNew' => false],
            ['url' => 'http://insecure.example.test/product.jpg', 'isNew' => false],
        ],
        'status' => 'pending_review',
    ]);
    $product->id = (string) Str::uuid();
    $product->save();

    return $product;
}

function runModerationJob(Product $product): void
{
    (new ModerateProductJob($product->id))->handle(
        app(ProductModerationClient::class),
        app(ProductApprovalRouter::class),
    );
}

/** @return array<string, mixed> */
function openAiResponseForProductJob(bool $flagged = false): array
{
    return [
        'id' => 'modr_test',
        'model' => 'omni-moderation-latest',
        'results' => [
            [
                'flagged' => $flagged,
                'categories' => [
                    'sexual' => false,
                    'violence' => $flagged,
                    'violence/graphic' => false,
                ],
                'category_scores' => [
                    'sexual' => 0.01,
                    'violence' => $flagged ? 0.99 : 0.03,
                    'violence/graphic' => 0.005,
                ],
            ],
        ],
    ];
}
