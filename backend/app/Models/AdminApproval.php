<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminApproval extends Model
{
    use HasOrgScope;
    use HasUuids;

    protected $table = 'admin_approvals';

    public $incrementing = false;

    protected $keyType = 'string';

    public const TYPE_MANUAL_GRANT = 'MANUAL_GRANT';

    public const TYPE_REVOKE_BENEFIT = 'REVOKE_BENEFIT';

    public const TYPE_REFUND = 'REFUND';

    public const TYPE_REPROCESS_EVENT = 'REPROCESS_EVENT';

    public const TYPE_ROLLBACK_RELEASE = 'ROLLBACK_RELEASE';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_EXECUTING = 'EXECUTING';

    public const STATUS_EXECUTED = 'EXECUTED';

    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'id',
        'org_id',
        'type',
        'status',
        'requested_by_admin_user_id',
        'approved_by_admin_user_id',
        'reason',
        'payload_json',
        'correlation_id',
        'approved_at',
        'executed_at',
        'error_code',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'payload_json' => 'array',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by_admin_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_user_id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }
}
