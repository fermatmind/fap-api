<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\ContentPackRelease;
use App\Services\Content\EnneagramPackLoader;
use App\Services\Content\EnneagramRegistryReleaseResolver;
use App\Services\Enneagram\Assets\EnneagramInactiveCandidateReleaseImporter;
use App\Services\Ops\EnneagramRegistryActivationGateService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Exact-package authority for the private Enneagram result cohort.
 *
 * This is deliberately separate from PersonalityPublicContentAsset: it stages
 * only the private result candidate release and never writes public profiles.
 */
final class EnneagramPrivateResultPromotionAuthority
{
    private const PACKAGE_MANIFEST_SCHEMA = 'fermatmind.en_parity.enneagram_private_result_package.v2';

    private const RELEASE_ACTION = 'enneagram_registry_import_inactive_candidate';

    private const PACK_ID = 'ENNEAGRAM';

    private const PACK_VERSION = 'v2';

    private const EXPECTED_ROWS = 630;

    private const EXPECTED_SOURCE_ASSETS = 1332;

    /** @var list<string> */
    private const REQUIRED_CANDIDATE_FILES = [
        'candidate_manifest.json',
        'candidate_hashes.json',
        'rollback_plan.md',
        'import_diff_summary.json',
        'replacement_additive_map.json',
        'source_mapping_report.json',
        'forbidden_claim_report.json',
        'legacy_residual_scan.json',
        'fc144_boundary_report.json',
        'phase8b_summary.json',
        'candidate_payloads_manifest.json',
        'candidate_payload_hashes.json',
        'candidate_payload_source_mapping.json',
    ];

    /** @var array<string,int> */
    private const EXPECTED_PAYLOAD_COUNTS = [
        'baseline' => 36,
        'low_resonance' => 108,
        'partial_resonance' => 90,
        'diffuse_convergence' => 108,
        'close_call_pair' => 36,
        'scene_localization' => 162,
        'fc144_recommendation' => 90,
    ];

    /** @var array<string,int> */
    private const EXPECTED_BRANCH_PAYLOAD_COUNTS = [
        'low_resonance_response' => 108,
        'partial_resonance_response' => 90,
        'diffuse_convergence_response' => 108,
        'close_call_pair' => 36,
        'scene_localization_response' => 162,
        'fc144_recommendation_response' => 90,
    ];

    /** @var list<string> */
    private const FORBIDDEN_PRIVATE_KEYS = [
        'attempt', 'attempt_id', 'raw_score', 'raw_scores', 'score_vector',
        'percentile', 'selector_trace', 'report_token', 'report_url', 'user',
        'user_id', 'order', 'payment', 'email', 'phone', 'answer', 'answers',
    ];

    public function __construct(
        private readonly EnneagramInactiveCandidateReleaseImporter $inactiveImporter,
        private readonly EnneagramRegistryActivationGateService $activationGate,
        private readonly EnneagramRegistryReleaseResolver $releaseResolver,
        private readonly EnneagramPackLoader $packLoader,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'W5' || $context->subscope !== 'enneagram-results'
            || $context->expectedRowCount !== self::EXPECTED_ROWS) {
            throw new DomainException('enneagram_private_result_promotion_context_invalid');
        }
        $this->assertAuditStorageCompatible();

        $root = realpath($context->packageDirectory);
        if ($root === false || ! is_dir($root) || is_link($root)) {
            throw new DomainException('enneagram_private_result_package_directory_invalid');
        }
        $manifestBytes = $this->read($root, 'package_manifest.json');
        $manifest = $this->decode($manifestBytes, 'enneagram_private_result_package_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== self::PACKAGE_MANIFEST_SCHEMA
            || ($manifest['lane_id'] ?? null) !== 'W5'
            || ($manifest['subscope'] ?? null) !== 'enneagram-results'
            || ($manifest['locale'] ?? null) !== 'en'
            || ($manifest['status'] ?? null) !== 'unpublished_candidate'
            || (int) ($manifest['expected_row_count'] ?? -1) !== self::EXPECTED_ROWS
            || (int) ($manifest['source_asset_count'] ?? -1) !== self::EXPECTED_SOURCE_ASSETS
            || (string) ($manifest['source_commit'] ?? '') !== $context->sourceCommit) {
            throw new DomainException('enneagram_private_result_package_manifest_contract_invalid');
        }

        $files = $this->verifyInventory($root, $manifest, $context->packageSha256);
        $candidateDir = $root.'/candidate';
        foreach (self::REQUIRED_CANDIDATE_FILES as $file) {
            if (! isset($files['candidate/'.$file])) {
                throw new DomainException('enneagram_private_result_candidate_artifact_missing');
            }
        }
        $payloadFiles = array_values(array_filter(array_keys($files), static fn (string $path): bool => str_starts_with($path, 'candidate/candidate_payloads/') && str_ends_with($path, '.json')));
        sort($payloadFiles, SORT_STRING);
        if (count($payloadFiles) !== self::EXPECTED_ROWS) {
            throw new DomainException('enneagram_private_result_payload_count_invalid');
        }

        $candidateManifestBytes = $files['candidate/candidate_manifest.json'];
        $candidateManifest = $this->decode($candidateManifestBytes, 'enneagram_private_result_candidate_manifest_invalid');
        $candidateHashes = $this->decode($files['candidate/candidate_hashes.json'], 'enneagram_private_result_candidate_hashes_invalid');
        $payloadManifest = $this->decode($files['candidate/candidate_payloads_manifest.json'], 'enneagram_private_result_payload_manifest_invalid');
        $payloadMapping = $this->decode($files['candidate/candidate_payload_source_mapping.json'], 'enneagram_private_result_payload_mapping_invalid');
        $candidateManifestSha = hash('sha256', $candidateManifestBytes);
        $runtimeRegistrySha = strtolower(trim((string) ($candidateHashes['runtime_registry_manifest_sha256'] ?? '')));
        if (! hash_equals($candidateManifestSha, strtolower((string) ($candidateHashes['candidate_manifest_sha256'] ?? '')))
            || preg_match('/\A[a-f0-9]{64}\z/', $runtimeRegistrySha) !== 1
            || (int) ($payloadManifest['total_payload_count'] ?? -1) !== self::EXPECTED_ROWS
            || array_values((array) ($candidateManifest['out_of_launch_scope'] ?? [])) !== ['1R-I', '1R-J']) {
            throw new DomainException('enneagram_private_result_candidate_identity_invalid');
        }
        if ((int) ($candidateManifest['candidate_item_count'] ?? -1) !== self::EXPECTED_SOURCE_ASSETS) {
            throw new DomainException('enneagram_private_result_source_asset_count_invalid');
        }
        if ((array) ($payloadManifest['payload_counts'] ?? []) !== self::EXPECTED_PAYLOAD_COUNTS
            || (array) ($payloadMapping['branch_payload_counts'] ?? []) !== self::EXPECTED_BRANCH_PAYLOAD_COUNTS) {
            throw new DomainException('enneagram_private_result_payload_matrix_invalid');
        }
        $this->assertCloseCallCoverage($candidateManifest, $payloadMapping);
        foreach ($payloadFiles as $path) {
            $payload = $this->decode($files[$path], 'enneagram_private_result_payload_invalid');
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $encoded) === 1) {
                throw new DomainException('enneagram_private_result_payload_cjk_leakage');
            }
            $this->assertNoPrivateFields($payload);
        }
        $this->assertW9($manifest, $context->packageSha256, self::EXPECTED_ROWS);

        return [
            'root' => $root,
            'candidate_dir' => $candidateDir,
            'package_sha256' => $context->packageSha256,
            'candidate_manifest_sha256' => $candidateManifestSha,
            'runtime_registry_manifest_sha256' => $runtimeRegistrySha,
            'release_id' => $this->releaseId($candidateManifestSha),
            'targets' => array_map(static fn (string $path): array => ['locale' => 'en', 'payload_path' => $path], $payloadFiles),
        ];
    }

    /** @return array{created:bool,release_id:string,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $existing = ContentPackRelease::query()->find($package['release_id']);
        if ($existing instanceof ContentPackRelease) {
            $this->assertReleaseBinding($existing, $context, $package);

            return ['created' => false, 'release_id' => $package['release_id'], 'readback_count' => self::EXPECTED_ROWS];
        }

        $summary = $this->inactiveImporter->import(
            $package['candidate_dir'],
            storage_path('app/content_promotion/enneagram/'.$context->packageSha256),
            [
                'candidate_manifest_sha256' => $package['candidate_manifest_sha256'],
                'runtime_registry_manifest_sha256' => $package['runtime_registry_manifest_sha256'],
            ],
        );
        if ((string) ($summary['inactive_release_id'] ?? '') !== $package['release_id']
            || (int) ($summary['candidate_payload_count'] ?? -1) !== self::EXPECTED_ROWS) {
            throw new DomainException('enneagram_private_result_inactive_import_readback_failed');
        }
        $release = ContentPackRelease::query()->find($package['release_id']);
        if (! $release instanceof ContentPackRelease) {
            throw new DomainException('enneagram_private_result_inactive_release_missing');
        }
        $payload = is_array($release->manifest_json) ? $release->manifest_json : [];
        $payload['exact_package'] = [
            'package_sha256' => $context->packageSha256,
            'source_commit' => $context->sourceCommit,
            'workflow_run_id' => $context->workflowRunId,
            'idempotency_key' => $context->idempotencyKey,
            'candidate_manifest_sha256' => $package['candidate_manifest_sha256'],
            'runtime_registry_manifest_sha256' => $package['runtime_registry_manifest_sha256'],
        ];
        $release->forceFill([
            'source_commit' => $context->sourceCommit,
            'compiled_hash' => 'sha256:'.$context->packageSha256,
            'content_hash' => 'sha256:'.$context->packageSha256,
            'manifest_json' => $payload,
        ])->saveQuietly();
        DB::table('content_release_manifests')->where('content_pack_release_id', $package['release_id'])->update([
            'compiled_hash' => 'sha256:'.$context->packageSha256,
            'content_hash' => 'sha256:'.$context->packageSha256,
            'source_commit' => $context->sourceCommit,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $this->assertReleaseBinding($release->fresh(), $context, $package);

        return ['created' => true, 'release_id' => $package['release_id'], 'readback_count' => self::EXPECTED_ROWS];
    }

    /** @return array{changed:bool,readback_count:int,published_count:int} */
    public function publish(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $release = ContentPackRelease::query()->find($package['release_id']);
        if (! $release instanceof ContentPackRelease) {
            throw new DomainException('enneagram_private_result_draft_missing');
        }
        $this->assertReleaseBinding($release, $context, $package);
        $activeBefore = $this->releaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        if (($activeBefore['active_release_id'] ?? null) === $package['release_id']) {
            return ['changed' => false, 'readback_count' => self::EXPECTED_ROWS, 'published_count' => self::EXPECTED_ROWS];
        }
        $this->activationGate->activateInactiveCandidateRelease(
            $package['release_id'],
            $package['release_id'],
            $package['candidate_manifest_sha256'],
            $package['runtime_registry_manifest_sha256'],
            storage_path('app/content_promotion/enneagram/'.$context->packageSha256.'/activation'),
            'content-promotion-v2',
        );

        return ['changed' => true, 'readback_count' => self::EXPECTED_ROWS, 'published_count' => self::EXPECTED_ROWS];
    }

    /** @return array{readback_count:int,published_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $release = ContentPackRelease::query()->find($package['release_id']);
        if (! $release instanceof ContentPackRelease) {
            throw new DomainException('enneagram_private_result_live_qa_release_missing');
        }
        $this->assertReleaseBinding($release, $context, $package);
        $runtime = $this->releaseResolver->runtimeRegistryContext(self::PACK_VERSION);
        if (($runtime['source'] ?? null) !== 'active_release' || ($runtime['active_release_id'] ?? null) !== $package['release_id']) {
            throw new DomainException('enneagram_private_result_live_qa_activation_mismatch');
        }
        $this->packLoader->loadRegistryPack(self::PACK_VERSION);

        return ['readback_count' => self::EXPECTED_ROWS, 'published_count' => self::EXPECTED_ROWS];
    }

    public function rollback(PromotionContext $context): void
    {
        $package = $this->inspect($context);
        $this->activationGate->rollbackInactiveCandidateRelease(
            $package['release_id'],
            $package['release_id'],
            storage_path('app/content_promotion/enneagram/'.$context->packageSha256.'/rollback'),
            'content-promotion-v2',
        );
    }

    public function activeReleaseId(): ?string
    {
        $context = $this->releaseResolver->runtimeRegistryContext(self::PACK_VERSION);

        return $context['source'] === 'active_release' ? $context['active_release_id'] : null;
    }

    /** @param array<string,mixed> $manifest @return array<string,string> */
    private function verifyInventory(string $root, array $manifest, string $packageSha): array
    {
        $entries = $manifest['files'] ?? null;
        if (! is_array($entries) || $entries === []) {
            throw new DomainException('enneagram_private_result_package_inventory_invalid');
        }
        $files = [];
        $chain = '';
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new DomainException('enneagram_private_result_package_file_entry_invalid');
            }
            $path = (string) ($entry['path'] ?? '');
            $declared = strtolower(trim((string) ($entry['sha256'] ?? '')));
            if (! $this->isSafePackagePath($path) || isset($files[$path]) || preg_match('/\A[a-f0-9]{64}\z/', $declared) !== 1) {
                throw new DomainException('enneagram_private_result_package_file_contract_invalid');
            }
            $files[$path] = $this->read($root, $path);
            if (! hash_equals($declared, hash('sha256', $files[$path]))) {
                throw new DomainException('enneagram_private_result_package_payload_sha256_mismatch');
            }
            $chain .= $path."\0".$declared."\n";
        }
        $actualPaths = $this->physicalFiles($root);
        $declaredPaths = array_keys($files);
        sort($declaredPaths, SORT_STRING);
        if ($actualPaths !== $declaredPaths || ! hash_equals($packageSha, hash('sha256', $chain))
            || ! hash_equals((string) ($manifest['package_sha256'] ?? ''), $packageSha)) {
            throw new DomainException('enneagram_private_result_package_inventory_mismatch');
        }

        return $files;
    }

    /** @param array<string,mixed> $candidateManifest @param array<string,mixed> $payloadMapping */
    private function assertCloseCallCoverage(array $candidateManifest, array $payloadMapping): void
    {
        $pairs = $payloadMapping['close_call_pairs'] ?? $candidateManifest['close_call_pairs'] ?? null;
        if (! is_array($pairs) || count($pairs) !== 36) {
            throw new DomainException('enneagram_private_result_close_call_coverage_invalid');
        }
        $actual = [];
        foreach ($pairs as $pair) {
            $key = is_array($pair) ? (string) ($pair['pair_key'] ?? '') : (string) $pair;
            if (preg_match('/\A([1-9])_([1-9])\z/', $key, $matches) !== 1 || $matches[1] >= $matches[2]) {
                throw new DomainException('enneagram_private_result_close_call_pair_invalid');
            }
            $actual[] = $key;
        }
        $expected = [];
        for ($a = 1; $a <= 9; $a++) {
            for ($b = $a + 1; $b <= 9; $b++) {
                $expected[] = $a.'_'.$b;
            }
        }
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new DomainException('enneagram_private_result_close_call_coverage_invalid');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertW9(array $manifest, string $packageSha, int $rows): void
    {
        $gate = data_get($manifest, 'quality_gates.independent_w9');
        $root = realpath((string) config('content_promotion.w9_authority_root'));
        if (! is_array($gate) || $root === false || is_link($root)) {
            throw new DomainException('enneagram_private_result_w9_evidence_incomplete');
        }
        $ref = (string) ($gate['report_ref'] ?? '');
        if (! $this->isSafePackagePath($ref) || ! is_file($root.'/'.$ref) || is_link($root.'/'.$ref)) {
            throw new DomainException('enneagram_private_result_w9_evidence_incomplete');
        }
        $bytes = (string) File::get($root.'/'.$ref);
        if (! hash_equals((string) ($gate['report_sha256'] ?? ''), hash('sha256', $bytes))) {
            throw new DomainException('enneagram_private_result_w9_evidence_incomplete');
        }
        $report = $this->decode($bytes, 'enneagram_private_result_w9_evidence_incomplete');
        if (($report['schema_version'] ?? null) !== 'fermatmind.en_parity.independent_w9_report.v1'
            || ($report['review_kind'] ?? null) !== 'independent_w9'
            || ($report['verdict'] ?? null) !== 'PASS'
            || ($report['package_sha256'] ?? null) !== $packageSha
            || ($report['lane_id'] ?? null) !== 'W5'
            || ($report['subscope'] ?? null) !== 'enneagram-results'
            || (int) ($report['reviewed_row_count'] ?? -1) !== $rows) {
            throw new DomainException('enneagram_private_result_w9_evidence_incomplete');
        }
    }

    /** @param array<string,mixed> $value */
    private function assertNoPrivateFields(array $value): void
    {
        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_PRIVATE_KEYS, true)) {
                throw new DomainException('enneagram_private_result_payload_private_field');
            }
            if (is_array($item)) {
                $this->assertNoPrivateFields($item);
            }
        }
    }

    /** @param array<string,mixed> $package */
    private function assertReleaseBinding(?ContentPackRelease $release, PromotionContext $context, array $package): void
    {
        if (! $release instanceof ContentPackRelease || (string) $release->action !== self::RELEASE_ACTION
            || (string) $release->to_pack_id !== self::PACK_ID || (string) $release->pack_version !== self::PACK_VERSION
            || ! hash_equals((string) $release->source_commit, $context->sourceCommit)
            || ! hash_equals((string) $release->compiled_hash, 'sha256:'.$context->packageSha256)) {
            throw new DomainException('enneagram_private_result_release_identity_collision');
        }
        $payload = is_array($release->manifest_json) ? $release->manifest_json : [];
        if (data_get($payload, 'exact_package.package_sha256') !== $context->packageSha256
            || data_get($payload, 'exact_package.source_commit') !== $context->sourceCommit
            || data_get($payload, 'exact_package.candidate_manifest_sha256') !== $package['candidate_manifest_sha256']) {
            throw new DomainException('enneagram_private_result_release_binding_invalid');
        }
    }

    private function assertAuditStorageCompatible(): void
    {
        if (! Schema::hasColumns('content_pack_releases', ['source_commit', 'manifest_json', 'compiled_hash', 'content_hash', 'storage_path'])
            || ! Schema::hasColumns('content_release_manifests', ['source_commit', 'payload_json', 'compiled_hash', 'content_hash'])) {
            throw new DomainException('enneagram_private_result_audit_storage_incompatible');
        }
    }

    private function releaseId(string $candidateManifestSha): string
    {
        return 'enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_'.substr($candidateManifestSha, 0, 8);
    }

    private function read(string $root, string $path): string
    {
        if (! $this->isSafePackagePath($path) || ! is_file($root.'/'.$path) || is_link($root.'/'.$path)) {
            throw new DomainException('enneagram_private_result_package_path_invalid');
        }

        return (string) File::get($root.'/'.$path);
    }

    /** @return list<string> */
    private function physicalFiles(string $root): array
    {
        $paths = [];
        foreach (File::allFiles($root) as $file) {
            if ($file->isLink()) {
                throw new DomainException('enneagram_private_result_package_symlink_rejected');
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
            if ($path !== 'package_manifest.json') {
                $paths[] = $path;
            }
        }
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function isSafePackagePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '..')
            && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/\-]*\z/', $path) === 1;
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException($error);
        }

        if (! is_array($decoded)) {
            throw new DomainException($error);
        }

        return $decoded;
    }
}
