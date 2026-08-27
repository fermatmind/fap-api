<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;

final class CareerCurrentAuthorityReleaseIntent
{
    public const CONTRACT_VERSION = 'career.current_authority_release_intent.v1';

    public const RELATIVE_PATH = 'content_assets/career/career_current_authority_release.v1.json';

    public const SOURCE_MERGE_SHA = '2c956887ad9460849fd29cbfacb145e1397993cd';

    public const MANIFEST_SHA256 = '129fa491a531399625ea74fe384c38711f428387c874aa191de699c57aecdfdf';

    public const AGGREGATE_SHA256 = 'dff327cece2f65b780865a1aeccb168a3ddca7f82e4d073a6609c67b74147799';

    public const VERSIONLESS_PROJECTION_SHA256 = 'd04253c3dc3685469bb431211bdeb3e898242a468c143ca8da0fda7798354d80';

    private const EXPECTED_KEYS = [
        'aggregate_sha256',
        'contract_version',
        'discoverability',
        'locale_page_count',
        'locales',
        'manifest_sha256',
        'manual_hold_slugs',
        'module_count',
        'search_submission',
        'shards_per_module',
        'slug_count',
        'source_merge_sha',
        'versionless_projection_sha256',
    ];

    public function __construct(private readonly CareerCurrentAuthorityPackageLoader $loader) {}

    /** @return array<string, mixed> */
    public function verify(string $backendRoot, ?string $intentPath = null): array
    {
        $intentPath ??= rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH;
        if (! is_file($intentPath) || is_link($intentPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_MISSING');
        }

        try {
            $intent = json_decode((string) file_get_contents($intentPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_INVALID');
        }
        if (! is_array($intent)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_INVALID');
        }
        $keys = array_keys($intent);
        sort($keys, SORT_STRING);
        if ($keys !== self::EXPECTED_KEYS
            || ($intent['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($intent['source_merge_sha'] ?? null) !== self::SOURCE_MERGE_SHA
            || ($intent['manifest_sha256'] ?? null) !== self::MANIFEST_SHA256
            || ($intent['aggregate_sha256'] ?? null) !== self::AGGREGATE_SHA256
            || ($intent['versionless_projection_sha256'] ?? null) !== self::VERSIONLESS_PROJECTION_SHA256
            || ($intent['locales'] ?? null) !== ['zh-CN', 'en']
            || ($intent['slug_count'] ?? null) !== 1046
            || ($intent['locale_page_count'] ?? null) !== 2092
            || ($intent['module_count'] ?? null) !== 10
            || ($intent['shards_per_module'] ?? null) !== 64
            || ($intent['manual_hold_slugs'] ?? null) !== ['software-developers']
            || ($intent['discoverability'] ?? null) !== false
            || ($intent['search_submission'] ?? null) !== false) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_INVALID');
        }

        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        $authority = $this->loader->load($backendRoot);
        $manifest = $authority['manifest'];
        $summary = $authority['summary'];
        $modules = $manifest['modules'] ?? null;
        $shards = $manifest['shards'] ?? null;
        if (! hash_equals((string) $intent['manifest_sha256'], (string) hash_file('sha256', $manifestPath))
            || ! hash_equals((string) $intent['aggregate_sha256'], (string) ($summary['sharded_aggregate_sha256'] ?? ''))
            || ! hash_equals((string) $intent['versionless_projection_sha256'], (string) ($summary['versionless_projection_sha256'] ?? ''))
            || ($summary['career_count'] ?? null) !== $intent['slug_count']
            || ($summary['locale_page_count'] ?? null) !== $intent['locale_page_count']
            || ! is_array($modules)
            || count($modules) !== $intent['module_count']
            || ! is_array($shards)
            || count($shards) !== $intent['module_count'] * $intent['shards_per_module']) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_PACKAGE_MISMATCH');
        }
        foreach ($modules as $module) {
            $moduleShards = array_values(array_filter(
                $shards,
                static fn (mixed $shard): bool => is_array($shard) && ($shard['module'] ?? null) === $module,
            ));
            if (count($moduleShards) !== $intent['shards_per_module']
                || array_column($moduleShards, 'shard_index') !== range(0, $intent['shards_per_module'] - 1)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_PACKAGE_MISMATCH');
            }
        }

        return [
            'intent' => $intent,
            'package' => [
                'slug_count' => $summary['career_count'],
                'locale_page_count' => $summary['locale_page_count'],
                'module_count' => count($modules),
                'shard_count' => count($shards),
            ],
            'operation_key' => $this->operationKey($intent),
        ];
    }

    /** @param array<string, mixed> $intent */
    public function operationKey(array $intent): string
    {
        return hash(
            'sha256',
            'career-current-authority|'.($intent['source_merge_sha'] ?? '').'|'.($intent['aggregate_sha256'] ?? '').'|'.($intent['versionless_projection_sha256'] ?? ''),
        );
    }
}
