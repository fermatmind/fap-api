<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EnneagramPublicAuthorityV206RevisionPromoter
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-REVISION-PROMOTER-06';

    public const TARGET_COUNT = 116;

    public const STATE_PENDING_MANUAL_REVIEW = 'pending_manual_review';

    public const STATE_HUMAN_REVIEW_APPROVED = 'human_review_approved';

    public const STATE_PUBLISHED = 'published';

    public const STATE_ROLLED_BACK = 'rolled_back';

    private const TOKEN_VERSION = 'enneagram-authority-v2-rollback.v1';

    /** @var list<string> */
    private const EDITORIAL_FIELDS = [
        'title',
        'summary',
        'content_sections_json',
        'seo_json',
        'canonical_json',
        'hreflang_json',
        'faq_json',
        'schema_json',
        'method_boundary_json',
        'evidence_notes_json',
        'authority_json',
        'internal_links_json',
        'review_state',
        'contract_version',
        'source_package',
        'source_hash',
        'last_reviewed_at',
        'updated_by_admin_user_id',
    ];

    /** @var list<string> */
    private const JSON_FIELDS = [
        'content_sections_json',
        'seo_json',
        'canonical_json',
        'hreflang_json',
        'faq_json',
        'schema_json',
        'method_boundary_json',
        'evidence_notes_json',
        'authority_json',
        'internal_links_json',
    ];

    /** @var list<string> */
    private const STABLE_IDENTITY_FIELDS = [
        'org_id',
        'framework',
        'entity_type',
        'entity_key',
        'slug',
        'locale',
        'robots',
        'is_public',
        'index_eligible',
        'sitemap_eligible',
        'llms_eligible',
        'launch_state',
        'published_at',
    ];

    /** @param list<array<string, mixed>> $targets @return array<string, mixed> */
    public function preflight(array $targets): array
    {
        return $this->publicPlan($this->buildPlan($targets, false));
    }

    /** @param list<array<string, mixed>> $targets @return array<string, mixed> */
    public function promote(array $targets, string $expectedPreflightFingerprint): array
    {
        return DB::transaction(function () use ($targets, $expectedPreflightFingerprint): array {
            $plan = $this->buildPlan($targets, true);
            if (! hash_equals((string) $plan['preflight_fingerprint'], $expectedPreflightFingerprint)) {
                throw new RuntimeException('Promotion preflight fingerprint changed; transaction aborted.');
            }

            $rollbackRows = [];
            foreach ($plan['targets'] as $target) {
                /** @var PersonalityPublicContentAsset $asset */
                $asset = $target['asset'];
                /** @var PersonalityPublicContentAssetRevision $revision */
                $revision = $target['working_revision'];
                $updates = [
                    ...$target['promoted_editorial_snapshot'],
                    'published_revision_id' => (int) $revision->id,
                    'working_revision_id' => null,
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ];
                $query = DB::table('personality_public_content_assets')
                    ->where('id', (int) $asset->id)
                    ->where('working_revision_id', (int) $revision->id);
                $target['expected_current_published_revision_id'] === null
                    ? $query->whereNull('published_revision_id')
                    : $query->where('published_revision_id', $target['expected_current_published_revision_id']);
                if ($query->update($updates) !== 1) {
                    throw new RuntimeException('Promotion pointer compare-and-swap failed: '.$target['asset_key'].'.');
                }
                if (DB::table('personality_public_content_asset_revisions')
                    ->where('id', (int) $revision->id)
                    ->where('workflow_state', self::STATE_HUMAN_REVIEW_APPROVED)
                    ->update(['workflow_state' => self::STATE_PUBLISHED]) !== 1) {
                    throw new RuntimeException('Promotion manual-review state changed concurrently: '.$target['asset_key'].'.');
                }

                $asset->refresh();
                $afterFingerprint = $this->publicFingerprint($asset);
                $rollbackRows[] = [
                    'asset_id' => (int) $asset->id,
                    'asset_key' => (string) $target['asset_key'],
                    'promoted_revision_id' => (int) $revision->id,
                    'previous_published_revision_id' => $target['expected_current_published_revision_id'],
                    'previous_published_revision_package_sha256' => $target['current_published_revision']?->authority_package_sha256,
                    'previous_published_revision_source_hash' => $target['current_published_revision']?->source_hash,
                    'package_sha256' => (string) $revision->authority_package_sha256,
                    'source_hash' => (string) $revision->source_hash,
                    'before_public_fingerprint' => (string) $target['expected_public_fingerprint_before'],
                    'before_restorable_fingerprint' => (string) $target['before_restorable_fingerprint'],
                    'after_public_fingerprint' => $afterFingerprint,
                    'before_editorial_snapshot' => $target['before_editorial_snapshot'],
                ];
            }

            $token = $this->encodeToken([
                'version' => self::TOKEN_VERSION,
                'artifact' => self::ARTIFACT,
                'target_count' => self::TARGET_COUNT,
                'preflight_fingerprint' => (string) $plan['preflight_fingerprint'],
                'rows' => $rollbackRows,
            ]);

            return [
                ...$this->publicPlan($plan),
                'status' => 'PASS_POINTER_SAFE_PROMOTION',
                'writes_committed' => true,
                'promoted_count' => self::TARGET_COUNT,
                'rollback_token' => $token,
                'rollback_token_sha256' => hash('sha256', $token),
                'production_execution' => app()->environment('production'),
                'public_release_count' => 0,
                'indexability_change_count' => 0,
                'sitemap_change_count' => 0,
                'llms_change_count' => 0,
            ];
        }, 1);
    }

    /** @return array<string, mixed> */
    public function rollback(string $token): array
    {
        $payload = $this->decodeToken($token);

        return DB::transaction(function () use ($payload, $token): array {
            $rows = $payload['rows'];
            foreach ($rows as $row) {
                $asset = PersonalityPublicContentAsset::query()
                    ->withoutGlobalScopes()
                    ->lockForUpdate()
                    ->find((int) $row['asset_id']);
                if (! $asset instanceof PersonalityPublicContentAsset
                    || (string) $asset->framework !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM
                    || (int) ($asset->published_revision_id ?? 0) !== (int) $row['promoted_revision_id']
                    || $asset->working_revision_id !== null
                    || ! hash_equals((string) $row['after_public_fingerprint'], $this->publicFingerprint($asset))) {
                    throw new RuntimeException('Rollback pointer or public fingerprint changed: '.(string) $row['asset_key'].'.');
                }
                $revision = PersonalityPublicContentAssetRevision::query()
                    ->lockForUpdate()
                    ->find((int) $row['promoted_revision_id']);
                if (! $revision instanceof PersonalityPublicContentAssetRevision
                    || (string) $revision->workflow_state !== self::STATE_PUBLISHED
                    || (string) $revision->authority_package_sha256 !== (string) $row['package_sha256']
                    || (string) $revision->source_hash !== (string) $row['source_hash']) {
                    throw new RuntimeException('Rollback promoted revision identity changed: '.(string) $row['asset_key'].'.');
                }
                if ($row['previous_published_revision_id'] !== null) {
                    $previousRevision = PersonalityPublicContentAssetRevision::query()
                        ->lockForUpdate()
                        ->find((int) $row['previous_published_revision_id']);
                    if (! $previousRevision instanceof PersonalityPublicContentAssetRevision
                        || (int) $previousRevision->asset_id !== (int) $asset->id
                        || (string) $previousRevision->authority_asset_key !== (string) $row['asset_key']
                        || (string) $previousRevision->workflow_state !== self::STATE_PUBLISHED
                        || (string) $previousRevision->authority_package_sha256 !== (string) $row['previous_published_revision_package_sha256']
                        || (string) $previousRevision->source_hash !== (string) $row['previous_published_revision_source_hash']) {
                        throw new RuntimeException('Rollback previous published revision identity changed: '.(string) $row['asset_key'].'.');
                    }
                }

                $updates = [
                    ...$row['before_editorial_snapshot'],
                    'published_revision_id' => $row['previous_published_revision_id'],
                    'working_revision_id' => null,
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ];
                if (DB::table('personality_public_content_assets')
                    ->where('id', (int) $asset->id)
                    ->where('published_revision_id', (int) $revision->id)
                    ->whereNull('working_revision_id')
                    ->update($updates) !== 1) {
                    throw new RuntimeException('Rollback pointer compare-and-swap failed: '.(string) $row['asset_key'].'.');
                }
                if (DB::table('personality_public_content_asset_revisions')
                    ->where('id', (int) $revision->id)
                    ->where('workflow_state', self::STATE_PUBLISHED)
                    ->update(['workflow_state' => self::STATE_ROLLED_BACK]) !== 1) {
                    throw new RuntimeException('Rollback revision state changed concurrently: '.(string) $row['asset_key'].'.');
                }

                $asset->refresh();
                if (! hash_equals((string) $row['before_restorable_fingerprint'], $this->restorableFingerprint($asset))) {
                    throw new RuntimeException('Rollback restorable content fingerprint readback failed: '.(string) $row['asset_key'].'.');
                }
            }

            return [
                'artifact' => self::ARTIFACT,
                'ok' => true,
                'status' => 'PASS_POINTER_SAFE_ROLLBACK',
                'target_count' => self::TARGET_COUNT,
                'rolled_back_count' => self::TARGET_COUNT,
                'rollback_token_sha256' => hash('sha256', $token),
                'writes_committed' => true,
                'production_execution' => app()->environment('production'),
                'public_release_count' => 0,
                'indexability_change_count' => 0,
                'sitemap_change_count' => 0,
                'llms_change_count' => 0,
            ];
        }, 1);
    }

    public function approvalPhrase(string $deploySha, string $preflightFingerprint): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $preflightFingerprint) !== 1) {
            throw new RuntimeException('Deploy SHA and preflight fingerprint must be exact lowercase hashes.');
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 POINTER-SAFE PROMOTION FOR DEPLOY_SHA=%s PREFLIGHT_FINGERPRINT=%s TARGET_COUNT=116 BOUND_HUMAN_REVIEW_EVIDENCE_REQUIRED=116 ROLLBACK_TOKEN_REQUIRED=1 PUBLIC_REVIEWER_NAME_WRITE_COUNT=0 PUBLIC_RELEASE=0 INDEXABILITY=0 SITEMAP=0 LLMS=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            $preflightFingerprint,
        );
    }

    public function rollbackApprovalPhrase(string $deploySha, string $rollbackTokenSha256): string
    {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $rollbackTokenSha256) !== 1) {
            throw new RuntimeException('Deploy SHA and rollback token SHA-256 must be exact lowercase hashes.');
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 POINTER-SAFE ROLLBACK FOR DEPLOY_SHA=%s ROLLBACK_TOKEN_SHA256=%s TARGET_COUNT=116 RESTORE_PREVIOUS_PUBLISHED_REVISION=1 PUBLIC_RELEASE=0 INDEXABILITY=0 SITEMAP=0 LLMS=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            $rollbackTokenSha256,
        );
    }

    /** @param list<array<string, mixed>> $targets @return array<string, mixed> */
    private function buildPlan(array $targets, bool $lock): array
    {
        if (count($targets) !== self::TARGET_COUNT) {
            throw new RuntimeException('Promotion requires the complete 116-target batch.');
        }
        usort($targets, static fn (array $left, array $right): int => ((string) ($left['asset_key'] ?? '')) <=> ((string) ($right['asset_key'] ?? '')));

        $planned = [];
        $seenAssets = [];
        $seenKeys = [];
        foreach ($targets as $index => $target) {
            $assetId = (int) ($target['asset_id'] ?? 0);
            $assetKey = trim((string) ($target['asset_key'] ?? ''));
            $expectedWorkingId = (int) ($target['expected_working_revision_id'] ?? 0);
            $expectedCurrentPublishedId = $target['expected_current_published_revision_id'] ?? null;
            $expectedCurrentPublishedId = $expectedCurrentPublishedId !== null ? (int) $expectedCurrentPublishedId : null;
            $packageSha = strtolower(trim((string) ($target['expected_package_sha256'] ?? '')));
            $sourceHash = strtolower(trim((string) ($target['expected_source_hash'] ?? '')));
            $publicFingerprint = strtolower(trim((string) ($target['expected_public_fingerprint_before'] ?? '')));
            if ($assetId <= 0 || $assetKey === '' || $expectedWorkingId <= 0
                || isset($seenAssets[$assetId]) || isset($seenKeys[$assetKey])) {
                throw new RuntimeException('Promotion target identity is missing or duplicated at index '.$index.'.');
            }
            foreach ([$packageSha, $sourceHash, $publicFingerprint] as $hash) {
                if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                    throw new RuntimeException('Promotion target hash is invalid: '.$assetKey.'.');
                }
            }
            $seenAssets[$assetId] = true;
            $seenKeys[$assetKey] = true;

            $assetQuery = PersonalityPublicContentAsset::query()->withoutGlobalScopes();
            if ($lock) {
                $assetQuery->lockForUpdate();
            }
            $asset = $assetQuery->find($assetId);
            if (! $asset instanceof PersonalityPublicContentAsset
                || (string) $asset->framework !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM
                || (string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                || ! (bool) $asset->is_public) {
                throw new RuntimeException('Promotion target is not a published Enneagram authority asset: '.$assetKey.'.');
            }
            $currentPublishedId = $asset->published_revision_id !== null ? (int) $asset->published_revision_id : null;
            if ($currentPublishedId !== $expectedCurrentPublishedId
                || (int) ($asset->working_revision_id ?? 0) !== $expectedWorkingId) {
                throw new RuntimeException('Promotion pointer is stale: '.$assetKey.'.');
            }
            $currentPublishedRevision = null;
            if ($currentPublishedId !== null) {
                $publishedRevisionQuery = PersonalityPublicContentAssetRevision::query();
                if ($lock) {
                    $publishedRevisionQuery->lockForUpdate();
                }
                $currentPublishedRevision = $publishedRevisionQuery->find($currentPublishedId);
                if (! $currentPublishedRevision instanceof PersonalityPublicContentAssetRevision
                    || (int) $currentPublishedRevision->asset_id !== $assetId
                    || (string) $currentPublishedRevision->authority_asset_key !== $assetKey
                    || (string) $currentPublishedRevision->workflow_state !== self::STATE_PUBLISHED) {
                    throw new RuntimeException('Current published revision lineage is invalid: '.$assetKey.'.');
                }
            }

            $revisionQuery = PersonalityPublicContentAssetRevision::query();
            if ($lock) {
                $revisionQuery->lockForUpdate();
            }
            $revision = $revisionQuery->find($expectedWorkingId);
            if (! $revision instanceof PersonalityPublicContentAssetRevision
                || (int) $revision->asset_id !== $assetId
                || (string) $revision->authority_asset_key !== $assetKey
                || (string) $revision->authority_package_sha256 !== $packageSha
                || (string) $revision->source_hash !== $sourceHash) {
                throw new RuntimeException('Promotion package SHA, source hash, or revision identity mismatch: '.$assetKey.'.');
            }
            if ((string) $revision->workflow_state !== self::STATE_HUMAN_REVIEW_APPROVED) {
                throw new RuntimeException('Promotion requires completed manual review: '.$assetKey.'.');
            }
            $reviewEvidenceQuery = PersonalityPublicContentAssetRevisionReview::query()
                ->where('revision_id', (int) $revision->id);
            if ($lock) {
                $reviewEvidenceQuery->lockForUpdate();
            }
            $reviewEvidence = $reviewEvidenceQuery->first();
            if (! $reviewEvidence instanceof PersonalityPublicContentAssetRevisionReview
                || (int) $reviewEvidence->asset_id !== $assetId
                || (string) $reviewEvidence->authority_asset_key !== $assetKey
                || (string) $reviewEvidence->source_package !== (string) $revision->source_package
                || (string) $reviewEvidence->asset_sha256 !== $sourceHash
                || (string) $reviewEvidence->authority_package_sha256 !== $packageSha
                || (string) $reviewEvidence->decision !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED
                || (string) $reviewEvidence->review_source !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN
                || ! hash_equals((string) $reviewEvidence->evidence_sha256, $this->reviewEvidenceFingerprint($asset, $reviewEvidence))) {
                throw new RuntimeException('Promotion requires exact bound human-review evidence: '.$assetKey.'.');
            }
            if ((string) $revision->public_runtime_fingerprint_before !== $publicFingerprint
                || ! hash_equals($publicFingerprint, $this->publicFingerprint($asset))) {
                throw new RuntimeException('Promotion public fingerprint changed: '.$assetKey.'.');
            }

            $snapshot = $revision->snapshot_json;
            foreach (self::STABLE_IDENTITY_FIELDS as $field) {
                if (array_key_exists($field, $snapshot)
                    && $this->normalizeComparable($field, $snapshot[$field]) !== $this->normalizeComparable($field, $asset->getAttribute($field))) {
                    throw new RuntimeException('Promotion snapshot attempts to change stable public identity: '.$assetKey.':'.$field.'.');
                }
            }
            $promotedEditorial = array_intersect_key($snapshot, array_flip(self::EDITORIAL_FIELDS));
            foreach (['title', 'contract_version', 'source_package', 'source_hash'] as $required) {
                if (! array_key_exists($required, $promotedEditorial)) {
                    throw new RuntimeException('Promotion snapshot is missing '.$required.': '.$assetKey.'.');
                }
            }
            if ((string) $promotedEditorial['source_package'] !== (string) $revision->source_package
                || (string) $promotedEditorial['source_hash'] !== $sourceHash) {
                throw new RuntimeException('Promotion snapshot provenance does not match its immutable revision lineage: '.$assetKey.'.');
            }
            $promotedEditorial['review_state'] = self::STATE_HUMAN_REVIEW_APPROVED;
            $promotedEditorial['last_reviewed_at'] = $reviewEvidence->reviewed_at?->format('Y-m-d H:i:s');
            $authority = is_array($promotedEditorial['authority_json'] ?? null)
                ? $promotedEditorial['authority_json']
                : [];
            $authority['reviewer'] = null;
            $promotedEditorial['authority_json'] = $authority;

            $planned[] = [
                ...$target,
                'asset_id' => $assetId,
                'asset_key' => $assetKey,
                'expected_working_revision_id' => $expectedWorkingId,
                'expected_current_published_revision_id' => $expectedCurrentPublishedId,
                'expected_package_sha256' => $packageSha,
                'expected_source_hash' => $sourceHash,
                'expected_public_fingerprint_before' => $publicFingerprint,
                'working_snapshot_sha256' => $this->fingerprint($snapshot),
                'asset' => $asset,
                'working_revision' => $revision,
                'review_evidence' => $reviewEvidence,
                'current_published_revision' => $currentPublishedRevision,
                'before_editorial_snapshot' => $this->editorialSnapshot($asset),
                'before_restorable_fingerprint' => $this->restorableFingerprint($asset),
                'promoted_editorial_snapshot' => $this->databaseEditorialSnapshot($promotedEditorial),
            ];
        }
        usort($planned, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);

        $fingerprintTargets = array_map(static fn (array $target): array => [
            'asset_id' => $target['asset_id'],
            'asset_key' => $target['asset_key'],
            'expected_current_published_revision_id' => $target['expected_current_published_revision_id'],
            'expected_working_revision_id' => $target['expected_working_revision_id'],
            'expected_package_sha256' => $target['expected_package_sha256'],
            'expected_source_hash' => $target['expected_source_hash'],
            'expected_public_fingerprint_before' => $target['expected_public_fingerprint_before'],
            'working_snapshot_sha256' => $target['working_snapshot_sha256'],
            'review_evidence_sha256' => (string) $target['review_evidence']->evidence_sha256,
            'review_register_sha256' => (string) $target['review_evidence']->review_register_sha256,
        ], $planned);

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_POINTER_SAFE_PROMOTION_PREFLIGHT',
            'target_count' => self::TARGET_COUNT,
            'preflight_fingerprint' => $this->fingerprint($fingerprintTargets),
            'writes_committed' => false,
            'production_execution' => false,
            'public_release_count' => 0,
            'indexability_change_count' => 0,
            'sitemap_change_count' => 0,
            'llms_change_count' => 0,
            'targets' => $planned,
        ];
    }

    /** @return array<string, mixed> */
    private function editorialSnapshot(PersonalityPublicContentAsset $asset): array
    {
        $snapshot = [];
        foreach (self::EDITORIAL_FIELDS as $field) {
            $snapshot[$field] = $asset->getRawOriginal($field);
        }

        return $snapshot;
    }

    private function publicFingerprint(PersonalityPublicContentAsset $asset): string
    {
        $attributes = $asset->getAttributes();
        unset($attributes['working_revision_id']);

        return $this->fingerprint($attributes);
    }

    private function restorableFingerprint(PersonalityPublicContentAsset $asset): string
    {
        $attributes = $asset->getAttributes();
        unset($attributes['working_revision_id'], $attributes['updated_at']);

        return $this->fingerprint($attributes);
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function databaseEditorialSnapshot(array $snapshot): array
    {
        foreach (self::JSON_FIELDS as $field) {
            if (array_key_exists($field, $snapshot) && $snapshot[$field] !== null && ! is_string($snapshot[$field])) {
                $snapshot[$field] = json_encode(
                    $snapshot[$field],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $payload */
    private function encodeToken(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, $this->signingKey());
    }

    /** @return array{version:string,artifact:string,target_count:int,preflight_fingerprint:string,rows:list<array<string,mixed>>} */
    private function decodeToken(string $token): array
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], $this->signingKey()), $parts[1])) {
            throw new RuntimeException('Rollback token signature is invalid.');
        }
        $encoded = strtr($parts[0], '-_', '+/');
        $decoded = base64_decode($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $payload = is_string($decoded) ? json_decode($decoded, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== self::TOKEN_VERSION
            || ($payload['artifact'] ?? null) !== self::ARTIFACT
            || ($payload['target_count'] ?? null) !== self::TARGET_COUNT
            || ! is_array($payload['rows'] ?? null)
            || count($payload['rows']) !== self::TARGET_COUNT
            || count(array_unique(array_column($payload['rows'], 'asset_id'))) !== self::TARGET_COUNT) {
            throw new RuntimeException('Rollback token payload is invalid or incomplete.');
        }

        return $payload;
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY is required to sign rollback tokens.');
        }

        return $key;
    }

    private function reviewEvidenceFingerprint(
        PersonalityPublicContentAsset $asset,
        PersonalityPublicContentAssetRevisionReview $review,
    ): string {
        return $this->fingerprint([
            'asset_key' => (string) $asset->locale.'|'.(string) $asset->entity_type.':'.(string) $asset->entity_key,
            'asset_sha256' => (string) $review->asset_sha256,
            'package_sha256' => (string) $review->authority_package_sha256,
            'reviewer_name' => (string) $review->reviewer_name,
            'reviewed_at' => $review->reviewed_at?->utc()->format('Y-m-d H:i:s'),
            'decision' => (string) $review->decision,
            'review_source' => (string) $review->review_source,
        ]);
    }

    private function normalizeComparable(string $field, mixed $value): string
    {
        if ($field === 'published_at' && $value !== null && $value !== '') {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);

            return $date->format('U.u');
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|list<mixed> $value */
    private function fingerprint(array $value): string
    {
        $this->sortRecursive($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    /** @param array<mixed> $value */
    private function sortRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortRecursive($child);
            }
        }
        unset($child);
        if (! array_is_list($value)) {
            ksort($value);
        }
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['targets']);

        return $plan;
    }
}
