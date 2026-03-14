<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class AnalyticsScaleQualityDaily extends Model
{
    use HasOrgScope;

    protected $table = 'analytics_scale_quality_daily';

    protected $fillable = [
        'day',
        'org_id',
        'scale_code',
        'locale',
        'region',
        'content_package_version',
        'scoring_spec_version',
        'norm_version',
        'started_attempts',
        'completed_attempts',
        'results_count',
        'valid_results_count',
        'invalid_results_count',
        'quality_a_count',
        'quality_b_count',
        'quality_c_count',
        'quality_d_count',
        'crisis_alert_count',
        'speeding_count',
        'longstring_count',
        'straightlining_count',
        'extreme_count',
        'inconsistency_count',
        'warnings_count',
        'last_refreshed_at',
    ];

    protected $casts = [
        'day' => 'date',
        'org_id' => 'integer',
        'started_attempts' => 'integer',
        'completed_attempts' => 'integer',
        'results_count' => 'integer',
        'valid_results_count' => 'integer',
        'invalid_results_count' => 'integer',
        'quality_a_count' => 'integer',
        'quality_b_count' => 'integer',
        'quality_c_count' => 'integer',
        'quality_d_count' => 'integer',
        'crisis_alert_count' => 'integer',
        'speeding_count' => 'integer',
        'longstring_count' => 'integer',
        'straightlining_count' => 'integer',
        'extreme_count' => 'integer',
        'inconsistency_count' => 'integer',
        'warnings_count' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
