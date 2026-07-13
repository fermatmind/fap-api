<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PublicContentRuntimeDaily extends Model
{
    protected $table = 'public_content_runtime_daily';

    protected $fillable = [
        'day',
        'route_family',
        'priority',
        'locale',
        'request_count',
        'success_count',
        'not_found_count',
        'rate_limited_count',
        'client_error_count',
        'server_error_count',
        'timeout_count',
        'duration_count',
        'duration_sum_ms',
        'duration_max_ms',
        'duration_histogram',
        'rolled_minutes',
        'last_success_at',
        'last_failure_at',
    ];

    protected function casts(): array
    {
        return [
            'request_count' => 'integer',
            'success_count' => 'integer',
            'not_found_count' => 'integer',
            'rate_limited_count' => 'integer',
            'client_error_count' => 'integer',
            'server_error_count' => 'integer',
            'timeout_count' => 'integer',
            'duration_count' => 'integer',
            'duration_sum_ms' => 'float',
            'duration_max_ms' => 'float',
            'duration_histogram' => 'array',
            'rolled_minutes' => 'array',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }
}
