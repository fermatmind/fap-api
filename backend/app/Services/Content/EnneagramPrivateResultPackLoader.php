<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EnneagramPrivateResultPackLoader
{
    public function __construct(
        private readonly ContentPackV2Resolver $resolver,
        private readonly EnneagramPrivateResultCompileService $compiler,
    ) {}

    /** @return array{assets:array<string,mixed>,authority:array<string,mixed>,manifest:array<string,mixed>,payload:array<string,mixed>} */
    public function load(string $locale = 'zh-CN'): array
    {
        $normalizedLocale = str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
        $loaded = $this->loadCompiled();
        $payload = $loaded['payload'];
        $assets = data_get($payload, 'locale_assets.'.$normalizedLocale);
        if (! is_array($assets) || count($assets) !== count(EnneagramPrivateResultCompileService::SOURCE_CONTRACT)) {
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_LOCALE_UNAVAILABLE');
        }

        $manifest = $loaded['manifest'];
        $manifest['locale'] = $normalizedLocale;
        $manifest['locales'] = $normalizedLocale === 'en' ? ['en'] : ['zh-CN', 'en'];
        $manifest['source_files'] = (array) data_get($manifest, 'locale_source_files.'.$normalizedLocale, []);

        return [
            'assets' => $assets,
            'manifest' => $manifest,
            'payload' => $payload,
            'authority' => [
                'schema_version' => 'fap.enneagram.private_result_authority.v1',
                'authority_id' => EnneagramPrivateResultCompileService::AUTHORITY_ID,
                'mode' => 'canonical',
                'locale' => $normalizedLocale,
                'release_id' => $loaded['release_id'],
                'source_hash' => (string) $payload['source_hash'],
                'compiled_hash' => (string) $payload['compiled_hash'],
                'compiled_schema' => EnneagramPrivateResultCompileService::SCHEMA,
                'compiler_schema' => EnneagramPrivateResultCompileService::COMPILER_SCHEMA,
                'compiler_version' => EnneagramPrivateResultCompileService::COMPILER_VERSION,
                'runtime_contract' => (string) $payload['runtime_contract'],
            ],
        ];
    }

    /** @return array{payload:array<string,mixed>,manifest:array<string,mixed>,release_id:?string} */
    private function loadCompiled(): array
    {
        $activePath = $this->resolver->resolveActiveCompiledPath(
            EnneagramPrivateResultCompileService::PACK_ID,
            EnneagramPrivateResultCompileService::PACK_VERSION,
        );
        if (is_string($activePath) && $activePath !== '') {
            $artifactPath = rtrim($activePath, '/').'/'.EnneagramPrivateResultCompileService::ARTIFACT_FILENAME;
            $manifestPath = rtrim($activePath, '/').'/manifest.json';
            if (! is_file($artifactPath) || ! is_file($manifestPath)) {
                throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_INCOMPLETE');
            }
            $artifactBytes = (string) file_get_contents($artifactPath);
            $payload = json_decode($artifactBytes, true, 512, JSON_THROW_ON_ERROR);
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ! is_array($manifest)) {
                throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_INVALID');
            }
            $release = $this->activeRelease();
            if ($release === null) {
                throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING');
            }
            $this->assertValid($payload, $manifest, $artifactBytes, $release);

            return ['payload' => $payload, 'manifest' => $manifest, 'release_id' => (string) $release->id];
        }

        if (app()->environment('production')) {
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_MISSING');
        }

        $compiled = $this->compiler->compile();
        $this->assertValid($compiled['payload'], $compiled['manifest'], $compiled['bytes'], null);

        return ['payload' => $compiled['payload'], 'manifest' => $compiled['manifest'], 'release_id' => null];
    }

    private function activeRelease(): ?object
    {
        $releaseId = trim((string) DB::table('content_pack_activations')
            ->where('pack_id', EnneagramPrivateResultCompileService::PACK_ID)
            ->where('pack_version', EnneagramPrivateResultCompileService::PACK_VERSION)
            ->value('release_id'));

        return $releaseId === '' ? null : DB::table('content_pack_releases')->where('id', $releaseId)->first();
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $manifest */
    private function assertValid(array $payload, array $manifest, string $artifactBytes, ?object $release): void
    {
        $sourceHash = strtolower(trim((string) ($payload['source_hash'] ?? '')));
        $compiledHash = strtolower(trim((string) ($payload['compiled_hash'] ?? '')));
        $unsigned = $payload;
        unset($unsigned['compiled_hash']);
        if (($payload['schema'] ?? null) !== EnneagramPrivateResultCompileService::SCHEMA
            || ($payload['authority_id'] ?? null) !== EnneagramPrivateResultCompileService::AUTHORITY_ID
            || ($payload['scale_code'] ?? null) !== 'ENNEAGRAM'
            || ($payload['version'] ?? null) !== EnneagramPrivateResultCompileService::PACK_VERSION
            || ($payload['runtime_contract'] ?? null) !== 'enneagram.report.v2'
            || data_get($payload, 'compiler.schema') !== EnneagramPrivateResultCompileService::COMPILER_SCHEMA
            || data_get($payload, 'compiler.version') !== EnneagramPrivateResultCompileService::COMPILER_VERSION
            || preg_match('/\A[0-9a-f]{64}\z/', $sourceHash) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $compiledHash) !== 1
            || ! hash_equals($compiledHash, hash('sha256', $this->canonicalJson($unsigned)))
            || data_get($payload, 'coverage.locales') !== ['zh-CN', 'en']
            || (int) data_get($payload, 'coverage.pair_count', 0) !== 36
            || data_get($payload, 'coverage.forms') !== ['e105', 'fc144']
            || data_get($payload, 'form_projections.e105.source_hash') !== $sourceHash
            || data_get($payload, 'form_projections.fc144.source_hash') !== $sourceHash) {
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
        }

        $hashInput = '';
        foreach (['zh-CN', 'en'] as $locale) {
            foreach (EnneagramPrivateResultCompileService::SOURCE_CONTRACT as $filename => $contract) {
                $localeAssets = is_array($payload['locale_assets'][$locale] ?? null) ? $payload['locale_assets'][$locale] : [];
                $asset = $localeAssets[$filename] ?? null;
                $manifestRow = $this->manifestSourceRow($manifest, $locale, ($locale === 'en' ? 'en/' : '').$filename);
                if (! is_array($asset)
                    || ($asset['schema_version'] ?? null) !== $contract['schema']
                    || ($asset['registry_key'] ?? null) !== $contract['registry_key']
                    || ($asset['locale'] ?? null) !== $locale) {
                    throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_ARTIFACT_CONTRACT_INVALID');
                }
                $digest = hash('sha256', $this->canonicalJson($asset));
                if (($manifestRow['sha256'] ?? null) !== $digest
                    || ($manifestRow['schema'] ?? null) !== $contract['schema']
                    || ($manifestRow['role'] ?? null) !== $contract['role']
                    || ($manifestRow['consumer_surfaces'] ?? null) !== $contract['surfaces']) {
                    throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_MANIFEST_CONTRACT_INVALID');
                }
                $hashInput .= $locale.'/'.$filename."\0".$digest."\n";
            }
        }

        if (! hash_equals($sourceHash, hash('sha256', $hashInput))
            || ($manifest['schema_version'] ?? null) !== EnneagramPrivateResultCompileService::MANIFEST_SCHEMA
            || ($manifest['authority_id'] ?? null) !== EnneagramPrivateResultCompileService::AUTHORITY_ID
            || ! hash_equals($sourceHash, strtolower(trim((string) ($manifest['source_hash'] ?? ''))))
            || ! hash_equals($compiledHash, strtolower(trim((string) ($manifest['compiled_hash'] ?? ''))))
            || ! hash_equals(hash('sha256', $artifactBytes), strtolower(trim((string) data_get($manifest, 'artifacts.0.sha256', ''))))) {
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_MANIFEST_HASH_MISMATCH');
        }

        if ($release !== null
            && (strtoupper(trim((string) ($release->to_pack_id ?? ''))) !== EnneagramPrivateResultCompileService::PACK_ID
                || trim((string) ($release->pack_version ?? $release->dir_alias ?? '')) !== EnneagramPrivateResultCompileService::PACK_VERSION
                || trim((string) ($release->status ?? '')) !== 'success'
                || ! hash_equals($compiledHash, strtolower(trim((string) ($release->compiled_hash ?? ''))))
                || ! hash_equals($sourceHash, strtolower(trim((string) ($release->content_hash ?? '')))))) {
            throw new RuntimeException('ENNEAGRAM_PRIVATE_RESULT_ACTIVE_RELEASE_BINDING_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function manifestSourceRow(array $manifest, string $locale, string $path): array
    {
        foreach ((array) data_get($manifest, 'locale_source_files.'.$locale, []) as $row) {
            if (is_array($row) && ($row['path'] ?? null) === $path) {
                return $row;
            }
        }

        return [];
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
