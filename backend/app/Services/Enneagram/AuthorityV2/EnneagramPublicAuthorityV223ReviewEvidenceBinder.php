<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EnneagramPublicAuthorityV223ReviewEvidenceBinder
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RUNTIME-IMPORT-REVIEW-22C';

    public const TARGET_COUNT = 116;

    public function __construct(
        private readonly EnneagramPublicAuthorityV205RevisionWorkspaceWriter $workspaceWriter,
        private readonly PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter $revisionWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $releaseReport
     * @param  array<string, mixed>  $reviewRegister
     * @return array<string, mixed>
     */
    public function preflight(array $releaseReport, array $reviewRegister, string $reviewRegisterSha256): array
    {
        $workspace = $this->workspaceWriter->preflight($releaseReport);
        $plan = $this->buildPlan($releaseReport, $reviewRegister, $reviewRegisterSha256, false);
        if (! hash_equals((string) $workspace['package_sha256'], (string) $plan['package_sha256'])) {
            throw new RuntimeException('Human-review binder package does not match the exact candidate workspace.');
        }

        return $this->publicPlan($plan);
    }

    /**
     * @param  array<string, mixed>  $releaseReport
     * @param  array<string, mixed>  $reviewRegister
     * @return array<string, mixed>
     */
    public function bind(
        array $releaseReport,
        array $reviewRegister,
        string $reviewRegisterSha256,
        string $expectedPackageSha256,
        string $expectedPreflightFingerprint,
        ?int $boundByAdminUserId = null,
    ): array {
        return DB::transaction(function () use (
            $releaseReport,
            $reviewRegister,
            $reviewRegisterSha256,
            $expectedPackageSha256,
            $expectedPreflightFingerprint,
            $boundByAdminUserId,
        ): array {
            $plan = $this->buildPlan($releaseReport, $reviewRegister, $reviewRegisterSha256, true);
            if (! hash_equals((string) $plan['package_sha256'], $expectedPackageSha256)
                || ! hash_equals((string) $plan['preflight_fingerprint'], $expectedPreflightFingerprint)) {
                throw new RuntimeException('Human-review package SHA-256 or preflight fingerprint changed; transaction aborted.');
            }

            foreach ($plan['targets'] as $target) {
                if ($target['existing_evidence'] instanceof PersonalityPublicContentAssetRevisionReview) {
                    throw new RuntimeException('Human-review evidence is already bound; duplicate bind is not permitted: '.$target['asset_key'].'.');
                }

                PersonalityPublicContentAssetRevisionReview::query()->create([
                    'revision_id' => (int) $target['revision']->id,
                    'asset_id' => (int) $target['asset']->id,
                    'authority_asset_key' => (string) $target['asset_key'],
                    'source_package' => (string) $target['revision']->source_package,
                    'asset_sha256' => (string) $target['asset_sha256'],
                    'authority_package_sha256' => (string) $plan['package_sha256'],
                    'review_register_sha256' => $reviewRegisterSha256,
                    'reviewer_name' => (string) $target['reviewer_name'],
                    'reviewed_at' => (string) $target['reviewed_at'],
                    'decision' => PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED,
                    'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
                    'evidence_sha256' => (string) $target['evidence_sha256'],
                    'bound_by_admin_user_id' => $boundByAdminUserId,
                ]);

                if (DB::table('personality_public_content_asset_revisions')
                    ->where('id', (int) $target['revision']->id)
                    ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW)
                    ->update(['workflow_state' => EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED]) !== 1) {
                    throw new RuntimeException('Human-review workflow state changed concurrently: '.$target['asset_key'].'.');
                }
            }

            $boundCount = PersonalityPublicContentAssetRevisionReview::query()
                ->where('authority_package_sha256', (string) $plan['package_sha256'])
                ->where('review_register_sha256', $reviewRegisterSha256)
                ->where('decision', PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED)
                ->where('review_source', PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN)
                ->count();
            $approvedCount = PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', (string) $plan['package_sha256'])
                ->where('workflow_state', EnneagramPublicAuthorityV206RevisionPromoter::STATE_HUMAN_REVIEW_APPROVED)
                ->count();
            $publicFingerprintAfter = $this->publicFingerprint($plan['targets']);
            if ($boundCount !== self::TARGET_COUNT
                || $approvedCount !== self::TARGET_COUNT
                || ! hash_equals((string) $plan['public_fingerprint'], $publicFingerprintAfter)) {
                throw new RuntimeException('Human-review evidence readback or public fingerprint failed; transaction aborted.');
            }

            return [
                ...$this->publicPlan($plan),
                'status' => 'PASS_EXACT_HUMAN_REVIEW_EVIDENCE_BIND',
                'writes_committed' => true,
                'review_evidence_created_count' => self::TARGET_COUNT,
                'workflow_transition_count' => self::TARGET_COUNT,
                'human_review_approved_count' => self::TARGET_COUNT,
                'public_fingerprint_after' => $publicFingerprintAfter,
                'public_fingerprint_unchanged' => true,
                'production_execution' => app()->environment('production'),
                'public_reviewer_name_write_count' => 0,
            ];
        }, 1);
    }

    public function approvalPhrase(
        string $deploySha,
        string $packageSha256,
        string $reviewRegisterSha256,
        string $preflightFingerprint,
    ): string {
        if (preg_match('/^[0-9a-f]{40}$/', $deploySha) !== 1) {
            throw new RuntimeException('Deploy SHA must be an exact lowercase 40-character Git SHA.');
        }
        foreach ([$packageSha256, $reviewRegisterSha256, $preflightFingerprint] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Binder package, register, and preflight hashes must be exact lowercase SHA-256 values.');
            }
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 HUMAN REVIEW BIND FOR DEPLOY_SHA=%s PACKAGE_SHA256=%s REVIEW_REGISTER_SHA256=%s PREFLIGHT_FINGERPRINT=%s TARGET_COUNT=116 REVIEW_SOURCE=operator_supplied_human DECISION=approved PUBLIC_REVIEWER_NAME_WRITE_COUNT=0; ABORT_ON_ANY_MISMATCH',
            $deploySha,
            $packageSha256,
            $reviewRegisterSha256,
            $preflightFingerprint,
        );
    }

    /**
     * @param  array<string, mixed>  $releaseReport
     * @param  array<string, mixed>  $reviewRegister
     * @return array<string, mixed>
     */
    private function buildPlan(
        array $releaseReport,
        array $reviewRegister,
        string $reviewRegisterSha256,
        bool $lock,
    ): array {
        $this->assertSchema();
        $packageSha256 = strtolower(trim((string) ($releaseReport['package_sha256'] ?? '')));
        if (($releaseReport['artifact'] ?? null) !== EnneagramPublicAuthorityV222ReleaseGate::ARTIFACT
            || preg_match('/^[0-9a-f]{64}$/', $packageSha256) !== 1
            || ! is_array($releaseReport['asset_records'] ?? null)
            || count($releaseReport['asset_records']) !== self::TARGET_COUNT) {
            throw new RuntimeException('Human-review binder requires the exact 116-asset final release report.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $reviewRegisterSha256) !== 1) {
            throw new RuntimeException('Human-review register SHA-256 is invalid.');
        }
        if (($reviewRegister['schema_version'] ?? null) !== 'enneagram_public_authority_v2_private_review_register.v1'
            || ($reviewRegister['review_source'] ?? null) !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN
            || ($reviewRegister['package_sha256'] ?? null) !== $packageSha256
            || ! is_array($reviewRegister['reviews'] ?? null)
            || count($reviewRegister['reviews']) !== self::TARGET_COUNT) {
            throw new RuntimeException('Human-review register schema, source, package, or target count is invalid.');
        }

        $releaseRecords = [];
        foreach ($releaseReport['asset_records'] as $record) {
            $key = is_array($record) ? trim((string) ($record['asset_key'] ?? '')) : '';
            if ($key === '' || isset($releaseRecords[$key])) {
                throw new RuntimeException('Final release report contains a missing or duplicate asset key.');
            }
            $releaseRecords[$key] = $record;
        }

        $reviews = [];
        foreach ($reviewRegister['reviews'] as $index => $review) {
            if (! is_array($review)) {
                throw new RuntimeException('Human-review register row must be an object at index '.$index.'.');
            }
            $key = trim((string) ($review['asset_key'] ?? ''));
            if ($key === '' || ! isset($releaseRecords[$key]) || isset($reviews[$key])) {
                throw new RuntimeException('Human-review register contains an unknown or duplicate asset key: '.$key.'.');
            }
            $reviews[$key] = $review;
        }
        if (array_keys($releaseRecords) !== array_keys(array_replace($releaseRecords, $reviews))) {
            throw new RuntimeException('Human-review register asset set does not match the final release report.');
        }

        $targets = [];
        ksort($releaseRecords);
        foreach ($releaseRecords as $releaseAssetKey => $record) {
            $review = $reviews[$releaseAssetKey] ?? null;
            if (! is_array($review)) {
                throw new RuntimeException('Human-review register is missing an asset: '.$releaseAssetKey.'.');
            }
            $assetSha256 = strtolower(trim((string) ($record['asset_sha256'] ?? '')));
            $reviewerName = trim((string) ($review['reviewer_name'] ?? ''));
            $reviewSource = trim((string) ($review['review_source'] ?? ''));
            $decision = trim((string) ($review['decision'] ?? ''));
            if (($review['asset_sha256'] ?? null) !== $assetSha256
                || $reviewerName === ''
                || $reviewSource !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN
                || $decision !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED) {
                throw new RuntimeException('Human-review row is missing approval or exact provenance: '.$releaseAssetKey.'.');
            }
            $reviewedAtInput = trim((string) ($review['reviewed_at'] ?? ''));
            if ($reviewedAtInput === '') {
                throw new RuntimeException('Human-review timestamp is missing: '.$releaseAssetKey.'.');
            }
            try {
                $reviewedAt = CarbonImmutable::parse($reviewedAtInput)
                    ->utc()
                    ->format('Y-m-d H:i:s');
            } catch (\Throwable) {
                throw new RuntimeException('Human-review timestamp is invalid: '.$releaseAssetKey.'.');
            }

            $assetKey = implode(':', [
                PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
                (string) $record['entity_type'],
                (string) $record['code'],
                (string) $record['locale'],
            ]);
            $revisionQuery = PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', $packageSha256)
                ->where('authority_asset_key', $assetKey);
            if ($lock) {
                $revisionQuery->lockForUpdate();
            }
            $revision = $revisionQuery->first();
            if (! $revision instanceof PersonalityPublicContentAssetRevision
                || (string) $revision->source_package !== EnneagramPublicAuthorityV205RevisionWorkspaceWriter::SOURCE_PACKAGE
                || (string) $revision->source_hash !== $assetSha256
                || (string) $revision->workflow_state !== EnneagramPublicAuthorityV206RevisionPromoter::STATE_PENDING_MANUAL_REVIEW) {
                throw new RuntimeException('Human-review target revision identity, SHA, or workflow state is invalid: '.$assetKey.'.');
            }

            $assetQuery = PersonalityPublicContentAsset::query()->withoutGlobalScopes();
            if ($lock) {
                $assetQuery->lockForUpdate();
            }
            $asset = $assetQuery->find((int) $revision->asset_id);
            if (! $asset instanceof PersonalityPublicContentAsset
                || (int) ($asset->working_revision_id ?? 0) !== (int) $revision->id
                || (string) $asset->framework !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM
                || ! hash_equals(
                    (string) $revision->public_runtime_fingerprint_before,
                    $this->revisionWriter->recordPublicRuntimeFingerprint($asset),
                )) {
                throw new RuntimeException('Human-review target pointer or public fingerprint changed: '.$assetKey.'.');
            }

            $evidenceSha256 = $this->fingerprint([
                'asset_key' => $releaseAssetKey,
                'asset_sha256' => $assetSha256,
                'package_sha256' => $packageSha256,
                'reviewer_name' => $reviewerName,
                'reviewed_at' => $reviewedAt,
                'decision' => $decision,
                'review_source' => $reviewSource,
            ]);
            if (isset($review['evidence_sha256']) && $review['evidence_sha256'] !== $evidenceSha256) {
                throw new RuntimeException('Human-review evidence SHA-256 is invalid: '.$releaseAssetKey.'.');
            }

            $existingQuery = PersonalityPublicContentAssetRevisionReview::query()
                ->where('revision_id', (int) $revision->id);
            if ($lock) {
                $existingQuery->lockForUpdate();
            }
            $existingEvidence = $existingQuery->first();
            if ($existingEvidence instanceof PersonalityPublicContentAssetRevisionReview) {
                throw new RuntimeException('Duplicate human-review evidence already exists: '.$assetKey.'.');
            }

            $targets[] = [
                'asset_key' => $assetKey,
                'release_asset_key' => $releaseAssetKey,
                'asset_sha256' => $assetSha256,
                'reviewer_name' => $reviewerName,
                'reviewed_at' => $reviewedAt,
                'evidence_sha256' => $evidenceSha256,
                'asset' => $asset,
                'revision' => $revision,
                'existing_evidence' => $existingEvidence,
            ];
        }
        if (count($targets) !== self::TARGET_COUNT) {
            throw new RuntimeException('Human-review binder requires exactly 116 validated targets.');
        }

        $publicFingerprint = $this->publicFingerprint($targets);
        $fingerprintTargets = array_map(static fn (array $target): array => [
            'asset_id' => (int) $target['asset']->id,
            'revision_id' => (int) $target['revision']->id,
            'asset_key' => (string) $target['asset_key'],
            'asset_sha256' => (string) $target['asset_sha256'],
            'reviewed_at' => (string) $target['reviewed_at'],
            'evidence_sha256' => (string) $target['evidence_sha256'],
        ], $targets);

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_EXACT_HUMAN_REVIEW_EVIDENCE_PREFLIGHT',
            'target_count' => self::TARGET_COUNT,
            'approved_count' => self::TARGET_COUNT,
            'rejected_count' => 0,
            'duplicate_count' => 0,
            'package_sha256' => $packageSha256,
            'review_register_sha256' => $reviewRegisterSha256,
            'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
            'public_fingerprint' => $publicFingerprint,
            'preflight_fingerprint' => $this->fingerprint([
                'package_sha256' => $packageSha256,
                'review_register_sha256' => $reviewRegisterSha256,
                'public_fingerprint' => $publicFingerprint,
                'targets' => $fingerprintTargets,
            ]),
            'writes_committed' => false,
            'production_execution' => false,
            'public_reviewer_name_write_count' => 0,
            'targets' => $targets,
        ];
    }

    private function assertSchema(): void
    {
        if (! SchemaBaseline::hasTable('personality_public_content_asset_revision_reviews')) {
            throw new RuntimeException('Private personality revision review-evidence table is unavailable.');
        }
    }

    /** @param list<array<string, mixed>> $targets */
    private function publicFingerprint(array $targets): string
    {
        $rows = [];
        foreach ($targets as $target) {
            /** @var PersonalityPublicContentAsset $asset */
            $asset = $target['asset'];
            $asset->refresh();
            $rows[] = [
                'asset_key' => (string) $target['asset_key'],
                'fingerprint' => $this->revisionWriter->recordPublicRuntimeFingerprint($asset),
            ];
        }

        return $this->fingerprint($rows);
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $child): mixed => $this->normalizeForHash($child), $value);
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function publicPlan(array $plan): array
    {
        unset($plan['targets']);

        return $plan;
    }
}
