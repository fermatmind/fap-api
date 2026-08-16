<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\BigFive\ReportEngine\Registry\RegistryLoader;
use App\Services\BigFive\ReportEngine\Registry\UnsupportedRegistryLocale;
use RuntimeException;

final class BigFivePrivateResultPackLoader
{
    public function __construct(
        private readonly ContentPackV2Resolver $resolver,
        private readonly BigFivePrivateResultCompileService $compiler,
    ) {}

    /**
     * @return array{registry:array<string,mixed>,authority:array<string,string>}
     */
    public function load(string $locale): array
    {
        $compiled = $this->loadCompiled();
        $normalizedLocale = strtolower(trim($locale));
        if ($normalizedLocale !== ''
            && $normalizedLocale !== 'zh'
            && ! str_starts_with($normalizedLocale, 'zh-')
            && $normalizedLocale !== 'en'
            && ! str_starts_with($normalizedLocale, 'en-')) {
            throw new UnsupportedRegistryLocale("Big Five report engine registry unavailable for locale: {$locale}");
        }
        $registry = ($normalizedLocale === 'en' || str_starts_with($normalizedLocale, 'en-'))
            ? (new RegistryLoader)->load($locale)
            : RegistryLoader::fromCompiledAssets(
                (array) ($compiled['assets'] ?? []),
                (array) ($compiled['registry_manifest'] ?? []),
            );

        return [
            'registry' => $registry,
            'authority' => [
                'schema_version' => 'fap.big5.private_result_authority.v1',
                'mode' => 'canonical',
                'locale' => $locale !== '' ? $locale : 'zh-CN',
                'source_hash' => (string) $compiled['source_hash'],
                'compiled_hash' => (string) $compiled['compiled_hash'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function loadCompiled(): array
    {
        $activePath = $this->resolver->resolveActiveCompiledPath(
            BigFivePrivateResultCompileService::PACK_ID,
            BigFivePrivateResultCompileService::PACK_VERSION,
        );
        if (is_string($activePath) && $activePath !== '') {
            $path = rtrim($activePath, '/').'/'.BigFivePrivateResultCompileService::ARTIFACT_FILENAME;
            if (! is_file($path)) {
                throw new RuntimeException('BIG5_PRIVATE_RESULT_ACTIVE_ARTIFACT_MISSING');
            }
            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('BIG5_PRIVATE_RESULT_ACTIVE_ARTIFACT_INVALID');
            }
            $this->assertValid($payload);

            return $payload;
        }

        if (app()->environment('production')) {
            throw new RuntimeException('BIG5_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING');
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
        $registryManifest = is_array($payload['registry_manifest'] ?? null)
            ? $payload['registry_manifest']
            : [];
        if (($payload['schema'] ?? null) !== BigFivePrivateResultCompileService::SCHEMA
            || ($payload['scale_code'] ?? null) !== 'BIG5_OCEAN'
            || ($payload['locale'] ?? null) !== 'zh-CN'
            || ($payload['version'] ?? null) !== BigFivePrivateResultCompileService::PACK_VERSION
            || ($registryManifest['schema'] ?? null) !== 'fap.big5.report_registry_manifest.v1'
            || ! hash_equals($sourceHash, strtolower(trim((string) ($registryManifest['source_hash'] ?? ''))))
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $compiledHash) !== 1
            || ! is_array($payload['assets'] ?? null)) {
            throw new RuntimeException('BIG5_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }

        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        $canonical = $this->canonicalJson($unsigned);
        if (! hash_equals($compiledHash, hash('sha256', $canonical))) {
            throw new RuntimeException('BIG5_PRIVATE_RESULT_ACTIVE_ARTIFACT_HASH_MISMATCH');
        }
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
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
