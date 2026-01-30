<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'rules_json',
        'is_active',
    ];

    protected $casts = [
        'rules_json' => 'array',
        'is_active' => 'boolean',
    ];
}
