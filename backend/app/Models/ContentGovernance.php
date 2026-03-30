<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentGovernance extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'content_governance';

    protected $fillable = [
        'org_id',
        'page_type',
        'primary_query',
        'canonical_target',
        'hub_ref',
        'test_binding',
        'method_binding',
        'cta_stage',
        'author_admin_user_id',
        'reviewer_admin_user_id',
        'publish_gate_state',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'author_admin_user_id' => 'integer',
        'reviewer_admin_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function governable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'author_admin_user_id', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reviewer_admin_user_id', 'id');
    }
}
