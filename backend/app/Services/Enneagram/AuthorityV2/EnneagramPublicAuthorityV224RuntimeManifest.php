<?php

declare(strict_types=1);

namespace App\Services\Enneagram\AuthorityV2;

use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevisionReview;
use App\Services\Personality\AuthorityV2\PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter;
use Carbon\CarbonImmutable;
use RuntimeException;

final class EnneagramPublicAuthorityV224RuntimeManifest
{
    public const ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RUNTIME-READBACK-22E';

    private const RELEASE_GATE_ARTIFACT = 'ENNEAGRAM-PUBLIC-AUTHORITY-V2-RELEASE-GATE-22';

    public const TARGET_COUNT = 116;

    public const CANARY_COUNT = 8;

    public const FOLLOW_UP_BATCH_COUNT = 9;

    public const FOLLOW_UP_BATCH_SIZE = 12;

    /** @var list<string> */
    private const CANARY_KEYS = [
        'en|hub:enneagram',
        'zh-CN|hub:enneagram',
        'en|center:gut',
        'zh-CN|center:head',
        'en|core_type:type-1',
        'zh-CN|wing:1w9',
        'en|instinctual_subtype:type-4/social',
        'en|instinctual_subtype:type-9/one-to-one',
    ];

    /** @var array{hero:null,inline:list<never>,og:null} */
    private const EMPTY_MEDIA = ['hero' => null, 'inline' => [], 'og' => null];

    public function __construct(
        private readonly PersonalityAuthorityV2CollisionSafeWorkingRevisionWriter $revisionWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $releaseReport
     * @return array{checklist:array<string,mixed>,review_register_template:array<string,mixed>,readback_batches:array<string,mixed>,retrospective_template:array<string,mixed>}
     */
    public function artifacts(array $releaseReport): array
    {
        $records = $this->releaseRecords($releaseReport);
        $batches = $this->batches($records);

        return [
            'checklist' => [
                'schema_version' => 'enneagram_public_authority_v2_human_review_checklist.v1',
                'artifact' => self::ARTIFACT,
                'package_sha256' => (string) $releaseReport['package_sha256'],
                'target_count' => self::TARGET_COUNT,
                'media_contract' => self::EMPTY_MEDIA,
                'items' => array_map(static fn (array $record): array => [
                    'asset_key' => (string) $record['asset_key'],
                    'path' => (string) $record['path'],
                    'source_path' => (string) $record['source_path'],
                    'asset_sha256' => (string) $record['asset_sha256'],
                    'review_decision' => 'pending_operator_supplied_human_review',
                ], $records),
            ],
            'review_register_template' => [
                'schema_version' => 'enneagram_public_authority_v2_private_review_register.v1',
                'classification' => 'private_internal_only_do_not_commit_when_completed',
                'package_sha256' => (string) $releaseReport['package_sha256'],
                'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
                'reviews' => array_map(static fn (array $record): array => [
                    'asset_key' => (string) $record['asset_key'],
                    'asset_sha256' => (string) $record['asset_sha256'],
                    'reviewer_name' => null,
                    'reviewed_at' => null,
                    'decision' => null,
                    'review_source' => PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN,
                    'evidence_sha256' => null,
                ], $records),
            ],
            'readback_batches' => [
                'schema_version' => 'enneagram_public_authority_v2_readback_batches.v1',
                'artifact' => self::ARTIFACT,
                'target_count' => self::TARGET_COUNT,
                'batch_plan' => '8 + 9x12 after one atomic 116-revision promotion',
                'batches' => $batches,
                'batch_plan_sha256' => $this->fingerprint($batches),
            ],
            'retrospective_template' => [
                'schema_version' => 'enneagram_public_authority_v2_runtime_closeout_23_retrospective.v1',
                'classification' => 'redacted_git_safe',
                'allowed_fields' => ['sha256', 'count', 'status', 'redacted_error_code'],
                'prohibited_fields' => ['reviewer_name', 'secret', 'nonce', 'signature', 'rollback_token', 'private_url'],
                'status' => 'pending_separate_exact_sha_authorization_and_execution',
                'automatic_rollback' => false,
            ],
        ];
    }

    /** @param array<string,mixed> $releaseReport @return array<string,list<array<string,mixed>>> */
    public function readbackBatches(array $releaseReport): array
    {
        return $this->batches($this->releaseRecords($releaseReport));
    }

    /**
     * Validate the complete private review register before a standalone post-readback
     * and return only the sensitive names needed for public leak detection.
     *
     * @param  array<string, mixed>  $releaseReport
     * @param  array<string, mixed>  $reviewRegister
     * @return list<string>
     */
    public function approvedPrivateReviewerNames(
        array $releaseReport,
        array $reviewRegister,
        string $reviewRegisterSha256,
    ): array {
        $records = $this->releaseRecords($releaseReport);
        $this->assertApprovedReviewRegister(
            $reviewRegister,
            $records,
            $reviewRegisterSha256,
            (string) $releaseReport['package_sha256'],
        );

        $names = [];
        foreach ($reviewRegister['reviews'] as $review) {
            $names[] = trim((string) $review['reviewer_name']);
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $releaseReport
     * @param  array<string, mixed>  $reviewRegister
     * @param  array<string, mixed>|null  $preReadback
     * @return array<string, mixed>
     */
    public function preflight(
        array $releaseReport,
        string $releaseReportSha256,
        array $reviewRegister,
        string $reviewRegisterSha256,
        string $backendDeployedSha,
        string $frontendDeployedSha,
        string $apiBaseUrl,
        string $frontendBaseUrl,
        string $revalidationEndpoint,
        string $workspacePreflightFingerprint,
        ?array $preReadback = null,
        ?string $preReadbackSha256 = null,
    ): array {
        $this->assertHash($releaseReportSha256, 'release report SHA-256');
        $this->assertHash($reviewRegisterSha256, 'review register SHA-256');
        $this->assertGitSha($backendDeployedSha, 'backend deployed SHA');
        $this->assertGitSha($frontendDeployedSha, 'frontend deployed SHA');
        $this->assertHash($workspacePreflightFingerprint, 'working import preflight fingerprint');
        $runtimeEndpoints = $this->runtimeEndpoints($apiBaseUrl, $frontendBaseUrl, $revalidationEndpoint);
        $records = $this->releaseRecords($releaseReport);
        $this->assertApprovedReviewRegister(
            $reviewRegister,
            $records,
            $reviewRegisterSha256,
            (string) $releaseReport['package_sha256'],
        );
        $artifacts = $this->artifacts($releaseReport);

        $projectionRows = [];
        $stableRows = [];
        foreach ($records as $record) {
            $asset = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
                ->where('org_id', 0)
                ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
                ->where('entity_type', (string) $record['entity_type'])
                ->where('entity_key', (string) $record['code'])
                ->where('locale', (string) $record['locale'])
                ->first();
            if (! $asset instanceof PersonalityPublicContentAsset
                || ! (bool) $asset->is_public
                || (string) $asset->launch_state !== PersonalityPublicContentAsset::LAUNCH_PUBLISHED
                || (string) data_get($asset->canonical_json, 'path', '') !== (string) $record['path']) {
                throw new RuntimeException('Runtime preflight public identity drifted: '.(string) $record['asset_key'].'.');
            }
            if ($this->normalizedMedia($asset->media_json) !== self::EMPTY_MEDIA) {
                throw new RuntimeException('Runtime preflight requires empty public media authority: '.(string) $record['asset_key'].'.');
            }
            $projectionRows[] = [
                'asset_key' => (string) $record['asset_key'],
                'fingerprint' => $this->revisionWriter->recordPublicRuntimeFingerprint($asset),
            ];
            $stableRows[] = [
                'asset_key' => (string) $record['asset_key'],
                'asset_id' => (int) $asset->id,
                'slug' => (string) $asset->slug,
                'canonical_path' => (string) data_get($asset->canonical_json, 'path', ''),
                'robots' => (string) $asset->robots,
                'is_public' => (bool) $asset->is_public,
                'index_eligible' => (bool) $asset->index_eligible,
                'sitemap_eligible' => (bool) $asset->sitemap_eligible,
                'llms_eligible' => (bool) $asset->llms_eligible,
                'launch_state' => (string) $asset->launch_state,
                'published_at' => $asset->published_at?->utc()->toIso8601String(),
            ];
        }
        usort($projectionRows, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        usort($stableRows, static fn (array $left, array $right): int => $left['asset_key'] <=> $right['asset_key']);
        $publicProjectionFingerprint = $this->fingerprint($projectionRows);
        $stableFingerprint = $this->fingerprint($stableRows);

        $preReadbackBinding = null;
        if ($preReadback !== null) {
            $this->assertHash((string) $preReadbackSha256, 'pre-readback SHA-256');
            $this->assertPreReadback(
                $preReadback,
                $records,
                $publicProjectionFingerprint,
                $stableFingerprint,
            );
            $preReadbackBinding = [
                'sha256' => $preReadbackSha256,
                'observed_at' => (string) $preReadback['observed_at'],
                'public_projection_fingerprint' => (string) ($preReadback['public_projection_fingerprint'] ?? ''),
                'stable_identity_discoverability_fingerprint' => (string) ($preReadback['stable_identity_discoverability_fingerprint'] ?? ''),
                'url_sets' => $preReadback['url_sets'] ?? null,
            ];
        }

        $runtimeFingerprint = $this->fingerprint([
            'backend_deployed_sha' => $backendDeployedSha,
            'frontend_deployed_sha' => $frontendDeployedSha,
            'runtime_endpoints' => $runtimeEndpoints,
            'release_report_sha256' => $releaseReportSha256,
            'package_sha256' => (string) $releaseReport['package_sha256'],
            'review_register_sha256' => $reviewRegisterSha256,
            'workspace_preflight_fingerprint' => $workspacePreflightFingerprint,
            'public_projection_fingerprint' => $publicProjectionFingerprint,
            'stable_identity_discoverability_fingerprint' => $stableFingerprint,
            'batch_plan_sha256' => (string) $artifacts['readback_batches']['batch_plan_sha256'],
            'pre_readback' => $preReadbackBinding,
        ]);
        $authorizationPhrase = $this->authorizationPhrase(
            $backendDeployedSha,
            $frontendDeployedSha,
            (string) $releaseReport['package_sha256'],
            $releaseReportSha256,
            $reviewRegisterSha256,
            $runtimeFingerprint,
        );
        $packet = [
            'schema_version' => 'enneagram_public_authority_v2_exact_sha_production_authorization.v1',
            'artifact' => self::ARTIFACT,
            'status' => 'AWAITING_SEPARATE_EXACT_SHA_PRODUCTION_AUTHORIZATION',
            'backend_deployed_sha' => $backendDeployedSha,
            'frontend_deployed_sha' => $frontendDeployedSha,
            'runtime_endpoints' => $runtimeEndpoints,
            'release_report_sha256' => $releaseReportSha256,
            'package_sha256' => (string) $releaseReport['package_sha256'],
            'review_register_sha256' => $reviewRegisterSha256,
            'workspace_preflight_fingerprint' => $workspacePreflightFingerprint,
            'runtime_preflight_fingerprint' => $runtimeFingerprint,
            'public_projection_fingerprint' => $publicProjectionFingerprint,
            'stable_identity_discoverability_fingerprint' => $stableFingerprint,
            'pre_readback' => $preReadbackBinding,
            'target_count' => self::TARGET_COUNT,
            'media_write_count' => 0,
            'promotion_count' => self::TARGET_COUNT,
            'revalidation_path_count' => self::TARGET_COUNT,
            'readback_plan' => ['canary-00' => 8, 'readback-01..09' => '9x12'],
            'automatic_rollback' => false,
            'rollback_requires_separate_exact_authorization' => true,
            'authorization_phrase' => $authorizationPhrase,
        ];

        return [
            'artifact' => self::ARTIFACT,
            'ok' => true,
            'status' => 'PASS_EXACT_SHA_RUNTIME_PREFLIGHT_AUTHORIZATION_REQUIRED',
            'target_count' => self::TARGET_COUNT,
            'approved_review_count' => self::TARGET_COUNT,
            'reviewer_names_exposed' => false,
            'media_write_count' => 0,
            'public_projection_fingerprint' => $publicProjectionFingerprint,
            'stable_identity_discoverability_fingerprint' => $stableFingerprint,
            'runtime_preflight_fingerprint' => $runtimeFingerprint,
            'authorization_packet' => $packet,
            'authorization_packet_sha256' => $this->fingerprint($packet),
            'artifacts' => $artifacts,
            'writes_committed' => false,
            'production_execution' => false,
        ];
    }

    /** @param array<string,mixed> $preReadback @param list<array<string,mixed>> $records */
    private function assertPreReadback(
        array $preReadback,
        array $records,
        string $publicProjectionFingerprint,
        string $stableFingerprint,
    ): void {
        $observedAtRaw = trim((string) ($preReadback['observed_at'] ?? ''));
        if ($observedAtRaw === '') {
            throw new RuntimeException('Exact pre-readback observed_at is missing.');
        }
        try {
            $observedAt = CarbonImmutable::parse($observedAtRaw)->utc();
        } catch (\Throwable) {
            throw new RuntimeException('Exact pre-readback observed_at is invalid.');
        }
        $now = CarbonImmutable::now('UTC');
        if ($observedAt->isBefore($now->subMinutes(30)) || $observedAt->isAfter($now->addMinute())) {
            throw new RuntimeException('Exact pre-readback is stale or has a future observed_at.');
        }
        if (($preReadback['schema_version'] ?? null) !== 'enneagram_public_authority_v2_runtime_readback.v1'
            || ($preReadback['artifact'] ?? null) !== EnneagramPublicAuthorityV224RuntimeReadback::ARTIFACT
            || ($preReadback['status'] ?? null) !== 'PASS_PRE_RUNTIME_READBACK'
            || ($preReadback['phase'] ?? null) !== 'pre'
            || ($preReadback['batch'] ?? null) !== 'all'
            || (int) ($preReadback['target_count'] ?? 0) !== self::TARGET_COUNT
            || (int) ($preReadback['api_read_count'] ?? 0) !== self::TARGET_COUNT
            || (int) ($preReadback['html_read_count'] ?? 0) !== self::TARGET_COUNT
            || (int) ($preReadback['private_data_exposed_count'] ?? -1) !== 0
            || (int) ($preReadback['non_empty_media_count'] ?? -1) !== 0
            || ($preReadback['writes_committed'] ?? true) !== false
            || ($preReadback['production_execution'] ?? true) !== false
            || ($preReadback['ok'] ?? false) !== true
            || ! hash_equals($publicProjectionFingerprint, (string) ($preReadback['public_projection_fingerprint'] ?? ''))
            || ! hash_equals($stableFingerprint, (string) ($preReadback['stable_identity_discoverability_fingerprint'] ?? ''))) {
            throw new RuntimeException('Exact pre-readback artifact is invalid, incomplete, or stale.');
        }

        $expectedRows = [];
        foreach ($records as $record) {
            $expectedRows[(string) $record['asset_key']] = [
                'path' => (string) $record['path'],
                'asset_sha256' => (string) $record['asset_sha256'],
            ];
        }
        $observedRows = is_array($preReadback['rows'] ?? null) ? $preReadback['rows'] : [];
        if (count($observedRows) !== self::TARGET_COUNT) {
            throw new RuntimeException('Exact pre-readback must contain all 116 row results.');
        }
        $seen = [];
        foreach ($observedRows as $row) {
            $assetKey = is_array($row) ? (string) ($row['asset_key'] ?? '') : '';
            $expected = $expectedRows[$assetKey] ?? null;
            if (! is_array($row) || ! is_array($expected) || isset($seen[$assetKey])
                || ($row['ok'] ?? false) !== true
                || (int) ($row['api_http_status'] ?? 0) !== 200
                || (int) ($row['html_http_status'] ?? 0) !== 200
                || ($row['media_empty'] ?? false) !== true
                || ($row['reviewer_public'] ?? true) !== false
                || ($row['issues'] ?? null) !== []
                || (string) ($row['path'] ?? '') !== $expected['path']
                || (string) ($row['asset_sha256'] ?? '') !== $expected['asset_sha256']) {
                throw new RuntimeException('Exact pre-readback row identity or result drifted.');
            }
            $seen[$assetKey] = true;
        }

        $expectedPaths = array_values(array_column($expectedRows, 'path'));
        sort($expectedPaths);
        foreach (['sitemap', 'llms', 'llms_full'] as $surface) {
            $set = data_get($preReadback, 'url_sets.'.$surface);
            $urls = is_array($set) && is_array($set['enneagram_urls'] ?? null)
                ? array_values($set['enneagram_urls'])
                : [];
            sort($urls);
            if (! is_array($set)
                || preg_match('/^[0-9a-f]{64}$/', (string) ($set['url_set_sha256'] ?? '')) !== 1
                || (int) ($set['url_count'] ?? 0) < self::TARGET_COUNT
                || (int) ($set['enneagram_url_count'] ?? 0) !== self::TARGET_COUNT
                || $urls !== $expectedPaths
                || ! hash_equals($this->fingerprint($urls), (string) ($set['enneagram_url_set_sha256'] ?? ''))) {
                throw new RuntimeException('Exact pre-readback '.$surface.' URL-set evidence is invalid.');
            }
        }
    }

    public function authorizationPhrase(
        string $backendSha,
        string $frontendSha,
        string $packageSha256,
        string $reportSha256,
        string $reviewRegisterSha256,
        string $runtimeFingerprint,
    ): string {
        $this->assertGitSha($backendSha, 'backend deployed SHA');
        $this->assertGitSha($frontendSha, 'frontend deployed SHA');
        foreach ([$packageSha256, $reportSha256, $reviewRegisterSha256, $runtimeFingerprint] as $hash) {
            $this->assertHash($hash, 'authorization hash');
        }

        return sprintf(
            'AUTHORIZE ENNEAGRAM AUTHORITY V2 RUNTIME CLOSEOUT FOR BACKEND_DEPLOY_SHA=%s FRONTEND_DEPLOY_SHA=%s PACKAGE_SHA256=%s REPORT_SHA256=%s REVIEW_REGISTER_SHA256=%s RUNTIME_PREFLIGHT_FINGERPRINT=%s TARGET_COUNT=116 MEDIA_WRITE_COUNT=0 PROMOTION_COUNT=116 REVALIDATION_PATH_COUNT=116 READBACK=8+9x12 AUTOMATIC_ROLLBACK=0; ABORT_ON_ANY_MISMATCH',
            $backendSha,
            $frontendSha,
            $packageSha256,
            $reportSha256,
            $reviewRegisterSha256,
            $runtimeFingerprint,
        );
    }

    /** @return array{api_base_origin:string,frontend_base_origin:string,frontend_revalidation_endpoint:string} */
    private function runtimeEndpoints(
        string $apiBaseUrl,
        string $frontendBaseUrl,
        string $revalidationEndpoint,
    ): array {
        return [
            'api_base_origin' => $this->exactHttpsOrigin($apiBaseUrl, 'API base origin'),
            'frontend_base_origin' => $this->exactHttpsOrigin($frontendBaseUrl, 'frontend base origin'),
            'frontend_revalidation_endpoint' => $this->exactHttpsEndpoint(
                $revalidationEndpoint,
                'frontend revalidation endpoint',
            ),
        ];
    }

    private function exactHttpsOrigin(string $value, string $label): string
    {
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '')) {
            throw new RuntimeException($label.' must be an exact HTTPS origin without credentials, path, query, or fragment.');
        }

        return $value;
    }

    private function exactHttpsEndpoint(string $value, string $label): string
    {
        $value = trim($value);
        $parts = parse_url($value);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || trim((string) ($parts['path'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new RuntimeException($label.' must be an exact HTTPS URL without credentials, query, or fragment.');
        }

        return $value;
    }

    /** @param list<array<string,mixed>> $records @return array<string,list<array<string,mixed>>> */
    private function batches(array $records): array
    {
        $byKey = [];
        foreach ($records as $record) {
            $byKey[(string) $record['asset_key']] = $record;
        }
        $canary = [];
        foreach (self::CANARY_KEYS as $key) {
            if (! isset($byKey[$key])) {
                throw new RuntimeException('Required canary asset is missing: '.$key.'.');
            }
            $canary[] = $this->readbackTarget($byKey[$key]);
            unset($byKey[$key]);
        }
        ksort($byKey);
        $remaining = array_map(fn (array $record): array => $this->readbackTarget($record), array_values($byKey));
        if (count($canary) !== self::CANARY_COUNT || count($remaining) !== 108) {
            throw new RuntimeException('Readback batch planner requires exactly 8 canary and 108 remaining targets.');
        }
        $batches = ['canary-00' => $canary];
        foreach (array_chunk($remaining, self::FOLLOW_UP_BATCH_SIZE) as $index => $chunk) {
            $batches[sprintf('readback-%02d', $index + 1)] = $chunk;
        }
        if (count($batches) !== 10) {
            throw new RuntimeException('Readback batch planner requires canary-00 plus readback-01..09.');
        }

        return $batches;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function readbackTarget(array $record): array
    {
        return [
            'asset_key' => (string) $record['asset_key'],
            'locale' => (string) $record['locale'],
            'entity_type' => (string) $record['entity_type'],
            'code' => (string) $record['code'],
            'path' => (string) $record['path'],
            'asset_sha256' => (string) $record['asset_sha256'],
        ];
    }

    /** @param array<string,mixed> $releaseReport @return list<array<string,mixed>> */
    private function releaseRecords(array $releaseReport): array
    {
        $package = strtolower(trim((string) ($releaseReport['package_sha256'] ?? '')));
        if (($releaseReport['artifact'] ?? null) !== self::RELEASE_GATE_ARTIFACT
            || ($releaseReport['schema_version'] ?? null) !== 'enneagram_public_authority_v2_release_gate.v2'
            || ($releaseReport['automated_gate_passed'] ?? false) !== true
            || ($releaseReport['media_boundary_passed'] ?? false) !== true
            || preg_match('/^[0-9a-f]{64}$/', $package) !== 1
            || ! is_array($releaseReport['asset_records'] ?? null)
            || count($releaseReport['asset_records']) !== self::TARGET_COUNT
            || (int) data_get($releaseReport, 'counts.empty_media_authority_count', 0) !== self::TARGET_COUNT
            || (int) data_get($releaseReport, 'counts.media_write_count', -1) !== 0) {
            throw new RuntimeException('Runtime manifest requires the exact automated-pass empty-media 116-page release report.');
        }
        $records = [];
        foreach ($releaseReport['asset_records'] as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Runtime release record must be an object.');
            }
            $key = trim((string) ($record['asset_key'] ?? ''));
            $sha = strtolower(trim((string) ($record['asset_sha256'] ?? '')));
            $path = trim((string) ($record['path'] ?? ''));
            if ($key === '' || isset($records[$key]) || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1
                || ! str_starts_with($path, '/')) {
                throw new RuntimeException('Runtime release record identity, path, or SHA is invalid: '.$key.'.');
            }
            $record['asset_sha256'] = $sha;
            $records[$key] = $record;
        }
        ksort($records);

        return array_values($records);
    }

    /** @param array<string,mixed> $register @param list<array<string,mixed>> $records */
    private function assertApprovedReviewRegister(
        array $register,
        array $records,
        string $registerSha256,
        string $releasePackageSha256,
    ): void {
        $expected = array_column($records, null, 'asset_key');
        if (($register['schema_version'] ?? null) !== 'enneagram_public_authority_v2_private_review_register.v1'
            || ($register['review_source'] ?? null) !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN
            || ($register['package_sha256'] ?? null) !== $releasePackageSha256
            || ! is_array($register['reviews'] ?? null)
            || count($register['reviews']) !== self::TARGET_COUNT) {
            throw new RuntimeException('Private review register schema, source, package, or row count is invalid.');
        }
        $seen = [];
        foreach ($register['reviews'] as $review) {
            $key = is_array($review) ? trim((string) ($review['asset_key'] ?? '')) : '';
            $record = $expected[$key] ?? null;
            if (! is_array($review) || ! is_array($record) || isset($seen[$key])
                || ($review['asset_sha256'] ?? null) !== $record['asset_sha256']
                || trim((string) ($review['reviewer_name'] ?? '')) === ''
                || ($review['decision'] ?? null) !== PersonalityPublicContentAssetRevisionReview::DECISION_APPROVED
                || ($review['review_source'] ?? null) !== PersonalityPublicContentAssetRevisionReview::REVIEW_SOURCE_OPERATOR_SUPPLIED_HUMAN) {
                throw new RuntimeException('Private review register is missing an exact approved human-review row: '.$key.'.');
            }
            try {
                $reviewedAt = trim((string) ($review['reviewed_at'] ?? ''));
                if ($reviewedAt === '') {
                    throw new RuntimeException('Private review register timestamp is missing: '.$key.'.');
                }
                CarbonImmutable::parse($reviewedAt);
            } catch (\Throwable) {
                throw new RuntimeException('Private review register timestamp is invalid: '.$key.'.');
            }
            $seen[$key] = true;
        }
        if (count($seen) !== self::TARGET_COUNT) {
            throw new RuntimeException('Private review register asset set is incomplete.');
        }
        $this->assertHash($registerSha256, 'review register SHA-256');
    }

    private function normalizedMedia(mixed $media): array
    {
        $media = is_array($media) ? $media : [];

        return [
            'hero' => $media['hero'] ?? null,
            'inline' => array_values(is_array($media['inline'] ?? null) ? $media['inline'] : []),
            'og' => $media['og'] ?? null,
        ];
    }

    private function assertGitSha(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
            throw new RuntimeException(ucfirst($label).' must be an exact lowercase Git SHA.');
        }
    }

    private function assertHash(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException(ucfirst($label).' must be an exact lowercase SHA-256.');
        }
    }

    private function fingerprint(mixed $value): string
    {
        $value = $this->normalizeForHash($value);

        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
}
