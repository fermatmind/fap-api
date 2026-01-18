<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Event extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'event_code',
        'anon_id',
        'attempt_id',
        'meta_json',
        'occurred_at',
        'share_id',
    ];

    protected $casts = [
        'meta_json'   => 'array',
        'occurred_at' => 'datetime',
    ];
}