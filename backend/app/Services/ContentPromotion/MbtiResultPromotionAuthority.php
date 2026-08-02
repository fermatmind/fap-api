<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Services\Content\ContentPackV2Resolver;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Database-backed, package-derived authority for the W1 English MBTI result
 * cohort.  The package chain SHA is the only content identity accepted here;
 * this service deliberately never writes the deployed content-pack tree.
 */
final class MbtiResultPromotionAuthority
{
    private const PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    private const EXTERNAL_W9_REPORT_SHA256 = 'b1179976102f903b36de9888ee4cb0b4dd74fc3906d5951682922464bc8dec7d';

    private const EXTERNAL_W9_ENVELOPE_SHA256 = 'ad1b74998d0e245076197fa3135d150c0757eb48c241b25ffb9966db55028bfe';

    private const EXTERNAL_W9_SOURCE_COMMIT = 'e623de1894b54b3ef70faae9d17fac4d216337ee';

    private const EXTERNAL_W9_SOURCE_PATH = 'generated/en-content-parity/W9-independent-qa/W1-mbti-result-content/9325013b-renderer-643b7a80/independent_qa_report.json';

    private const PACKAGE_MANIFEST_SCHEMA = 'fermatmind.en_parity.immutable_content_package_manifest.v1';

    private const PACK_ID = 'MBTI.global.en.default';

    private const PACK_VERSION = 'v0.3';

    private const REQUIRED_FILES = [
        'assets.json',
        'editorial_review.json',
        'inventory_reconciliation.json',
        'entitlement_matrix.json',
        'pdf_reader_fixture_mapping.json',
    ];

    private const REQUIRED_SURFACES = [
        'free_result',
        'preview_result',
        'locked_result',
        'full_result',
        'share_public_summary',
        'pdf_reader',
        'history_account_reentry',
    ];

    private const FORBIDDEN_PRIVATE_KEYS = [
        'attempt', 'report_token', 'report_url', 'order', 'payment', 'user_id',
        'account_id', 'email', 'phone', 'answer', 'raw_score', 'score_vector',
        'recovery', 'secret', 'authorization', 'cookie',
    ];

    public function __construct(private readonly ContentPackV2Resolver $runtimeResolver) {}

    /** @return array<string, mixed> */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'W1' || $context->subscope !== 'mbti-results') {
            throw new DomainException('mbti_result_promotion_context_invalid');
        }

        $root = realpath($context->packageDirectory);
        if ($root === false || ! is_dir($root) || is_link($root)) {
            throw new DomainException('mbti_result_package_directory_invalid');
        }
        $manifestBytes = $this->readFile($root, 'package_manifest.json');
        $manifest = $this->decode($manifestBytes, 'mbti_result_package_manifest_invalid');
        if (($manifest['schema_version'] ?? null) !== self::PACKAGE_MANIFEST_SCHEMA
            || ($manifest['lane_id'] ?? null) !== 'W1'
            || ($manifest['locale'] ?? null) !== 'en'
            || ($manifest['status'] ?? null) !== 'unpublished_candidate') {
            throw new DomainException('mbti_result_package_manifest_contract_invalid');
        }
        $this->assertNoPermissionEscalation($manifest['permissions'] ?? null);

        $entries = $manifest['files'] ?? null;
        if (! is_array($entries) || $entries === []) {
            throw new DomainException('mbti_result_package_files_invalid');
        }
        $files = [];
        $chain = '';
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new DomainException('mbti_result_package_file_entry_invalid');
            }
            $path = trim((string) ($entry['path'] ?? ''));
            $sha256 = strtolower(trim((string) ($entry['sha256'] ?? '')));
            if ($path === '' || basename($path) !== $path || str_contains($path, '..')
                || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1 || isset($files[$path])) {
                throw new DomainException('mbti_result_package_file_contract_invalid');
            }
            $bytes = $this->readFile($root, $path);
            if (! hash_equals($sha256, hash('sha256', $bytes))) {
                throw new DomainException('mbti_result_package_payload_sha256_mismatch');
            }
            $files[$path] = $bytes;
            $chain .= $path."\0".$sha256."\n";
        }
        $packageSha256 = hash('sha256', $chain);
        if (! hash_equals(self::PACKAGE_SHA256, $packageSha256)
            || ! hash_equals(strtolower((string) ($manifest['package_sha256'] ?? '')), $packageSha256)
            || ! hash_equals($context->packageSha256, $packageSha256)) {
            throw new DomainException('mbti_result_package_sha256_mismatch');
        }
        foreach (self::REQUIRED_FILES as $required) {
            if (! array_key_exists($required, $files)) {
                throw new DomainException('mbti_result_package_required_payload_missing');
            }
        }
        if (($manifest['quality_gates']['independent_w9'] ?? null) !== 'pending') {
            throw new DomainException('mbti_result_package_w9_self_acceptance_forbidden');
        }
        $this->assertImmutableExternalW9Evidence($packageSha256);

        $assets = $this->decode($files['assets.json'], 'mbti_result_assets_json_invalid');
        $inventory = $this->decode($files['inventory_reconciliation.json'], 'mbti_result_inventory_json_invalid');
        $entitlements = $this->decode($files['entitlement_matrix.json'], 'mbti_result_entitlements_json_invalid');
        $pdf = $this->decode($files['pdf_reader_fixture_mapping.json'], 'mbti_result_pdf_json_invalid');
        $authorityTarget = data_get($assets, 'template_contract.authority_targets.english_commercial_spec_v1');
        if (($assets['locale'] ?? null) !== 'en'
            || ! is_array($authorityTarget)
            || ($authorityTarget['scale_code'] ?? null) !== 'MBTI'
            || ($authorityTarget['region'] ?? null) !== 'GLOBAL'
            || ($authorityTarget['locale'] ?? null) !== 'en'
            || ($authorityTarget['pack_id'] ?? null) !== self::PACK_ID
            || ($authorityTarget['content_package_version'] ?? null) !== self::PACK_VERSION) {
            throw new DomainException('mbti_result_target_identity_invalid');
        }
        $assetRows = $assets['assets'] ?? null;
        $inventoryRows = $inventory['rows'] ?? null;
        if (! is_array($assetRows) || ! is_array($inventoryRows)
            || (int) ($assets['asset_count'] ?? 0) !== count($assetRows)
            || (int) ($manifest['inventory_row_count'] ?? 0) !== count($inventoryRows)
            || count($inventoryRows) !== $context->expectedRowCount) {
            throw new DomainException('mbti_result_package_row_count_mismatch');
        }

        $assetByRow = [];
        foreach ($assetRows as $asset) {
            if (! is_array($asset) || ! is_string($asset['row_id'] ?? null) || $asset['row_id'] === '' || isset($assetByRow[$asset['row_id']])) {
                throw new DomainException('mbti_result_asset_identity_invalid');
            }
            $this->assertSafePayload($asset);
            if (preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', (string) json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === 1) {
                throw new DomainException('mbti_result_asset_cjk_leakage');
            }
            $assetByRow[$asset['row_id']] = $asset;
        }
        $rows = [];
        $seen = [];
        $candidateCount = 0;
        $fixtureCount = 0;
        $preservedCount = 0;
        foreach ($inventoryRows as $position => $row) {
            if (! is_array($row) || ! is_string($row['row_id'] ?? null) || $row['row_id'] === '' || isset($seen[$row['row_id']])) {
                throw new DomainException('mbti_result_inventory_identity_invalid');
            }
            $seen[$row['row_id']] = true;
            $disposition = (string) ($row['disposition'] ?? '');
            if (! in_array($disposition, ['candidate_asset', 'preserved_reference', 'w9_fixture_target'], true)) {
                throw new DomainException('mbti_result_inventory_disposition_invalid');
            }
            $authorityRow = [
                'position' => $position + 1,
                'row_id' => $row['row_id'],
                'disposition' => $disposition,
                'authority_state' => $disposition === 'candidate_asset' ? 'draft' : 'reference',
            ];
            if ($disposition === 'candidate_asset') {
                $asset = $assetByRow[$row['row_id']] ?? null;
                if (! is_array($asset)) {
                    throw new DomainException('mbti_result_candidate_asset_missing');
                }
                $authorityRow['asset'] = $asset;
                $authorityRow['asset_sha256'] = hash('sha256', PromotionContextFactory::canonicalJson($asset));
                $candidateCount++;
            }
            if ($disposition === 'w9_fixture_target') {
                $fixtureCount++;
            }
            if ($disposition === 'preserved_reference') {
                $preservedCount++;
            }
            $rows[] = $authorityRow;
        }
        if ($candidateCount !== count($assetRows)
            || $candidateCount !== (int) ($manifest['candidate_asset_count'] ?? -1)
            || $fixtureCount !== (int) ($manifest['w9_fixture_target_count'] ?? -1)
            || $preservedCount !== (int) ($manifest['preserved_control_count'] ?? -1)) {
            throw new DomainException('mbti_result_reconciliation_contract_invalid');
        }
        $surfaces = array_values(array_filter(array_map(
            static fn (mixed $surface): ?string => is_array($surface) && is_string($surface['surface'] ?? null) ? $surface['surface'] : null,
            (array) ($entitlements['surfaces'] ?? []),
        )));
        foreach (self::REQUIRED_SURFACES as $surface) {
            if (! in_array($surface, $surfaces, true)) {
                throw new DomainException('mbti_result_safe_surface_contract_invalid');
            }
        }
        if (($pdf['fixture_contract']['required_locale'] ?? null) !== 'en') {
            throw new DomainException('mbti_result_pdf_fixture_contract_invalid');
        }

        $authority = [
            'schema_version' => 'mbti_result_promotion.v2',
            'authority' => [
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
                'scale_code' => 'MBTI',
                'region' => 'GLOBAL',
                'locale' => 'en',
                'state' => 'draft',
                'runtime_fallback_registered' => false,
            ],
            'source' => [
                'package_id' => (string) ($manifest['package_id'] ?? ''),
                'package_sha256' => $packageSha256,
                'manifest_sha256' => hash('sha256', $manifestBytes),
            ],
            'counts' => [
                'rows' => count($rows),
                'candidate_assets' => $candidateCount,
                'fixture_rows' => $fixtureCount,
            ],
            'safe_surface_contract' => $surfaces,
            'rows' => $rows,
        ];
        $this->assertSafePayload($authority);
        $authorityHash = hash('sha256', PromotionContextFactory::canonicalJson($authority));

        return [
            'package_sha256' => $packageSha256,
            'authority' => $authority,
            'authority_hash' => $authorityHash,
            'release_id' => $this->deterministicUuid('fermatmind:content-promotion:w1-mbti-results:'.$packageSha256),
            'targets' => array_map(static fn (array $row): array => ['locale' => 'en', 'pack_id' => self::PACK_ID, 'row_id' => (string) $row['row_id']], $rows),
        ];
    }

    /** @return array{created:bool,release_id:string,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($package, $context): array {
            $release = DB::table('content_pack_releases')->where('id', $package['release_id'])->lockForUpdate()->first();
            if ($release !== null) {
                if ((string) $release->action !== 'content_promotion_w1_mbti_results_v2'
                    || (string) $release->to_pack_id !== self::PACK_ID
                    || (string) $release->locale !== 'en'
                    || (string) $release->status !== 'success'
                    || ! hash_equals((string) $release->compiled_hash, $context->packageSha256)
                    || ! hash_equals((string) $release->manifest_hash, $package['authority_hash'])
                    || ! $this->matchesAuthorityPayload((string) $release->manifest_json, $package['authority'])) {
                    throw new DomainException('mbti_result_release_identity_collision');
                }
                $manifest = DB::table('content_release_manifests')->where('manifest_hash', $package['authority_hash'])->lockForUpdate()->first();
                if ($manifest === null
                    || (string) $manifest->content_pack_release_id !== $package['release_id']
                    || ! hash_equals((string) $manifest->compiled_hash, $context->packageSha256)
                    || ! hash_equals((string) $manifest->content_hash, $package['authority_hash'])
                    || ! $this->matchesAuthorityPayload((string) $manifest->payload_json, $package['authority'])) {
                    throw new DomainException('mbti_result_manifest_readback_missing');
                }

                return ['created' => false, 'release_id' => $package['release_id'], 'readback_count' => count($package['targets'])];
            }
            $now = now();
            $canonicalAuthority = PromotionContextFactory::canonicalJson($package['authority']);
            $manifest = DB::table('content_release_manifests')->where('manifest_hash', $package['authority_hash'])->lockForUpdate()->first();
            if ($manifest !== null) {
                throw new DomainException('mbti_result_manifest_hash_collision');
            }
            DB::table('content_pack_releases')->insert([
                'id' => $package['release_id'],
                'action' => 'content_promotion_w1_mbti_results_v2',
                'region' => 'GLOBAL',
                'locale' => 'en',
                'dir_alias' => 'MBTI-GLOBAL-en-v0.3',
                'to_pack_id' => self::PACK_ID,
                'status' => 'success',
                'message' => 'Exact package-derived inactive MBTI result authority.',
                'created_by' => 'content-promotion-v2',
                'manifest_hash' => $package['authority_hash'],
                'compiled_hash' => $context->packageSha256,
                'content_hash' => $package['authority_hash'],
                'pack_version' => self::PACK_VERSION,
                'manifest_json' => $canonicalAuthority,
                'storage_path' => 'database/content_pack_releases/'.$package['release_id'],
                'source_commit' => $context->sourceCommit,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('content_release_manifests')->insert([
                'content_pack_release_id' => $package['release_id'],
                'manifest_hash' => $package['authority_hash'],
                'schema_version' => 'mbti_result_promotion.v2',
                'storage_disk' => 'database',
                'storage_path' => 'content_pack_releases/'.$package['release_id'],
                'pack_id' => self::PACK_ID,
                'pack_version' => self::PACK_VERSION,
                'compiled_hash' => $context->packageSha256,
                'content_hash' => $package['authority_hash'],
                'source_commit' => $context->sourceCommit,
                'payload_json' => $canonicalAuthority,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['created' => true, 'release_id' => $package['release_id'], 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{changed:bool,release_id:string,readback_count:int,published_count:int} */
    public function publish(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($package, $context): array {
            $release = $this->exactRelease($package, $context, true);
            $activation = DB::table('content_pack_activations')
                ->where('pack_id', self::PACK_ID)->where('pack_version', self::PACK_VERSION)->lockForUpdate()->first();
            $changed = $activation === null || (string) $activation->release_id !== $package['release_id'];
            if ($changed) {
                DB::table('content_pack_activations')->updateOrInsert(
                    ['pack_id' => self::PACK_ID, 'pack_version' => self::PACK_VERSION],
                    ['release_id' => $release->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                );
            }

            return ['changed' => $changed, 'release_id' => $package['release_id'], 'readback_count' => count($package['targets']), 'published_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{readback_count:int,published_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $activation = DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)->where('pack_version', self::PACK_VERSION)->first();
        if ($activation === null || (string) $activation->release_id !== $package['release_id']) {
            throw new DomainException('mbti_result_live_qa_activation_mismatch');
        }
        $release = $this->exactRelease($package, $context, false);
        $payload = $this->decode((string) $release->manifest_json, 'mbti_result_live_qa_payload_invalid');
        $runtimePayload = $this->runtimeResolver->resolveActiveMbtiResultAuthority();
        if ($runtimePayload === null
            || ! hash_equals(
                PromotionContextFactory::canonicalJson($payload),
                PromotionContextFactory::canonicalJson($runtimePayload),
            )) {
            throw new DomainException('mbti_result_live_qa_runtime_projection_mismatch');
        }
        if (($payload['source']['package_sha256'] ?? null) !== $context->packageSha256
            || ($payload['authority']['locale'] ?? null) !== 'en'
            || ($payload['authority']['pack_id'] ?? null) !== self::PACK_ID
            || count($payload['rows'] ?? []) !== $context->expectedRowCount) {
            throw new DomainException('mbti_result_live_qa_readback_mismatch');
        }
        foreach (self::REQUIRED_SURFACES as $surface) {
            if (! in_array($surface, (array) ($payload['safe_surface_contract'] ?? []), true)) {
                throw new DomainException('mbti_result_live_qa_surface_mismatch');
            }
        }
        $this->assertSafePayload($payload);

        return ['readback_count' => count($package['targets']), 'published_count' => count($package['targets'])];
    }

    public function activationReleaseId(): ?string
    {
        $value = DB::table('content_pack_activations')
            ->where('pack_id', self::PACK_ID)->where('pack_version', self::PACK_VERSION)->value('release_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function restoreActivation(?string $releaseId): void
    {
        DB::transaction(function () use ($releaseId): void {
            DB::table('content_pack_activations')
                ->where('pack_id', self::PACK_ID)->where('pack_version', self::PACK_VERSION)->lockForUpdate()->first();
            if ($releaseId === null) {
                DB::table('content_pack_activations')->where('pack_id', self::PACK_ID)->where('pack_version', self::PACK_VERSION)->delete();

                return;
            }
            if (! DB::table('content_pack_releases')->where('id', $releaseId)->exists()) {
                throw new DomainException('mbti_result_rollback_release_missing');
            }
            DB::table('content_pack_activations')->updateOrInsert(
                ['pack_id' => self::PACK_ID, 'pack_version' => self::PACK_VERSION],
                ['release_id' => $releaseId, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
        }, 3);
    }

    /** @param array<string,mixed> $package */
    private function exactRelease(array $package, PromotionContext $context, bool $lock): object
    {
        $query = DB::table('content_pack_releases')->where('id', $package['release_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $release = $query->first();
        if ($release === null
            || (string) $release->action !== 'content_promotion_w1_mbti_results_v2'
            || (string) $release->to_pack_id !== self::PACK_ID
            || (string) $release->locale !== 'en'
            || (string) $release->status !== 'success'
            || ! hash_equals((string) $release->compiled_hash, $context->packageSha256)
            || ! hash_equals((string) $release->manifest_hash, $package['authority_hash'])
            || ! $this->matchesAuthorityPayload((string) $release->manifest_json, $package['authority'])) {
            throw new DomainException('mbti_result_exact_draft_authority_missing');
        }
        $manifest = DB::table('content_release_manifests')->where('manifest_hash', $package['authority_hash'])->first();
        if ($manifest === null
            || (string) $manifest->content_pack_release_id !== $package['release_id']
            || ! hash_equals((string) $manifest->compiled_hash, $context->packageSha256)
            || ! hash_equals((string) $manifest->content_hash, $package['authority_hash'])
            || ! $this->matchesAuthorityPayload((string) $manifest->payload_json, $package['authority'])) {
            throw new DomainException('mbti_result_exact_manifest_missing');
        }

        return $release;
    }

    private function readFile(string $root, string $name): string
    {
        if ($name === '' || basename($name) !== $name || str_contains($name, '..')) {
            throw new DomainException('mbti_result_package_path_invalid');
        }
        $path = $root.DIRECTORY_SEPARATOR.$name;
        $resolved = realpath($path);
        $stat = @lstat($path);
        if (! is_file($path) || is_link($path) || $resolved === false || dirname($resolved) !== $root
            || ! is_array($stat) || (int) ($stat['nlink'] ?? 0) !== 1) {
            throw new DomainException('mbti_result_package_payload_missing');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new DomainException('mbti_result_package_payload_unreadable');
        }

        return $bytes;
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes, string $error): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new DomainException($error);
        }
        if (! is_array($value)) {
            throw new DomainException($error);
        }

        return $value;
    }

    private function assertNoPermissionEscalation(mixed $permissions): void
    {
        if (! is_array($permissions)) {
            throw new DomainException('mbti_result_package_permissions_invalid');
        }
        foreach ($permissions as $permission) {
            if ($permission !== false) {
                throw new DomainException('mbti_result_package_permission_escalation');
            }
        }
    }

    private function assertImmutableExternalW9Evidence(string $packageSha256): void
    {
        $configuredRoot = (string) config(
            'content_promotion.mbti_result_external_w9_root',
            'content_assets/en-content-parity/W9/mbti-results/9325013b-renderer-643b7a80',
        );
        $rootPath = str_starts_with($configuredRoot, DIRECTORY_SEPARATOR) ? $configuredRoot : base_path($configuredRoot);
        $root = realpath($rootPath);
        $envelopePath = ($root ?: '').DIRECTORY_SEPARATOR.'external_evidence_envelope.json';
        $reportPath = ($root ?: '').DIRECTORY_SEPARATOR.'independent_qa_report.json';
        $resolvedEnvelope = realpath($envelopePath);
        $resolvedReport = realpath($reportPath);
        if ($root === false || is_link($rootPath)
            || ! is_file($envelopePath) || is_link($envelopePath) || $resolvedEnvelope === false || dirname($resolvedEnvelope) !== $root
            || ! is_file($reportPath) || is_link($reportPath) || $resolvedReport === false || dirname($resolvedReport) !== $root) {
            throw new DomainException('mbti_result_external_w9_evidence_incomplete');
        }
        $envelopeBytes = File::get($envelopePath);
        $reportBytes = File::get($reportPath);
        if (! hash_equals(self::EXTERNAL_W9_ENVELOPE_SHA256, hash('sha256', $envelopeBytes))
            || ! hash_equals(self::EXTERNAL_W9_REPORT_SHA256, hash('sha256', $reportBytes))) {
            throw new DomainException('mbti_result_external_w9_evidence_incomplete');
        }
        $envelope = $this->decode($envelopeBytes, 'mbti_result_external_w9_evidence_incomplete');
        $report = $this->decode($reportBytes, 'mbti_result_external_w9_evidence_incomplete');
        if (($envelope['schema_version'] ?? null) !== 'fermatmind.content_promotion.external_w9_evidence_envelope.v1'
            || ($envelope['source_repository'] ?? null) !== 'fermatmind/fap-web'
            || ($envelope['source_commit'] ?? null) !== self::EXTERNAL_W9_SOURCE_COMMIT
            || ($envelope['source_path'] ?? null) !== self::EXTERNAL_W9_SOURCE_PATH
            || ($envelope['report_sha256'] ?? null) !== self::EXTERNAL_W9_REPORT_SHA256
            || ($envelope['package_sha256'] ?? null) !== self::PACKAGE_SHA256
            || ($envelope['producer_lane_id'] ?? null) !== 'W1'
            || ($envelope['subscope_id'] ?? null) !== 'W1-MBTI-RESULT-CONTENT'
            || ($envelope['promotion_subscope'] ?? null) !== 'mbti-results'
            || ($envelope['reviewed_row_count'] ?? null) !== 46
            || ($envelope['authority_content_count'] ?? null) !== 21
            || ($envelope['verdict'] ?? null) !== 'PASS'
            || ($report['schema_version'] ?? null) !== 'fermatmind.en_content_parity_independent_qa_report.v1'
            || ($report['artifact_kind'] ?? null) !== 'independent_qa_report'
            || ($report['qa_lane_id'] ?? null) !== 'W9'
            || ($report['producer_lane_id'] ?? null) !== 'W1'
            || ($report['subscope_id'] ?? null) !== 'W1-MBTI-RESULT-CONTENT'
            || ($report['verdict'] ?? null) !== 'PASS'
            || ($report['package_sha256'] ?? null) !== $packageSha256
            || (int) ($report['reviewed_row_count'] ?? 0) !== 46) {
            throw new DomainException('mbti_result_external_w9_evidence_incomplete');
        }
    }

    private function assertSafePayload(mixed $value, ?string $key = null): void
    {
        if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PRIVATE_KEYS, true)) {
            throw new DomainException('mbti_result_private_payload_forbidden');
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $nestedKey => $nested) {
            $this->assertSafePayload($nested, is_string($nestedKey) ? $nestedKey : null);
        }
    }

    private function deterministicUuid(string $value): string
    {
        $hash = hash('sha256', $value);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-5'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }

    /** @param array<string,mixed> $authority */
    private function matchesAuthorityPayload(string $json, array $authority): bool
    {
        try {
            return hash_equals(PromotionContextFactory::canonicalJson($authority), PromotionContextFactory::canonicalJson($this->decode($json, 'mbti_result_authority_payload_invalid')));
        } catch (DomainException) {
            return false;
        }
    }
}
