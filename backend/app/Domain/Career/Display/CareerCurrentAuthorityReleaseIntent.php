<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;

final class CareerCurrentAuthorityReleaseIntent
{
    public const CONTRACT_VERSION = 'career.current_authority_release_intent.v1';

    public const RELATIVE_PATH = 'content_assets/career/career_current_authority_release.v1.json';

    private const EXPECTED_KEYS = [
        'aggregate_sha256',
        'contract_version',
        'discoverability',
        'file_count',
        'locale_page_count',
        'locales',
        'manifest_sha256',
        'manual_hold_slugs',
        'search_submission',
        'slug_count',
        'source_merge_sha',
        'source_registry_sha256',
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
            || preg_match('/\A[a-f0-9]{40}\z/', (string) ($intent['source_merge_sha'] ?? '')) !== 1
            || ! $this->hash($intent['manifest_sha256'] ?? null)
            || ! $this->hash($intent['aggregate_sha256'] ?? null)
            || ! $this->hash($intent['versionless_projection_sha256'] ?? null)
            || ! $this->hash($intent['source_registry_sha256'] ?? null)
            || ($intent['locales'] ?? null) !== CareerCurrentAuthorityPackage::LOCALES
            || ($intent['slug_count'] ?? null) !== 1046
            || ($intent['locale_page_count'] ?? null) !== 2092
            || ($intent['file_count'] ?? null) !== 2092
            || ($intent['manual_hold_slugs'] ?? null) !== ['software-developers']
            || ($intent['discoverability'] ?? null) !== false
            || ($intent['search_submission'] ?? null) !== false) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_INVALID');
        }

        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        $authority = $this->loader->indexForPublish($backendRoot);
        $manifest = $authority['manifest'];
        $summary = $authority['summary'];
        if (! hash_equals((string) $intent['manifest_sha256'], (string) hash_file('sha256', $manifestPath))
            || ! hash_equals((string) $intent['aggregate_sha256'], (string) ($summary['aggregate_sha256'] ?? ''))
            || ! hash_equals((string) $intent['versionless_projection_sha256'], (string) ($summary['versionless_projection_sha256'] ?? ''))
            || ! hash_equals((string) $intent['source_registry_sha256'], (string) ($manifest['source_registry_sha256'] ?? ''))
            || ($summary['career_count'] ?? null) !== $intent['slug_count']
            || ($summary['locale_page_count'] ?? null) !== $intent['locale_page_count']
            || count((array) ($manifest['files'] ?? [])) !== $intent['file_count']) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_RELEASE_INTENT_PACKAGE_MISMATCH');
        }

        return [
            'intent' => $intent,
            'package' => [
                'slug_count' => $summary['career_count'],
                'locale_page_count' => $summary['locale_page_count'],
                'file_count' => count($manifest['files']),
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

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }
}
