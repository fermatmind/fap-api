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
        'event_name',
        'org_id',
        'user_id',
        'anon_id',
        'session_id',
        'request_id',
        'attempt_id',
        'meta_json',
        'occurred_at',
        'share_id',
        'share_channel',
        'share_click_id',

        // funnel columns
        'scale_code',
        'scale_version',
        'channel',
        'region',
        'locale',
        'client_platform',
        'client_version',
        'question_id',
        'question_index',
        'duration_ms',
        'is_dropoff',
        'pack_id',
        'dir_version',
        'pack_semver',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'referrer',
    ];

    protected $casts = [
        'meta_json'   => 'array',
        'occurred_at' => 'datetime',
        'org_id'      => 'integer',
        'user_id'     => 'integer',
        'question_index' => 'integer',
        'duration_ms' => 'integer',
        'is_dropoff' => 'integer',
    ];
}
