<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalHold extends Model
{
    protected $table = 'legal_holds';

    protected $fillable = [
        'scope_type',
        'scope_id',
        'reason_code',
        'placed_by',
        'active_from',
        'released_at',
    ];

    protected $casts = [
        'active_from' => 'datetime',
        'released_at' => 'datetime',
    ];
}
