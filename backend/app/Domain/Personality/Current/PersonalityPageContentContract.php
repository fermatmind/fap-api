<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

final class PersonalityPageContentContract
{
    public const CONTRACT_VERSION = 'personality.page.content.v1';

    public const LOCALES = ['en', 'zh-CN'];

    private const PAGE_KINDS = [
        'mbti' => ['hub', 'profile', 'variant', 'comparison_at', 'comparison_cross'],
        'big_five' => ['hub', 'domain', 'polarity', 'facet_hub', 'facet_detail'],
        'enneagram' => ['hub', 'center', 'core_type', 'wing', 'instinctual_subtype'],
    ];

    private const PAYLOAD_CONTRACTS = [
        'hub' => ['personality.mbti.hub.current.v1', 'personality_public_asset.v2'],
        'profile' => ['mbti.public.detail.v1'],
        'variant' => ['mbti.public.detail.v1'],
        'comparison_at' => ['mbti.at_comparison.v1'],
        'comparison_cross' => ['mbti.cross_type_comparison.public.v1'],
        'domain' => ['personality_public_asset.v2'],
        'polarity' => ['personality_public_asset.v2'],
        'facet_hub' => ['personality_public_asset.v2'],
        'facet_detail' => ['personality_public_asset.v2'],
        'center' => ['personality_public_asset.v2'],
        'core_type' => ['personality_public_asset.v2'],
        'wing' => ['personality_public_asset.v2'],
        'instinctual_subtype' => ['personality_public_asset.v2'],
    ];

    /** @param array<string,mixed> $page */
    public static function assert(array $page): void
    {
        self::exactKeys($page, [
            'contract_version', 'locale', 'identity', 'content_state', 'payload_contract', 'payload',
            'source_content_sha256',
        ]);
        $locale = $page['locale'] ?? null;
        if (($page['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ! in_array($locale, self::LOCALES, true)
            || ! in_array($page['content_state'] ?? null, ['baseline', 'enhanced'], true)
            || ! self::hash($page['source_content_sha256'] ?? null)
            || ! is_array($page['payload'] ?? null)
            || array_is_list($page['payload'])) {
            self::fail('PERSONALITY_CURRENT_PAGE_INVALID');
        }

        $identity = $page['identity'] ?? null;
        if (! is_array($identity) || array_is_list($identity)) {
            self::fail('PERSONALITY_CURRENT_IDENTITY_INVALID');
        }
        self::exactKeys($identity, [
            'framework', 'page_kind', 'entity_type', 'entity_key', 'slug', 'canonical_path',
        ]);
        foreach (['framework', 'page_kind', 'entity_type', 'entity_key', 'slug', 'canonical_path'] as $field) {
            if (! self::nonEmpty($identity[$field] ?? null)) {
                self::fail('PERSONALITY_CURRENT_IDENTITY_INVALID');
            }
        }
        $framework = (string) $identity['framework'];
        $pageKind = (string) $identity['page_kind'];
        if (! in_array($pageKind, self::PAGE_KINDS[$framework] ?? [], true)
            || ! in_array($page['payload_contract'] ?? null, self::PAYLOAD_CONTRACTS[$pageKind] ?? [], true)) {
            self::fail('PERSONALITY_CURRENT_PAGE_KIND_INVALID');
        }
        if ($framework === 'mbti' && $pageKind === 'hub'
            && ($page['payload_contract'] ?? null) !== 'personality.mbti.hub.current.v1') {
            self::fail('PERSONALITY_CURRENT_PAYLOAD_CONTRACT_INVALID');
        }
        if ($framework !== 'mbti' && ($page['payload_contract'] ?? null) !== 'personality_public_asset.v2') {
            self::fail('PERSONALITY_CURRENT_PAYLOAD_CONTRACT_INVALID');
        }

        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $canonicalPath = (string) $identity['canonical_path'];
        if (! str_starts_with($canonicalPath, '/'.$segment.'/personality')
            || str_contains($canonicalPath, '..')
            || str_contains($canonicalPath, '//')) {
            self::fail('PERSONALITY_CURRENT_CANONICAL_PATH_INVALID');
        }
        foreach (['entity_key', 'slug'] as $field) {
            if (preg_match('/\A[a-z0-9][a-z0-9\-\/]*\z/', (string) $identity[$field]) !== 1) {
                self::fail('PERSONALITY_CURRENT_IDENTITY_INVALID');
            }
        }
        if (! hash_equals((string) $page['source_content_sha256'], PersonalityCurrentAuthorityPackage::hashValue($page['payload']))) {
            self::fail('PERSONALITY_CURRENT_SOURCE_HASH_MISMATCH');
        }
    }

    /** @param array<string,mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::fail('PERSONALITY_CURRENT_KEYS_INVALID');
        }
    }

    private static function nonEmpty(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1;
    }

    private static function fail(string $code): never
    {
        throw new PersonalityCurrentAuthorityFailure($code);
    }
}
