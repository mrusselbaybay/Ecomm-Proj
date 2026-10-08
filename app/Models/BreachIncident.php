<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\BreachIncidentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BreachIncident extends Model
{
    /** @use HasFactory<BreachIncidentFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'detected_at', 'reported_to_npc_at', 'affected_count',
        'description', 'status', 'reported_by',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
        'reported_to_npc_at' => 'datetime',
        'affected_count' => 'integer',
    ];
}
