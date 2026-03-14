<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class AnalyticsQuestionOptionDaily extends Model
{
    use HasOrgScope;

    protected $table = 'analytics_question_option_daily';

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
        'option_key',
        'answered_rows_count',
        'distinct_attempts_answered',
        'last_refreshed_at',
    ];

    protected $casts = [
        'day' => 'date',
        'org_id' => 'integer',
        'question_order' => 'integer',
        'answered_rows_count' => 'integer',
        'distinct_attempts_answered' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
