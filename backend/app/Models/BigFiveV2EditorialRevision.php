<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

final class BigFiveV2EditorialRevision extends Model
{
    public const STATE_DRAFT = 'draft';

    public const STATE_REVIEW = 'review';

    public const STATE_APPROVED = 'approved';

    public const STATE_REJECTED = 'rejected';

    public const STATE_ARCHIVED = 'archived';

    protected $table = 'big_five_v2_editorial_revisions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'org_id',
        'asset_key',
        'asset_type',
        'asset_path',
        'asset_sha256',
        'version_no',
        'supersedes_revision_id',
        'workflow_state',
        'release_snapshot_id',
        'release_snapshot_hash',
        'draft_payload_hash',
        'created_by_admin_user_id',
        'submitted_by_admin_user_id',
        'reviewed_by_admin_user_id',
        'archived_by_admin_user_id',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'rejected_at',
        'archived_at',
        'decision_note',
        'metadata_json',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'version_no' => 'integer',
        'metadata_json' => 'array',
        'created_by_admin_user_id' => 'integer',
        'submitted_by_admin_user_id' => 'integer',
        'reviewed_by_admin_user_id' => 'integer',
        'archived_by_admin_user_id' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [
            self::STATE_DRAFT,
            self::STATE_REVIEW,
            self::STATE_APPROVED,
            self::STATE_REJECTED,
            self::STATE_ARCHIVED,
        ];
    }

    public function isTerminalState(): bool
    {
        return in_array($this->workflow_state, [
            self::STATE_APPROVED,
            self::STATE_REJECTED,
            self::STATE_ARCHIVED,
        ], true);
    }

    public function isRuntimeMutable(): bool
    {
        return false;
    }

    public function canPublishToRuntime(): bool
    {
        return false;
    }

    public function hasReleaseSnapshotLinkage(): bool
    {
        return (string) ($this->release_snapshot_id ?? '') !== ''
            && (string) ($this->release_snapshot_hash ?? '') !== '';
    }

    protected static function booted(): void
    {
        self::creating(static function (self $revision): void {
            if ((string) $revision->id === '') {
                $revision->id = (string) Str::uuid();
            }
        });

        self::updating(static function (self $revision): void {
            $immutableColumns = [
                'asset_key',
                'asset_type',
                'asset_path',
                'asset_sha256',
                'version_no',
                'supersedes_revision_id',
                'release_snapshot_id',
                'release_snapshot_hash',
                'draft_payload_hash',
            ];

            foreach ($immutableColumns as $column) {
                if ($revision->isDirty($column)) {
                    throw new LogicException('Big Five V2 editorial revision release linkage and lineage are immutable.');
                }
            }
        });
    }
}
