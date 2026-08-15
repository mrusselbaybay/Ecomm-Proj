<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StatusAuditLog extends Model
{
    protected $table = 'status_audit_log';
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'entity_type', 'entity_id', 'old_status', 'new_status', 'reason', 'changed_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function changedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Profile::class, 'changed_by');
    }
}