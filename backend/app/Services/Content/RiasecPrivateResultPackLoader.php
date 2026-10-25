<?php

declare(strict_types=1);

namespace App\Services\Content;

use RuntimeException;

final class RiasecPrivateResultPackLoader
{
    public function __construct(
        private readonly ContentPackV2Resolver $resolver,
        private readonly RiasecPrivateResultCompileService $compiler,
    ) {}

    /** @return array{assets:array<string,mixed>,authority:array<string,mixed>,payload:array<string,mixed>} */
    public function load(string $locale = 'zh-CN'): array
    {
        return self::localeProjection($this->loadCompiled(), $locale);
    }

    /** Activity coverage never establishes a complete English report. */
    public static function localeProjection(array $payload, string $locale): array
    {
        $locale = strtolower(trim($locale));
        $locale = $locale === '' || $locale === 'zh' || str_starts_with($locale, 'zh-') ? 'zh-CN'
            : ($locale === 'en' || str_starts_with($locale, 'en-') ? 'en' : $locale);
        $english = $locale === 'en';
        $selected = in_array($payload['compiler']['version'] ?? '', ['1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0'], true);
        $modules = $selected ? ['activity_explorer_v0_1', 'deep_content_slots_v1', 'lifecycle_copy_v1'] : ['activity_explorer_v0_1'];
        if ($locale !== 'zh-CN' && (! $english
            || ($payload['coverage']['locale_modules']['en'] ?? null) !== $modules
            || ! isset($payload['assets'][RiasecPrivateResultCompileService::ENGLISH_ACTIVITY_ASSET]))) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_LOCALE_UNAVAILABLE');
        }
        $assets = (array) $payload['assets'];
        if ($english) {
            $assets = array_filter($assets, static fn (string $path): bool => str_contains($path, '.en.'), ARRAY_FILTER_USE_KEY);
        } else {
            $assets = array_filter($assets, static fn (string $path): bool => ! str_contains($path, '.en.'), ARRAY_FILTER_USE_KEY);
        }

        return [
            'assets' => $assets,
            'payload' => $payload,
            'authority' => [
                'schema_version' => 'fap.riasec.private_result_authority.v1',
                'authority_id' => $english ? ($selected ? 'FERMATMIND_RIASEC_PRIVATE_RESULT_EN_SELECTED' : RiasecPrivateResultCompileService::ENGLISH_ACTIVITY_AUTHORITY_ID) : RiasecPrivateResultCompileService::AUTHORITY_ID,
                'mode' => 'canonical',
                ...($english ? ['coverage_scope' => 'selected_modules', 'modules' => $modules] : []),
                'locale' => $locale,
                'source_hash' => (string) $payload['source_hash'],
                'compiled_hash' => (string) $payload['compiled_hash'],
                'compiled_schema' => RiasecPrivateResultCompileService::SCHEMA,
                'compiler_schema' => (string) $payload['compiler']['schema'],
                'compiler_version' => (string) $payload['compiler']['version'],
                'runtime_contract' => $english ? ($selected ? 'riasec.public_projection.v2' : 'riasec.activity_explorer.v0.1') : (string) $payload['runtime_contract'],
            ],
        ];
    }

    public function assertPackage(array $manifest, array $payload, string $artifactBytes): void
    {
        $this->assertValid($payload);
        $this->assertManifestValid($manifest, $payload, $artifactBytes);
    }

    /** @return array<string,mixed> */
    private function loadCompiled(): array
    {
        $activePath = $this->resolver->resolveActiveCompiledPath(
            RiasecPrivateResultCompileService::PACK_ID,
            RiasecPrivateResultCompileService::PACK_VERSION,
        );
        if (is_string($activePath) && $activePath !== '') {
            $path = rtrim($activePath, '/').'/'.RiasecPrivateResultCompileService::ARTIFACT_FILENAME;
            $manifestPath = rtrim($activePath, '/').'/manifest.json';
            if (! is_file($path)) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_MISSING');
            }
            if (! is_file($manifestPath)) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_MANIFEST_MISSING');
            }
            $artifactBytes = (string) file_get_contents($path);
            $payload = json_decode($artifactBytes, true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_INVALID');
            }
            if (! is_array($manifest)) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_MANIFEST_INVALID');
            }
            $this->assertPackage($manifest, $payload, $artifactBytes);

            return $payload;
        }

        if (app()->environment('production')) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING');
        }

        $payload = $this->compiler->compile()['payload'];
        $this->assertValid($payload);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function assertValid(array $payload): void
    {
        $sourceHash = strtolower(trim((string) ($payload['source_hash'] ?? '')));
        $compiledHash = strtolower(trim((string) ($payload['compiled_hash'] ?? '')));
        $compiler = is_array($payload['compiler'] ?? null) ? $payload['compiler'] : [];
        $coverage = is_array($payload['coverage'] ?? null) ? $payload['coverage'] : [];
        $contract = RiasecPrivateResultCompileService::sourceContract((string) ($compiler['version'] ?? ''));
        if (($payload['schema'] ?? null) !== RiasecPrivateResultCompileService::SCHEMA
            || ($payload['authority_id'] ?? null) !== RiasecPrivateResultCompileService::AUTHORITY_ID
            || ($payload['scale_code'] ?? null) !== 'RIASEC'
            || ($payload['locale'] ?? null) !== 'zh-CN'
            || ($payload['version'] ?? null) !== RiasecPrivateResultCompileService::PACK_VERSION
            || ($payload['runtime_contract'] ?? null) !== 'riasec.report.v1'
            || ($compiler['schema'] ?? null) !== RiasecPrivateResultCompileService::COMPILER_SCHEMA
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $compiledHash) !== 1
            || ! is_array($payload['assets'] ?? null)
            || count((array) $payload['assets']) !== count($contract)
            || ($coverage['dimensions'] ?? null) !== ['R', 'I', 'A', 'S', 'E', 'C']
            || (int) ($coverage['pair_count'] ?? 0) !== 15
            || ($coverage['forms'] ?? null) !== ['riasec_60', 'riasec_140']) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }

        $assetNames = array_keys((array) $payload['assets']);
        $expectedAssetNames = array_keys($contract);
        sort($assetNames, SORT_STRING);
        sort($expectedAssetNames, SORT_STRING);
        if ($assetNames !== $expectedAssetNames) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }
        foreach ($contract as $filename => $sourceContract) {
            $asset = $payload['assets'][$filename];
            $rows = str_ends_with($filename, '.jsonl') ? $asset : [$asset];
            if (! is_array($asset) || $rows === []) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
            }
            foreach ($rows as $row) {
                if (! is_array($row)
                    || ($row['frontend_fallback_allowed'] ?? true) !== false
                    || (isset($row['schema_version']) && $row['schema_version'] !== $sourceContract['schema'])
                    || (! isset($row['schema_version']) && trim((string) ($row['asset_id'] ?? '')) === '')) {
                    throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
                }
            }
        }

        if (isset($contract[RiasecPrivateResultCompileService::ENGLISH_ACTIVITY_ASSET])) {
            $modules = in_array($compiler['version'] ?? '', ['1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0'], true) ? ['activity_explorer_v0_1', 'deep_content_slots_v1', 'lifecycle_copy_v1'] : ['activity_explorer_v0_1'];
            if (($coverage['locale_modules']['en'] ?? null) !== $modules) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
            }
            if (in_array($compiler['version'] ?? '', ['1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0'], true)) {
                $this->compiler->assertSelectedEnglishSources($payload['assets'], $compiler['version']);
                if ($this->canonicalJson($coverage['english_selected_sources'] ?? null) !== $this->canonicalJson(['dimensions' => ['R', 'I', 'A', 'S', 'E', 'C'], 'pairs' => RiasecPrivateResultCompileService::englishPairCoverage($compiler['version']), 'top3_groups' => RiasecPrivateResultCompileService::englishTop3Coverage($compiler['version']), 'ordered_codes' => RiasecPrivateResultCompileService::englishOrderedCodeCoverage($compiler['version']), 'generic_context_cards' => 4, 'dimension_state_rows' => isset($contract[RiasecPrivateResultCompileService::ENGLISH_DIMENSION_STATE_ASSET]) ? 126 : 0, 'faq_items' => 20, 'technical_sections' => 8, 'method_sections' => 8, ...(($compiler['version'] ?? '') === '1.6.0' ? ['generic_disagree_cards' => 3] : [])])) {
                    throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
                }
            } elseif (isset($coverage['english_selected_sources'])) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
            }
            $this->compiler->assertEnglishActivities($payload['assets'][RiasecPrivateResultCompileService::ENGLISH_ACTIVITY_ASSET], $payload['assets']['activity_task_examples_v1.zh-CN.jsonl']);
        } elseif (isset($coverage['locale_modules'])) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }

        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        if (! hash_equals($compiledHash, hash('sha256', $this->canonicalJson($unsigned)))) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_HASH_MISMATCH');
        }
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $payload */
    private function assertManifestValid(array $manifest, array $payload, string $artifactBytes): void
    {
        $contract = RiasecPrivateResultCompileService::sourceContract((string) ($payload['compiler']['version'] ?? ''));
        $sourceHashInput = '';
        $sourceRows = [];
        foreach ((array) ($manifest['source_files'] ?? []) as $row) {
            if (is_array($row) && is_string($row['path'] ?? null)) {
                $sourceRows[$row['path']] = $row;
            }
        }
        foreach ($contract as $filename => $sourceContract) {
            $row = $sourceRows[$filename] ?? [];
            $digest = hash('sha256', $this->canonicalJson($payload['assets'][$filename]));
            if (($row['required'] ?? false) !== true
                || ($row['role'] ?? null) !== $sourceContract['role']
                || ($row['schema'] ?? null) !== $sourceContract['schema']
                || ($row['surfaces'] ?? null) !== $sourceContract['surfaces']
                || ! hash_equals($digest, strtolower(trim((string) ($row['sha256'] ?? ''))))) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_MANIFEST_CONTRACT_INVALID');
            }
            $sourceHashInput .= $filename."\0".$digest."\n";
        }

        $sourceHash = hash('sha256', $sourceHashInput);
        $artifactHash = hash('sha256', $artifactBytes);
        if (($manifest['schema'] ?? null) !== RiasecPrivateResultCompileService::MANIFEST_SCHEMA
            || ($manifest['authority_id'] ?? null) !== RiasecPrivateResultCompileService::AUTHORITY_ID
            || ($manifest['version'] ?? null) !== RiasecPrivateResultCompileService::PACK_VERSION
            || ($manifest['compiler'] ?? null) !== ($payload['compiler'] ?? null)
            || ($manifest['coverage'] ?? null) !== ($payload['coverage'] ?? null)
            || count((array) ($manifest['source_files'] ?? [])) !== count($contract)
            || count($sourceRows) !== count($contract)
            || ! hash_equals($sourceHash, strtolower(trim((string) ($manifest['source_hash'] ?? ''))))
            || ! hash_equals($sourceHash, strtolower(trim((string) ($payload['source_hash'] ?? ''))))
            || ! hash_equals(strtolower(trim((string) ($payload['compiled_hash'] ?? ''))), strtolower(trim((string) ($manifest['compiled_hash'] ?? ''))))
            || ! hash_equals($artifactHash, strtolower(trim((string) data_get($manifest, 'artifacts.0.sha256', ''))))) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_MANIFEST_HASH_MISMATCH');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
