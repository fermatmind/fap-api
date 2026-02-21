<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NormSource extends Model
{
    protected $table = 'norm_sources';

    protected $primaryKey = 'source_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'source_id',
        'title',
        'citation',
        'homepage_url',
        'license',
        'notes_json',
    ];

    protected $casts = [
        'notes_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
