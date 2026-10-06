<?php

use App\Jobs\ModerateProductJob;
use App\Models\ProductModerationLog;
use Illuminate\Support\Facades\Http;

it('records the moderation result for a pending seller product', function () {
    config([
        'services.product_moderation.enabled' => true,
        'services.product_moderation.mode' => 'review_only',
        'services.product_moderation.url' => 'https://api.openai.test/v1/moderations',
        'services.product_moderation.token' => 'test-key',
        'services.product_moderation.model' => 'omni-moderation-latest',
    ]);

    Http::fake([
        'https://api.openai.test/v1/moderations' => Http::response([
            'results' => [[
                'flagged' => true,
                'categories' => [
                    'violence' => true,
                    'sexual' => false,
                ],
                'category_scores' => [
                    'violence' => 0.91,
                    'sexual' => 0.02,
                ],
            ]],
        ]),
    ]);

    $product = makeProduct(makeSeller(), [
        'name' => 'Product requiring review',
        'status' => 'pending_review',
    ]);

    app()->call([(new ModerateProductJob($product->id)), 'handle']);

    $moderation = ProductModerationLog::query()->where('product_id', $product->id)->sole();

    expect($moderation->ai_status->value)->toBe('NEEDS_REVIEW')
        ->and($moderation->ai_flagged_signals)->toBe(['violence'])
        ->and($moderation->needs_human_review)->toBeTrue()
        ->and($product->refresh()->status)->toBe('pending_review');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/moderations'
        && $request['model'] === 'omni-moderation-latest');
});
