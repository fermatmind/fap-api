<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScaleSlug extends Model
{
    use HasFactory;

    protected $table = 'scale_slugs';

    protected $fillable = [
        'org_id',
        'slug',
        'scale_code',
        'is_primary',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'is_primary' => 'boolean',
    ];
}
