<?php

namespace App\Models;

use App\Enums\AiModerationStatus;
use App\Enums\ProductModerationOutcome;
use Database\Factories\ProductModerationLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductModerationLog extends Model
{
    /** @use HasFactory<ProductModerationLogFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'product_id',
        'ai_status',
        'ai_confidence_score',
        'ai_flagged_signals',
        'ai_reasoning',
        'final_status',
        'needs_human_review',
    ];

    protected $casts = [
        'ai_status' => AiModerationStatus::class,
        'ai_confidence_score' => 'decimal:4',
        'ai_flagged_signals' => 'array',
        'final_status' => ProductModerationOutcome::class,
        'needs_human_review' => 'boolean',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
