<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmailSuppression extends Model
{
    use HasUuids;

    protected $table = 'email_suppressions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'email_hash',
        'reason',
        'source',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
