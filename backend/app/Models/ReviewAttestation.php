<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

final class ReviewAttestation extends Model
{
    protected $fillable = [
        'schema_version',
        'review_mode',
        'review_source',
        'scope_type',
        'scope_identity',
        'decision',
        'target_count',
        'target_set_sha256',
        'package_sha256',
        'exceptions_json',
        'statement_version',
        'attested_by_admin_user_id',
        'attested_at',
        'evidence_sha256',
        'canonical_evidence_json',
    ];

    protected $casts = [
        'target_count' => 'integer',
        'exceptions_json' => 'array',
        'attested_by_admin_user_id' => 'integer',
        'attested_at' => 'immutable_datetime',
        'canonical_evidence_json' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<ReviewAttestationTargetEvidence, $this>
     */
    public function targetEvidences(): HasMany
    {
        return $this->hasMany(ReviewAttestationTargetEvidence::class, 'review_attestation_id');
    }

    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException('Bound review attestations are immutable.');
        });
        self::deleting(static function (): void {
            throw new LogicException('Bound review attestations cannot be deleted through the application.');
        });
    }
}
