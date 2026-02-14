<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportJob extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'report_jobs';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'attempt_id',
        'status',
        'tries',
        'available_at',
        'started_at',
        'finished_at',
        'failed_at',
        'last_error',
        'last_error_trace',
        'report_json',
        'meta',
    ];

    protected $casts = [
        'tries' => 'integer',
        'available_at' => 'datetime',
        'org_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'failed_at' => 'datetime',
        'report_json' => 'array',
        'meta' => 'array',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
