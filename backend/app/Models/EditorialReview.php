<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EditorialReview extends Model
{
    use HasOrgScope;
    use HasUuids;

    public const STATE_DRAFTING = 'drafting';

    public const STATE_IN_REVIEW = 'in_review';

    public const STATE_CHANGES_REQUESTED = 'changes_requested';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    protected $table = 'editorial_reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'content_type',
        'content_id',
        'workflow_state',
        'owner_admin_user_id',
        'reviewer_admin_user_id',
        'submitted_by_admin_user_id',
        'reviewed_by_admin_user_id',
        'submitted_at',
        'reviewed_at',
        'last_transition_at',
        'note',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'content_id' => 'integer',
        'owner_admin_user_id' => 'integer',
        'reviewer_admin_user_id' => 'integer',
        'submitted_by_admin_user_id' => 'integer',
        'reviewed_by_admin_user_id' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'last_transition_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'owner_admin_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_admin_user_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'submitted_by_admin_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewed_by_admin_user_id');
    }

    public static function allowOrgZeroContext(): bool
    {
        return self::allowOrgZeroWithResolvedContext();
    }
}
