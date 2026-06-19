<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class ReportSnapshot extends Model
{
    use HasOrgScope;

    protected $table = 'report_snapshots';

    protected $primaryKey = 'attempt_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'org_id',
        'attempt_id',
        'order_no',
        'scale_code',
        'scale_code_v2',
        'scale_uid',
        'pack_id',
        'dir_version',
        'scoring_spec_version',
        'report_engine_version',
        'big5_result_page_v2_status',
        'big5_result_page_v2_fallback_reason',
        'big5_result_page_v2_validation_error_count',
        'big5_result_page_v2_audited_at',
        'snapshot_version',
        'report_json',
        'report_free_json',
        'report_full_json',
        'status',
        'last_error',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'big5_result_page_v2_validation_error_count' => 'integer',
        'big5_result_page_v2_audited_at' => 'datetime',
        'report_json' => 'array',
        'report_free_json' => 'array',
        'report_full_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $snapshot): void {
            if (! $snapshot->exists) {
                return;
            }

            if ($snapshot->isDirty('attempt_id') || $snapshot->isDirty('org_id')) {
                throw new \LogicException('Report snapshot identity fields are immutable after creation.');
            }
        });
    }

    public static function publicContextOrgId(): ?int
    {
        return self::resolvedPublicContextOrgId();
    }
}
