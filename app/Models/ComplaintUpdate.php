<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $complaint_id
 * @property string|null $admin_id
 * @property string|null $old_status
 * @property string $new_status
 * @property string $notes
 * @property bool $is_internal
 * @property-read Profile|null $admin
 */
class ComplaintUpdate extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'complaint_id', 'admin_id', 'old_status', 'new_status', 'notes', 'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    /** @return BelongsTo<Complaint, $this> */
    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'admin_id');
    }
}
