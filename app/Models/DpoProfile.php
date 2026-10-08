<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\DpoProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DpoProfile extends Model
{
    /** @use HasFactory<DpoProfileFactory> */
    use HasFactory;

    use HasUuidPrimaryKey;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'dpo_profile';

    protected $fillable = ['name', 'email', 'address', 'npc_registration_no'];
}
