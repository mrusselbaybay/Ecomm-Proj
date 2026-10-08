<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerComplianceAction extends Model
{
    use HasUuids;

    protected $fillable = [
        'seller_id',
        'product_id',
        'action',
        'reason',
        'notes',
        'admin_id',
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'admin_id');
    }
}
