<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Event extends Model
{
    use HasUuids;

    public $incrementing = false;
    protected $keyType = "string";

    protected $fillable = [
        "event_code",
        "anon_id",
        "attempt_id",
        "meta_json",
    ];

    protected $casts = [
        "meta_json" => "array",
    ];
}
