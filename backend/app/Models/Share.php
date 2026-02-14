<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Share extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $table = 'shares';

    protected $fillable = [
        'id',
        'attempt_id',
        'anon_id',
        'scale_code',
        'scale_version',
        'content_package_version',
    ];

    public function attempt()
    {
        return $this->belongsTo(Attempt::class, 'attempt_id', 'id');
    }
}
