<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'owner_user_id',
        'status',
        'domain',
        'timezone',
        'locale',
    ];

    protected $casts = [
        'id' => 'integer',
        'owner_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
