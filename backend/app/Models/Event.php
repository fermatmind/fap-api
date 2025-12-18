<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    protected $table = 'events';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'event_code',
        'anon_id',
        'attempt_id',
        'occurred_at',
        'meta_json',
    ];

    protected $casts = [
        'meta_json'   => 'array',
        'occurred_at' => 'datetime',
    ];
}