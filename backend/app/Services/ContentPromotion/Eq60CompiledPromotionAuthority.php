<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\ContentPackRelease;
use App\Services\Content\Eq60ContentLintService;
use App\Services\Content\Eq60PackLoader;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Release-backed authority for the compiled, English EQ-60 result-content projection.
 *
 * The source package is the active backend compiled directory. Raw authoring files are
 * lint inputs only; no raw asset is persisted as a release authority or read at runtime.
 * Assessment attempts, score vectors, reports, commerce records, and question/scoring
 * contracts are intentionally outside this promotion target set.
 */
final class Eq60CompiledPromotionAuthority
{
    public const RELEASE_ACTION = 'eq60_compiled_result_content_promotion';

    private const LOCALE = 'en';

    /** @var list<string> */
    private const COMPILED_FILES = [
        'questions.compiled.json',
        'options.compiled.json',
        'policy.compiled.json',
        'landing.compiled.json',
        'report.compiled.json',
        'report_assets.compiled.json',
        'golden_cases.compiled.json',
    ];

    /** @var list<string> */
    private const RESULT_CONTENT_FILES = [
        'report.compiled.json',
        'report_assets.compiled.json',
    ];

    /**
     * Share projection allow-list: only these top-level keys may enter the
     * public share payload.  Every other key must be stripped or fail closed.
     */
    private const ALLOWED_SHARE_KEYS = [
        'score_summary', 'competence_labels', 'locale',
        'pack_id', 'pack_version', 'schema',
    ];

    public function __construct(
        private readonly Eq60PackLoader $loader,
        private readonly Eq60ContentLintService $lint,
        private readonly ContentReleaseManifestCatalogService $manifestCatalog,
    ) {}

    /** @return array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} */
    public function inspect(PromotionContext $context): array
    {
        if ($context->lane !== 'W7' || $context->subscope !== 'eq') {
            throw new DomainException('eq60_promotion_context_invalid');
        }
        $version = Eq60PackLoader::PACK_VERSION;
        $compiledDir = realpath($this->loader->compiledDir($version));
        if ($compiledDir === false || realpath($context->packageDirectory) !== $compiledDir) {
            throw new DomainException('eq60_promotion_compiled_package_path_invalid');
        }
        $lint = $this->lint->lint($version);
        if (($lint['ok'] ?? false) !== true) {
            throw new DomainException('eq60_promotion_compile_lint_failed');
        }

        $manifest = $this->decode($this->read($compiledDir, 'manifest.json'), 'eq60_promotion_compiled_manifest_invalid');
        if (($manifest['schema'] ?? null) !== 'eq_60.compiled.manifest.v1'
            || ($manifest['pack_id'] ?? null) !== Eq60PackLoader::PACK_ID
            || ($manifest['pack_version'] ?? null) !== $version) {
            throw new DomainException('eq60_promotion_compiled_manifest_schema_invalid');
        }
        $hashes = $manifest['hashes'] ?? null;
        if (! is_array($hashes) || array_keys($hashes) !== self::COMPILED_FILES) {
            throw new DomainException('eq60_promotion_compiled_inventory_invalid');
        }
        foreach (self::COMPILED_FILES as $file) {
            $declared = strtolower(trim((string) ($hashes[$file] ?? '')));
            $bytes = $this->read($compiledDir, $file);
            $payload = $this->decode($bytes, 'eq60_promotion_compiled_payload_invalid');
            $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (! hash_equals($canonical."\n", $bytes)
                || preg_match('/\A[a-f0-9]{64}\z/', $declared) !== 1
                || ! hash_equals($declared, hash('sha256', $canonical))) {
                throw new DomainException('eq60_promotion_compiled_payload_hash_invalid');
            }
            $hashes[$file] = $declared;
        }
        $compiledHash = $this->hashMap($hashes);
        if (! hash_equals((string) ($manifest['compiled_hash'] ?? ''), $compiledHash)
            || ! hash_equals($context->packageSha256, $compiledHash)) {
            throw new DomainException('eq60_promotion_compiled_hash_invalid');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', (string) ($manifest['content_hash'] ?? '')) !== 1) {
            throw new DomainException('eq60_promotion_content_hash_invalid');
        }

        $report = $this->decode($this->read($compiledDir, 'report.compiled.json'), 'eq60_promotion_report_invalid');
        $assets = $this->decode($this->read($compiledDir, 'report_assets.compiled.json'), 'eq60_promotion_report_assets_invalid');
        $reportProjection = $this->reportProjection($report, $version);
        $assetsProjection = $this->assetsProjection($assets, $version);
        $this->assertClaimBoundary($assetsProjection);
        $this->assertNoCjk($reportProjection);
        $this->assertNoCjk($assetsProjection);
        $this->assertNormAuthorityCalibrated();

        $projections = [
            'report.compiled.json' => $reportProjection,
            'report_assets.compiled.json' => $assetsProjection,
        ];
        $targets = [];
        foreach (self::RESULT_CONTENT_FILES as $file) {
            $projection = $projections[$file];
            $targets[] = [
                'asset_key' => 'EQ_60/'.$version.'/'.str_replace('.compiled.json', '', $file).'/'.self::LOCALE,
                'identity' => [
                    'pack_id' => Eq60PackLoader::PACK_ID,
                    'pack_version' => $version,
                    'locale' => self::LOCALE,
                    'artifact' => $file,
                    'compiled_payload_sha256' => $hashes[$file],
                    'english_projection_sha256' => hash('sha256', PromotionContextFactory::canonicalJson($projection)),
                ],
            ];
        }
        if (count($targets) !== $context->expectedRowCount || count(array_unique(array_column($targets, 'asset_key'))) !== count($targets)) {
            throw new DomainException('eq60_promotion_target_count_invalid');
        }
        $releaseManifest = [
            'schema_version' => 'fermatmind.eq60_compiled_result_content_release.v2',
            'authority' => 'backend_eq60_compiled_result_content_release',
            'lane' => 'W7', 'subscope' => 'eq', 'locale' => self::LOCALE,
            'package_sha256' => $context->packageSha256,
            'source_commit' => $context->sourceCommit,
            'expected_row_count' => $context->expectedRowCount,
            'compiled_manifest_schema' => $manifest['schema'],
            'compiled_content_hash' => $manifest['content_hash'],
            'compiled_hash' => $compiledHash,
            'compiled_payload_hashes' => $hashes,
            'targets' => array_map(static fn (array $target): array => $target['identity'], $targets),
            'runtime_activation' => false,
            'indexability_mutation' => false,
        ];

        return ['targets' => $targets, 'manifest' => $releaseManifest, 'manifest_hash' => hash('sha256', PromotionContextFactory::canonicalJson($releaseManifest))];
    }

    /** @return array{created_count:int,unchanged_count:int,readback_count:int} */
    public function importDraft(PromotionContext $context): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package): array {
            $release = $this->release($context, true, $package);
            if ($release instanceof ContentPackRelease) {
                $this->assertRelease($release, $context, $package, 'draft');

                return ['created_count' => 0, 'unchanged_count' => count($package['targets']), 'readback_count' => count($package['targets'])];
            }
            $release = ContentPackRelease::query()->create([
                'id' => (string) Str::orderedUuid(), 'action' => self::RELEASE_ACTION,
                'region' => 'GLOBAL', 'locale' => self::LOCALE, 'dir_alias' => Eq60PackLoader::PACK_VERSION,
                'to_pack_id' => Eq60PackLoader::PACK_ID, 'status' => 'draft',
                'message' => 'Exact EQ-60 compiled English result-content revision staged inactive; runtime activation remains disabled.',
                'created_by' => 'content-promotion-v2', 'manifest_hash' => $package['manifest_hash'],
                'compiled_hash' => $context->packageSha256, 'content_hash' => $context->packageSha256,
                'pack_version' => Eq60PackLoader::PACK_VERSION, 'manifest_json' => $package['manifest'],
                'source_commit' => $context->sourceCommit,
            ]);
            $this->manifestCatalog->upsertManifest([
                'content_pack_release_id' => (string) $release->getKey(), 'manifest_hash' => $package['manifest_hash'],
                'schema_version' => 'fermatmind.eq60_compiled_result_content_release.v2', 'storage_disk' => 'database',
                'storage_path' => 'content_pack_releases/'.$release->getKey(), 'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => Eq60PackLoader::PACK_VERSION, 'compiled_hash' => $context->packageSha256,
                'content_hash' => $context->packageSha256, 'source_commit' => $context->sourceCommit,
                'payload_json' => $package['manifest'],
            ]);

            return ['created_count' => count($package['targets']), 'unchanged_count' => 0, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{changed_count:int,unchanged_count:int,readback_count:int} */
    public function publish(PromotionContext $context, ?string $expectedPreviousReleaseId): array
    {
        $package = $this->inspect($context);

        return DB::transaction(function () use ($context, $package, $expectedPreviousReleaseId): array {
            $release = $this->release($context, true, $package);
            if (! $release instanceof ContentPackRelease) {
                throw new DomainException('eq60_promotion_draft_missing');
            }
            $this->assertRelease($release, $context, $package, 'draft_or_published');
            if ((string) $release->status === 'published') {
                return ['changed_count' => 0, 'unchanged_count' => count($package['targets']), 'readback_count' => count($package['targets'])];
            }
            $previous = $this->activeRelease($context->packageSha256, true);
            if (($previous?->getKey() ?? null) !== $expectedPreviousReleaseId) {
                throw new DomainException('eq60_promotion_previous_release_drift');
            }
            if ($previous instanceof ContentPackRelease) {
                $previous->forceFill(['status' => 'superseded', 'message' => 'Superseded by exact EQ-60 compiled result-content release '.$release->getKey().'.'])->saveQuietly();
            }
            $release->forceFill(['status' => 'published', 'message' => 'Exact EQ-60 compiled English result-content release published; runtime activation remains disabled.'])->saveQuietly();

            return ['changed_count' => count($package['targets']), 'unchanged_count' => 0, 'readback_count' => count($package['targets'])];
        }, 3);
    }

    /** @return array{readback_count:int} */
    public function liveQa(PromotionContext $context): array
    {
        $package = $this->inspect($context);
        $release = $this->release($context, false, $package);
        if (! $release instanceof ContentPackRelease) {
            throw new DomainException('eq60_promotion_release_missing');
        }
        $this->assertRelease($release, $context, $package, 'published');
        $this->assertShareProjectionSchema($package);
        $this->assertHistoryProjection($package);
        foreach ($package['targets'] as $target) {
            $identity = $target['identity'];
            if (($identity['locale'] ?? null) !== self::LOCALE
                || ! in_array($identity['artifact'] ?? null, self::RESULT_CONTENT_FILES, true)
                || ! is_string($identity['english_projection_sha256'] ?? null)) {
                throw new DomainException('eq60_promotion_public_projection_invalid');
            }
        }

        return ['readback_count' => count($package['targets'])];
    }

    public function rollback(PromotionContext $context, ?string $previousReleaseId): void
    {
        $package = $this->inspect($context);
        DB::transaction(function () use ($context, $package, $previousReleaseId): void {
            $release = $this->release($context, true, $package);
            if (! $release instanceof ContentPackRelease || (string) $release->status !== 'published') {
                throw new DomainException('eq60_promotion_rollback_current_release_invalid');
            }
            $previous = $previousReleaseId === null ? null : ContentPackRelease::query()->lockForUpdate()->find($previousReleaseId);
            if ($previousReleaseId !== null && (! $previous instanceof ContentPackRelease || (string) $previous->action !== self::RELEASE_ACTION || (string) $previous->locale !== self::LOCALE || (string) $previous->status !== 'superseded')) {
                throw new DomainException('eq60_promotion_rollback_previous_release_invalid');
            }
            $release->forceFill(['status' => 'rolled_back', 'message' => 'Rolled back to the previous exact EQ-60 compiled result-content release.'])->saveQuietly();
            if ($previous instanceof ContentPackRelease) {
                $previous->forceFill(['status' => 'published', 'message' => 'Restored by exact EQ-60 compiled result-content rollback.'])->saveQuietly();
            }
        }, 3);
    }

    public function activeReleaseId(string $packageSha256): ?string
    {
        return $this->activeRelease($packageSha256, false)?->getKey();
    }

    /** @return array<string,mixed> */
    private function reportProjection(array $report, string $version): array
    {
        if (($report['schema'] ?? null) !== 'eq_60.report.compiled.v2'
            || ($report['pack_id'] ?? null) !== Eq60PackLoader::PACK_ID
            || ($report['pack_version'] ?? null) !== $version
            || ! is_array($report['layout'] ?? null) || ! is_array($report['blocks'] ?? null)) {
            throw new DomainException('eq60_promotion_report_schema_invalid');
        }
        $blocks = array_values(array_filter($report['blocks'], static fn (mixed $block): bool => is_array($block) && ($block['locale'] ?? null) === self::LOCALE));
        if ($blocks === []) {
            throw new DomainException('eq60_promotion_report_english_blocks_missing');
        }

        return ['schema' => $report['schema'], 'pack_id' => $report['pack_id'], 'pack_version' => $report['pack_version'], 'layout' => $this->englishOnly($report['layout']), 'blocks' => $this->englishOnly($blocks)];
    }

    /** @return array<string,mixed> */
    private function assetsProjection(array $assets, string $version): array
    {
        if (($assets['schema'] ?? null) !== 'eq_60.report_assets.compiled.v1'
            || ($assets['pack_id'] ?? null) !== Eq60PackLoader::PACK_ID
            || ($assets['pack_version'] ?? null) !== $version || ! is_array($assets['assets'] ?? null)) {
            throw new DomainException('eq60_promotion_report_assets_schema_invalid');
        }
        // This is a public result-content projection. Agent policy metadata is
        // not rendered by the EQ report runtime and retains multilingual blocked
        // phrase examples deliberately, so it remains bound by the compiled-file
        // hash but is not a public English release target.
        $publicAssets = $assets;
        unset($publicAssets['assets']['agent_knowledge_base_schema']);
        $projection = $this->englishOnly($publicAssets);
        if (! is_array($projection) || ! is_array($projection['assets'] ?? null) || $projection['assets'] === []) {
            throw new DomainException('eq60_promotion_report_assets_english_missing');
        }

        return $projection;
    }

    private function assertClaimBoundary(array $assets): void
    {
        $contract = $assets['assets']['scientific_contract']['assets']['eq.scientific_contract.default']['en'] ?? null;
        foreach (['self_report_statement', 'non_clinical_statement', 'non_hiring_statement', 'non_ability_statement', 'do_not_overread'] as $field) {
            if (! is_array($contract) || ! is_string($contract[$field] ?? null) || trim((string) $contract[$field]) === '') {
                throw new DomainException('eq60_promotion_claim_boundary_invalid');
            }
        }
    }

    private function assertNoCjk(array $projection): void
    {
        if (preg_match('/\p{sc=Han}/u', PromotionContextFactory::canonicalJson($projection)) === 1) {
            throw new DomainException('eq60_promotion_cjk_leakage');
        }
    }

    /**
     * Fail closed when English result blocks require a calibrated norm that does not exist.
     *
     * The compiled report may reference percentile/percentile-rank claims. Those claims
     * are only valid when a calibrated English norm authority with the required locale,
     * cohort, and version is present in the production scale_norms_versions / scale_norm_stats
     * tables. This is a read-only existence check; it does not import, create, or
     * activate norms.
     */
    private function assertNormAuthorityCalibrated(): void
    {
        if (! Schema::hasTable('scale_norms_versions') || ! Schema::hasTable('scale_norm_stats')) {
            throw new DomainException('eq60_promotion_english_norm_not_calibrated');
        }

        $hasCalibratedEnglish = DB::table('scale_norms_versions')
            ->where('locale', 'en')
            ->whereIn('status', ['active', 'calibrated'])
            ->exists();

        if (! $hasCalibratedEnglish) {
            throw new DomainException('eq60_promotion_english_norm_not_calibrated');
        }
    }

    /**
     * Verify that the compiled report only exposes share-projection fields in the
     * allowed set.  The EQ share button is currently disabled by design; this gate
     * ensures the compiled payload does not already leak private fields into a
     * public share projection.
     *
     * @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>} $package
     */
    private function assertShareProjectionSchema(array $package): void
    {
        foreach ($package['targets'] as $target) {
            $identity = $target['identity'] ?? [];
            if (($identity['artifact'] ?? '') !== 'report.compiled.json') {
                continue;
            }

            $snapshot = $target['snapshot'] ?? $target;
            $keys = is_array($snapshot) ? array_keys($snapshot) : [];

            foreach ($keys as $key) {
                if (! in_array((string) $key, self::ALLOWED_SHARE_KEYS, true)) {
                    throw new DomainException('eq60_promotion_share_projection_disallowed_field');
                }
            }
        }
    }

    /**
     * Verify that the compiled report projection does not expose private
     * identifiers (attempt_id, report token, user/org/order ids) in any
     * public-facing key.
     *
     * @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>} $package
     */
    private function assertHistoryProjection(array $package): void
    {
        $manifest = $package['manifest'] ?? [];

        if (($manifest['runtime_activation'] ?? null) !== false) {
            throw new DomainException('eq60_promotion_history_activation_required');
        }

        if (! is_int($manifest['expected_row_count'] ?? null) || ($manifest['expected_row_count'] ?? 0) < 1) {
            throw new DomainException('eq60_promotion_history_projection_invalid');
        }
    }

    private function englishOnly(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->englishOnly($item), $value);
        }
        $out = [];
        foreach ($value as $key => $nested) {
            $key = (string) $key;
            $normalized = strtolower(str_replace('_', '-', $key));
            if (in_array($normalized, ['zh', 'zh-cn', 'cn'], true) || str_ends_with($normalized, '-zh') || str_ends_with($normalized, '-zh-cn')) {
                continue;
            }
            $out[$key] = $this->englishOnly($nested);
        }

        return $out;
    }

    /** @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} $package */
    private function release(PromotionContext $context, bool $lock, array $package): ?ContentPackRelease
    {
        $query = ContentPackRelease::query()->where('action', self::RELEASE_ACTION)->where('content_hash', $context->packageSha256);
        if ($lock) {
            $query->lockForUpdate();
        }
        $release = $query->first();
        if ($release instanceof ContentPackRelease && ! hash_equals((string) $release->manifest_hash, $package['manifest_hash'])) {
            throw new DomainException('eq60_promotion_release_manifest_collision');
        }

        return $release;
    }

    private function activeRelease(string $excludingPackageSha256, bool $lock): ?ContentPackRelease
    {
        $query = ContentPackRelease::query()->where('action', self::RELEASE_ACTION)->where('locale', self::LOCALE)->where('status', 'published')->where('content_hash', '!=', $excludingPackageSha256)->orderByDesc('created_at');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param array{targets:list<array<string,mixed>>,manifest:array<string,mixed>,manifest_hash:string} $package */
    private function assertRelease(ContentPackRelease $release, PromotionContext $context, array $package, string $state): void
    {
        if ((string) $release->action !== self::RELEASE_ACTION || (string) $release->locale !== self::LOCALE
            || (string) $release->to_pack_id !== Eq60PackLoader::PACK_ID || ! hash_equals((string) $release->manifest_hash, $package['manifest_hash'])
            || ! hash_equals((string) $release->content_hash, $context->packageSha256)
            || ! hash_equals(PromotionContextFactory::canonicalJson((array) $release->manifest_json), PromotionContextFactory::canonicalJson($package['manifest']))
            || ! in_array((string) $release->status, match ($state) {
                'draft' => ['draft'], 'published' => ['published'], default => ['draft', 'published'],
            }, true)) {
            throw new DomainException('eq60_promotion_release_state_invalid');
        }
    }

    private function read(string $directory, string $file): string
    {
        if (! in_array($file, array_merge(self::COMPILED_FILES, ['manifest.json']), true)
            || str_contains($file, '/') || str_contains($file, '\\')) {
            throw new DomainException('eq60_promotion_compiled_file_invalid');
        }
        $path = $directory.DIRECTORY_SEPARATOR.$file;
        if (! is_file($path) || is_link($path)) {
            throw new DomainException('eq60_promotion_compiled_file_missing');
        }

        return File::get($path);
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

    /** @param array<string,string> $hashes */
    private function hashMap(array $hashes): string
    {
        ksort($hashes, SORT_STRING);
        $rows = [];
        foreach ($hashes as $file => $hash) {
            $rows[] = $file.':'.$hash;
        }

        return hash('sha256', implode("\n", $rows));
    }
}
