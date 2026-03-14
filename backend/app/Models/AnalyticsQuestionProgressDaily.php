<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class AnalyticsQuestionProgressDaily extends Model
{
    use HasOrgScope;

    protected $table = 'analytics_question_progress_daily';

    protected $fillable = [
        'day',
        'org_id',
        'locale',
        'region',
        'scale_code',
        'content_package_version',
        'scoring_spec_version',
        'norm_version',
        'question_id',
        'question_order',
        'reached_attempts_count',
        'answered_attempts_count',
        'completed_attempts_count',
        'dropoff_attempts_count',
        'last_refreshed_at',
    ];

    protected $casts = [
        'day' => 'date',
        'org_id' => 'integer',
        'question_order' => 'integer',
        'reached_attempts_count' => 'integer',
        'answered_attempts_count' => 'integer',
        'completed_attempts_count' => 'integer',
        'dropoff_attempts_count' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
