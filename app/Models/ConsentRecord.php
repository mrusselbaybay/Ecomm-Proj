<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id', 'guest_id', 'consent_type', 'policy_version',
        'categories_json', 'action', 'ip', 'user_agent', 'created_at',
    ];

    protected $casts = [
        'categories_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }
}
