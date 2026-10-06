<?php

namespace App\Services;

use App\Enums\AiModerationStatus;
use App\Models\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Throwable;
use UnexpectedValueException;

class ProductModerationClient
{
    /**
     * @return array{
     *     status: string,
     *     confidence_score: float,
     *     flagged_signals: list<string>,
     *     reasoning: string
     * }
     */
    public function moderate(Product $product): array
    {
        $url = config('services.product_moderation.url');
        $token = config('services.product_moderation.token');
        $model = config('services.product_moderation.model');

        if (! is_string($url) || $url === '') {
            throw new LogicException('The OpenAI moderation URL is not configured.');
        }

        if (! is_string($token) || $token === '') {
            throw new LogicException('The OpenAI API key is not configured.');
        }

        if (! is_string($model) || $model === '') {
            throw new LogicException('The OpenAI moderation model is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout((int) config('services.product_moderation.connect_timeout', 3))
            ->timeout((int) config('services.product_moderation.timeout', 15))
            ->retry(
                [250, 1000],
                when: function (Throwable $exception, PendingRequest $request): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->status() === 429 || $exception->response->serverError());
                },
            )
            ->post($url, $this->payload($product, $model))
            ->throw();

        $responseData = $response->json();

        if (! is_array($responseData)) {
            throw new UnexpectedValueException('The product moderation response must be a JSON object.');
        }

        /** @var array{results: list<array{flagged: bool, categories: array<string, bool|null>, category_scores: array<string, float|int|null>}>} $validated */
        $validated = Validator::make($responseData, [
            'results' => ['required', 'array', 'size:1'],
            'results.0.flagged' => ['required', 'boolean'],
            'results.0.categories' => ['required', 'array', 'min:1'],
            'results.0.categories.*' => ['nullable', 'boolean'],
            'results.0.category_scores' => ['required', 'array', 'min:1'],
            'results.0.category_scores.*' => ['nullable', 'numeric', 'between:0,1'],
        ])->validate();

        return $this->mapResult($validated['results'][0]);
    }

    /**
     * @return array{model: string, input: list<array<string, mixed>>}
     */
    private function payload(Product $product, string $model): array
    {
        $input = [
            [
                'type' => 'text',
                'text' => $this->productText($product),
            ],
        ];

        foreach ($this->imageUrls($product) as $imageUrl) {
            $input[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl,
                ],
            ];
        }

        return [
            'model' => $model,
            'input' => $input,
        ];
    }

    private function productText(Product $product): string
    {
        $fields = [
            'Product name' => $product->name,
            'Description' => $product->description,
            'Category' => $product->category,
            'Subcategory' => $product->subcategory,
            'Brand' => $product->brand,
            'Condition' => $product->condition,
        ];

        $lines = [];

        foreach ($fields as $label => $value) {
            if (is_string($value) && trim($value) !== '') {
                $lines[] = $label.': '.trim($value);
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function imageUrls(Product $product): array
    {
        $imageUrls = [];

        foreach ($product->images ?? [] as $image) {
            $url = $image['url'] ?? null;

            if (! is_string($url)) {
                continue;
            }

            $url = trim($url);

            if ($url === '' || strlen($url) > 5_242_880 || ! $this->isSupportedImageUrl($url)) {
                continue;
            }

            $imageUrls[] = $url;
        }

        return $imageUrls;
    }

    private function isSupportedImageUrl(string $url): bool
    {
        if (str_starts_with($url, 'data:image/')) {
            return true;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /**
     * @param  array{flagged: bool, categories: array<string, bool|null>, category_scores: array<string, float|int|null>}  $result
     * @return array{status: string, confidence_score: float, flagged_signals: list<string>, reasoning: string}
     */
    private function mapResult(array $result): array
    {
        $flaggedSignals = [];

        foreach ($result['categories'] as $category => $isFlagged) {
            if ($isFlagged === true) {
                $flaggedSignals[] = $category;
            }
        }

        if ($result['flagged'] && $flaggedSignals === []) {
            $flaggedSignals[] = 'openai_flagged_content';
        }

        [$highestCategory, $highestScore] = $this->highestCategoryScore($result['category_scores']);
        $requiresReview = $result['flagged'] || $flaggedSignals !== [];

        return [
            'status' => $requiresReview
                ? AiModerationStatus::NeedsReview->value
                : AiModerationStatus::Approve->value,
            'confidence_score' => round($requiresReview ? $highestScore : 1 - $highestScore, 4),
            'flagged_signals' => $flaggedSignals,
            'reasoning' => $requiresReview
                ? sprintf(
                    'OpenAI moderation flagged these categories for human review: %s. Highest category score: %s (%.4f).',
                    implode(', ', $flaggedSignals),
                    $highestCategory,
                    $highestScore,
                )
                : sprintf(
                    'OpenAI moderation did not flag a supported harmful-content category. Highest category score: %s (%.4f).',
                    $highestCategory,
                    $highestScore,
                ),
        ];
    }

    /**
     * @param  array<string, float|int|null>  $categoryScores
     * @return array{string, float}
     */
    private function highestCategoryScore(array $categoryScores): array
    {
        $highestCategory = null;
        $highestScore = -1.0;

        foreach ($categoryScores as $category => $score) {
            if (! is_int($score) && ! is_float($score)) {
                continue;
            }

            if ((float) $score > $highestScore) {
                $highestCategory = $category;
                $highestScore = (float) $score;
            }
        }

        if ($highestCategory === null) {
            throw new UnexpectedValueException('The OpenAI moderation response did not contain a category score.');
        }

        return [$highestCategory, $highestScore];
    }
}
