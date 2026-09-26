<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $table = 'documents';
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'owner_kind', 'profile_id', 'logistics_company_id',
        'doc_type', 'storage_path', 'status', 'reviewed_by', 'reviewed_at',
        'id_type',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** Short-lived signed link to the private file. */
    public function getUrlAttribute(): ?string
    {
        return app(\App\Services\FileStorage::class)->createSignedUrl('documents', $this->storage_path, 600);
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewed_by');
    }
}