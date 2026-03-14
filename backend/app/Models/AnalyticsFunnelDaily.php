<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class AnalyticsFunnelDaily extends Model
{
    use HasOrgScope;

    protected $table = 'analytics_funnel_daily';

    protected $fillable = [
        'day',
        'org_id',
        'scale_code',
        'locale',
        'started_attempts',
        'submitted_attempts',
        'first_view_attempts',
        'order_created_attempts',
        'paid_attempts',
        'paid_revenue_cents',
        'unlocked_attempts',
        'report_ready_attempts',
        'pdf_download_attempts',
        'share_generated_attempts',
        'share_click_attempts',
        'last_refreshed_at',
    ];

    protected $casts = [
        'day' => 'date',
        'org_id' => 'integer',
        'started_attempts' => 'integer',
        'submitted_attempts' => 'integer',
        'first_view_attempts' => 'integer',
        'order_created_attempts' => 'integer',
        'paid_attempts' => 'integer',
        'paid_revenue_cents' => 'integer',
        'unlocked_attempts' => 'integer',
        'report_ready_attempts' => 'integer',
        'pdf_download_attempts' => 'integer',
        'share_generated_attempts' => 'integer',
        'share_click_attempts' => 'integer',
        'last_refreshed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
