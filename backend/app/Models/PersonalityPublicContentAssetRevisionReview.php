<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PersonalityPublicContentAssetRevisionReview extends Model
{
    public const DECISION_APPROVED = 'approved';

    public const REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN = 'operator_supplied_human';

    protected $table = 'personality_public_content_asset_revision_reviews';

    protected $fillable = [
        'revision_id',
        'asset_id',
        'authority_asset_key',
        'source_package',
        'asset_sha256',
        'authority_package_sha256',
        'review_register_sha256',
        'reviewer_name',
        'reviewed_at',
        'decision',
        'review_source',
        'evidence_sha256',
        'bound_by_admin_user_id',
    ];

    protected $casts = [
        'revision_id' => 'integer',
        'asset_id' => 'integer',
        'reviewed_at' => 'datetime',
        'bound_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PersonalityPublicContentAssetRevision::class, 'revision_id', 'id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PersonalityPublicContentAsset::class, 'asset_id', 'id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Bound personality authority human-review evidence is immutable.');
        });
    }
}
