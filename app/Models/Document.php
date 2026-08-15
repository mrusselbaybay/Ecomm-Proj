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
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    // Configure a 'supabase' disk (S3-compatible) in config/filesystems.php
    // pointing at your Supabase Storage bucket "documents".
    public function getUrlAttribute(): string
    {
        return Storage::disk('supabase')->temporaryUrl($this->storage_path, now()->addMinutes(10));
    }

    public function reviewer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewed_by');
    }
}