<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SeoQualityAudit extends Model
{
    use HasFactory, HasOrgScope;

    public const TYPE_CITATION_QA = 'citation_qa';

    public const TYPE_MONTHLY_PATROL = 'monthly_patrol';

    public const STATUS_PASSED = 'passed';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    protected $table = 'seo_quality_audits';

    protected $fillable = [
        'org_id',
        'audit_type',
        'subject_type',
        'subject_id',
        'scope_key',
        'status',
        'summary_json',
        'findings_json',
        'actor_admin_user_id',
        'audited_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'subject_id' => 'integer',
        'actor_admin_user_id' => 'integer',
        'summary_json' => 'array',
        'findings_json' => 'array',
        'audited_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'actor_admin_user_id', 'id');
    }
}
