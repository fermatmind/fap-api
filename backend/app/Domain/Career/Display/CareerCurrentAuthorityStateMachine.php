<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;

final class CareerCurrentAuthorityStateMachine
{
    public const VERSION = 'career.current-authority.state-machine.v1';

    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerJobDetailCanonicalCacheReader $reader,
        private readonly CareerJobDetailReaderSafeReviewProjector $readerSafeProjector,
        private readonly CareerContentV3CanonicalReader $canonicalContent,
    ) {}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function assembleCandidate(array $row, string $locale): array
    {
        $slug = strtolower(trim((string) ($row['canonical_slug'] ?? '')));
        $payload = ['display_surface_v1' => $this->canonicalSurface($row, $locale)];
        $compact = $this->reader->withoutDerivedContentV3($payload, $slug, $locale);
        $stored = $this->reader->encode($compact);
        $canonical = $this->reader->read($stored, $slug, $locale);
        if ($canonical === null) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_PAYLOAD_MISSING');
        }
        $this->assertPayload($canonical, $row, $locale);

        return [
            'slug' => $slug,
            'locale' => $locale,
            'payload' => $canonical,
            'stored' => $stored,
        ];
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    public function assertPayload(?array $payload, array $row, string $locale): void
    {
        $code = $this->payloadMismatchCode($payload, $row, $locale);
        if ($code !== null) {
            throw new CareerCurrentAuthorityPublisherFailure($code);
        }
    }

    /** @param array<string,mixed> $entry */
    public function assertPreparedTransition(array $entry): void
    {
        if (($entry['status'] ?? null) !== 'ready'
            || ($entry['classification'] ?? null) !== 'ready_staged'
            || ! is_string($entry['version'] ?? null)
            || trim((string) $entry['version']) === '') {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_CANDIDATE_PREPARATION_FAILED');
        }
    }

    /** @param array<string,mixed> $activation */
    public function assertActivationTransition(array $activation, int $expectedCount): void
    {
        if (($activation['status'] ?? null) !== 'pass'
            || count((array) ($activation['entries'] ?? [])) !== $expectedCount
            || ($activation['failures'] ?? []) !== []) {
            throw new CareerCurrentAuthorityPublisherFailure('CURRENT_CACHE_POINTER_ACTIVATION_FAILED');
        }
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    public function payloadMatches(?array $payload, array $row, string $locale): bool
    {
        return $this->payloadMismatchCode($payload, $row, $locale) === null;
    }

    /** @param array<string,mixed>|null $payload @param array<string,mixed> $row */
    public function payloadMismatchCode(?array $payload, array $row, string $locale): ?string
    {
        if (is_array($payload) && $this->containsVersionDiscriminator($payload)) {
            return 'CURRENT_CACHE_VERSION_FIELD_FORBIDDEN';
        }
        $surface = is_array($payload) ? data_get($payload, 'display_surface_v1') : null;
        if (! is_array($surface)) {
            return 'CURRENT_CACHE_CONTENT_MISMATCH';
        }

        try {
            $expected = $this->readerSafeProjector->project($this->canonicalSurface($row, $locale));
            $actual = $this->readerSafeProjector->project($this->package->displayOwnedProjection($surface));
            if (hash_equals(
                CareerCurrentAuthorityPackage::hashValue($expected),
                CareerCurrentAuthorityPackage::hashValue($actual),
            )) {
                return null;
            }
            foreach (array_keys($expected) as $field) {
                if (! array_key_exists($field, $actual)
                    || ! hash_equals(
                        CareerCurrentAuthorityPackage::hashValue($expected[$field]),
                        CareerCurrentAuthorityPackage::hashValue($actual[$field]),
                    )) {
                    return match ($field) {
                        'surface_version' => 'CURRENT_CACHE_SURFACE_VERSION_MISMATCH',
                        'available_locales' => 'CURRENT_CACHE_AVAILABLE_LOCALES_MISMATCH',
                        'page' => $this->pageMismatchCode(
                            (array) $expected[$field],
                            (array) ($actual[$field] ?? []),
                        ),
                        'component_order' => 'CURRENT_CACHE_COMPONENT_ORDER_MISMATCH',
                        'sources' => 'CURRENT_CACHE_SOURCES_MISMATCH',
                        'structured_data_from_visible_content' => 'CURRENT_CACHE_STRUCTURED_DATA_MISMATCH',
                        'implementation_contract' => 'CURRENT_CACHE_IMPLEMENTATION_CONTRACT_MISMATCH',
                        'presentation_v1', 'presentation_v2' => 'CURRENT_CACHE_PRESENTATION_MISMATCH',
                        'content_v3' => $this->contentV3MismatchCode(
                            (array) $expected[$field],
                            (array) ($actual[$field] ?? []),
                        ),
                        default => 'CURRENT_CACHE_CONTENT_MISMATCH',
                    };
                }
            }
        } catch (CareerCurrentAuthorityPackageFailure) {
            return 'CURRENT_CACHE_CONTENT_MISMATCH';
        }

        return 'CURRENT_CACHE_CONTENT_MISMATCH';
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function contentV3MismatchCode(array $expected, array $actual): string
    {
        foreach (['contract_version', 'locale', 'subject', 'content_state', 'source_content_sha256'] as $field) {
            if (CareerCurrentAuthorityPackage::hashValue($expected[$field] ?? null)
                !== CareerCurrentAuthorityPackage::hashValue($actual[$field] ?? null)) {
                return 'CURRENT_CACHE_CONTENT_V3_'.strtoupper($field).'_MISMATCH';
            }
        }

        $expectedBlocks = is_array($expected['blocks'] ?? null) ? $expected['blocks'] : [];
        $actualBlocks = is_array($actual['blocks'] ?? null) ? $actual['blocks'] : [];
        if (count($expectedBlocks) !== count($actualBlocks)) {
            return 'CURRENT_CACHE_CONTENT_V3_BLOCK_COUNT_MISMATCH';
        }
        if (array_column($expectedBlocks, 'id') !== array_column($actualBlocks, 'id')) {
            return 'CURRENT_CACHE_CONTENT_V3_BLOCK_ORDER_MISMATCH';
        }
        foreach ($expectedBlocks as $index => $expectedBlock) {
            $actualBlock = $actualBlocks[$index] ?? null;
            if (! is_array($expectedBlock) || ! is_array($actualBlock)) {
                return 'CURRENT_CACHE_CONTENT_V3_BLOCK_SHAPE_MISMATCH';
            }
            foreach (['copy_key', 'content_state', 'availability'] as $field) {
                if (($expectedBlock[$field] ?? null) !== ($actualBlock[$field] ?? null)) {
                    return 'CURRENT_CACHE_CONTENT_V3_BLOCK_FIELD_MISMATCH';
                }
            }
            $expectedItems = is_array($expectedBlock['items'] ?? null) ? $expectedBlock['items'] : [];
            $actualItems = is_array($actualBlock['items'] ?? null) ? $actualBlock['items'] : [];
            if (count($expectedItems) !== count($actualItems)) {
                return 'CURRENT_CACHE_CONTENT_V3_ITEM_COUNT_MISMATCH';
            }
            if (array_column($expectedItems, 'id') !== array_column($actualItems, 'id')) {
                return 'CURRENT_CACHE_CONTENT_V3_ITEM_ORDER_MISMATCH';
            }
            foreach ($expectedItems as $itemIndex => $expectedItem) {
                $actualItem = $actualItems[$itemIndex] ?? null;
                if (! is_array($expectedItem) || ! is_array($actualItem)) {
                    return 'CURRENT_CACHE_CONTENT_V3_ITEM_SHAPE_MISMATCH';
                }
                foreach (['copy_key', 'type', 'availability'] as $field) {
                    if (($expectedItem[$field] ?? null) !== ($actualItem[$field] ?? null)) {
                        return 'CURRENT_CACHE_CONTENT_V3_ITEM_FIELD_MISMATCH';
                    }
                }
                if (CareerCurrentAuthorityPackage::hashValue($expectedItem['data'] ?? null)
                    !== CareerCurrentAuthorityPackage::hashValue($actualItem['data'] ?? null)) {
                    return 'CURRENT_CACHE_CONTENT_V3_ITEM_DATA_MISMATCH';
                }
            }
        }

        return 'CURRENT_CACHE_CONTENT_V3_MISMATCH';
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function pageMismatchCode(array $expected, array $actual): string
    {
        if (($expected['locale'] ?? null) !== ($actual['locale'] ?? null)) {
            return 'CURRENT_CACHE_PAGE_LOCALE_MISMATCH';
        }
        $expectedContent = is_array($expected['content'] ?? null) ? $expected['content'] : [];
        $actualContent = is_array($actual['content'] ?? null) ? $actual['content'] : [];
        foreach ($expectedContent as $componentId => $component) {
            if (! array_key_exists($componentId, $actualContent)
                || ! hash_equals(
                    CareerCurrentAuthorityPackage::hashValue($component),
                    CareerCurrentAuthorityPackage::hashValue($actualContent[$componentId]),
                )) {
                return match ($componentId) {
                    'career_quick_answers_block' => 'CURRENT_CACHE_QUICK_ANSWERS_MISMATCH',
                    'onet_structured_fields_block' => 'CURRENT_CACHE_ONET_STRUCTURED_FIELDS_MISMATCH',
                    default => 'CURRENT_CACHE_PAGE_CONTENT_MISMATCH',
                };
            }
        }

        return 'CURRENT_CACHE_PAGE_CONTENT_MISMATCH';
    }

    /** @param array<mixed> $value */
    private function containsVersionDiscriminator(array $value): bool
    {
        foreach ($value as $key => $item) {
            if ($key === 'asset_version' || $key === 'template_version') {
                return true;
            }
            if (is_array($item) && $this->containsVersionDiscriminator($item)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function canonicalSurface(array $row, string $locale): array
    {
        $surface = $this->package->publicProjection($row, $locale);
        unset($surface['content_v3']);
        $hydrated = $this->canonicalContent->hydrate(
            $surface,
            (string) ($row['canonical_slug'] ?? ''),
            $locale,
        );
        if (! is_array($hydrated)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_COMPATIBILITY_INVALID');
        }

        return $hydrated;
    }
}
