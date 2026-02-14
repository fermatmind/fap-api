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
        'pack_id',
        'dir_version',
        'scoring_spec_version',
        'report_engine_version',
        'snapshot_version',
        'report_json',
        'status',
        'last_error',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'report_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
