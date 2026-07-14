<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PersonalityPublicContentAssetRevision extends Model
{
    public const STATE_DRAFT = 'draft';

    protected $table = 'personality_public_content_asset_revisions';

    protected $fillable = [
        'asset_id',
        'revision_no',
        'authority_asset_key',
        'source_package',
        'source_hash',
        'authority_package_sha256',
        'workflow_state',
        'snapshot_json',
        'public_runtime_fingerprint_before',
        'created_by_admin_user_id',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'revision_no' => 'integer',
        'snapshot_json' => 'array',
        'created_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(PersonalityPublicContentAsset::class, 'asset_id', 'id');
    }

    public function isRuntimeMutable(): bool
    {
        return false;
    }

    protected static function booted(): void
    {
        self::updating(static function (self $revision): void {
            foreach ([
                'asset_id',
                'revision_no',
                'authority_asset_key',
                'source_package',
                'source_hash',
                'authority_package_sha256',
                'snapshot_json',
                'public_runtime_fingerprint_before',
            ] as $immutable) {
                if ($revision->isDirty($immutable)) {
                    throw new LogicException('Big Five Authority V2 personality draft revision lineage is immutable.');
                }
            }
        });
    }
}
