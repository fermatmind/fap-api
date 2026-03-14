<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class AnalyticsAxisDaily extends Model
{
    use HasOrgScope;

    protected $table = 'analytics_axis_daily';

    protected $fillable = [
        'day',
        'org_id',
        'locale',
        'region',
        'scale_code',
        'content_package_version',
        'scoring_spec_version',
        'norm_version',
        'axis_code',
        'side_code',
        'results_count',
        'distinct_attempts_with_results',
        'last_refreshed_at',
    ];

    protected $casts = [
        'day' => 'date',
        'org_id' => 'integer',
        'results_count' => 'integer',
        'distinct_attempts_with_results' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
