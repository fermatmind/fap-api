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
        $normalizedLocale = strtolower(trim($locale));
        if ($normalizedLocale !== '' && $normalizedLocale !== 'zh' && ! str_starts_with($normalizedLocale, 'zh-')) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_LOCALE_UNAVAILABLE');
        }

        $payload = $this->loadCompiled();

        return [
            'assets' => (array) $payload['assets'],
            'payload' => $payload,
            'authority' => [
                'schema_version' => 'fap.riasec.private_result_authority.v1',
                'authority_id' => RiasecPrivateResultCompileService::AUTHORITY_ID,
                'mode' => 'canonical',
                'locale' => 'zh-CN',
                'source_hash' => (string) $payload['source_hash'],
                'compiled_hash' => (string) $payload['compiled_hash'],
                'compiled_schema' => RiasecPrivateResultCompileService::SCHEMA,
                'compiler_schema' => RiasecPrivateResultCompileService::COMPILER_SCHEMA,
                'compiler_version' => RiasecPrivateResultCompileService::COMPILER_VERSION,
                'runtime_contract' => (string) $payload['runtime_contract'],
            ],
        ];
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
            $this->assertValid($payload);
            $this->assertManifestValid($manifest, $payload, $artifactBytes);

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
        if (($payload['schema'] ?? null) !== RiasecPrivateResultCompileService::SCHEMA
            || ($payload['authority_id'] ?? null) !== RiasecPrivateResultCompileService::AUTHORITY_ID
            || ($payload['scale_code'] ?? null) !== 'RIASEC'
            || ($payload['locale'] ?? null) !== 'zh-CN'
            || ($payload['version'] ?? null) !== RiasecPrivateResultCompileService::PACK_VERSION
            || ($payload['runtime_contract'] ?? null) !== 'riasec.report.v1'
            || ($compiler['schema'] ?? null) !== RiasecPrivateResultCompileService::COMPILER_SCHEMA
            || ($compiler['version'] ?? null) !== RiasecPrivateResultCompileService::COMPILER_VERSION
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $compiledHash) !== 1
            || ! is_array($payload['assets'] ?? null)
            || count((array) $payload['assets']) !== count(RiasecPrivateResultCompileService::SOURCE_CONTRACT)
            || ($coverage['dimensions'] ?? null) !== ['R', 'I', 'A', 'S', 'E', 'C']
            || (int) ($coverage['pair_count'] ?? 0) !== 15
            || ($coverage['forms'] ?? null) !== ['riasec_60', 'riasec_140']) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }

        $assetNames = array_keys((array) $payload['assets']);
        $expectedAssetNames = array_keys(RiasecPrivateResultCompileService::SOURCE_CONTRACT);
        sort($assetNames, SORT_STRING);
        sort($expectedAssetNames, SORT_STRING);
        if ($assetNames !== $expectedAssetNames) {
            throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }
        foreach (RiasecPrivateResultCompileService::SOURCE_CONTRACT as $filename => $contract) {
            $asset = $payload['assets'][$filename];
            $rows = str_ends_with($filename, '.jsonl') ? $asset : [$asset];
            if (! is_array($asset) || $rows === []) {
                throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
            }
            foreach ($rows as $row) {
                if (! is_array($row)
                    || ($row['frontend_fallback_allowed'] ?? true) !== false
                    || (isset($row['schema_version']) && $row['schema_version'] !== $contract['schema'])
                    || (! isset($row['schema_version']) && trim((string) ($row['asset_id'] ?? '')) === '')) {
                    throw new RuntimeException('RIASEC_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
                }
            }
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
        $sourceHashInput = '';
        $sourceRows = [];
        foreach ((array) ($manifest['source_files'] ?? []) as $row) {
            if (is_array($row) && is_string($row['path'] ?? null)) {
                $sourceRows[$row['path']] = $row;
            }
        }
        foreach (RiasecPrivateResultCompileService::SOURCE_CONTRACT as $filename => $contract) {
            $row = $sourceRows[$filename] ?? [];
            $digest = hash('sha256', $this->canonicalJson($payload['assets'][$filename]));
            if (($row['required'] ?? false) !== true
                || ($row['role'] ?? null) !== $contract['role']
                || ($row['schema'] ?? null) !== $contract['schema']
                || ($row['surfaces'] ?? null) !== $contract['surfaces']
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
            || count($sourceRows) !== count(RiasecPrivateResultCompileService::SOURCE_CONTRACT)
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
