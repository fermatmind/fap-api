<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Services\ReviewGovernance\PublicReviewContract;
use Throwable;

final class CareerJobDetailCanonicalCacheReader
{
    public const CODEC_VERSION = 'career.job-detail.gzip-json.v1';

    public const COMPILER_VERSION = 'career.content-v3.per-page-reader.v4';

    private const ENVELOPE_KEYS = ['codec', 'payload', 'sha256'];

    public function __construct(
        private readonly PublicReviewContract $publicReviewContract,
        private readonly ?CareerContentV3CanonicalReader $canonicalContent = null,
    ) {}

    /** @param array<string,mixed> $payload @return array{codec:string,payload:string,sha256:string} */
    public function encode(array $payload): array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $compressed = gzencode($json, 6);
        if ($compressed === false) {
            throw new \RuntimeException('Career detail cache payload compression failed.');
        }

        return [
            'codec' => self::CODEC_VERSION,
            'payload' => base64_encode($compressed),
            'sha256' => hash('sha256', $json),
        ];
    }

    /**
     * Decode only the supported envelope or the one-release legacy array.
     * Any envelope-shaped unknown/corrupt value fails closed.
     *
     * @return array<string,mixed>|null
     */
    public function decode(mixed $stored): ?array
    {
        if (! is_array($stored) || array_is_list($stored)) {
            return null;
        }
        if (! array_key_exists('codec', $stored)) {
            return $stored;
        }
        $keys = array_keys($stored);
        sort($keys, SORT_STRING);
        $expected = self::ENVELOPE_KEYS;
        sort($expected, SORT_STRING);
        if ($keys !== $expected || ($stored['codec'] ?? null) !== self::CODEC_VERSION) {
            return null;
        }

        $declaredSha = $stored['sha256'] ?? null;
        $encoded = $stored['payload'] ?? null;
        if (! is_string($declaredSha)
            || preg_match('/\A[0-9a-f]{64}\z/', $declaredSha) !== 1
            || ! is_string($encoded)) {
            return null;
        }
        $compressed = base64_decode($encoded, true);
        if ($compressed === false) {
            return null;
        }
        $json = gzdecode($compressed);
        if (! is_string($json) || ! hash_equals($declaredSha, hash('sha256', $json))) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) && ! array_is_list($payload) ? $payload : null;
    }

    public function isSupportedEnvelope(mixed $stored): bool
    {
        return is_array($stored)
            && ($stored['codec'] ?? null) === self::CODEC_VERSION;
    }

    /** @return array<string,mixed>|null */
    public function read(mixed $stored, string $slug, string $locale): ?array
    {
        $payload = $this->decode($stored);
        if ($payload === null) {
            return null;
        }

        return $this->normalizeAndHydrate($payload, $slug, $locale);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function normalizeAndHydrate(array $payload, string $slug, string $locale): ?array
    {
        $surface = $payload['display_surface_v1'] ?? null;
        $page = is_array($surface) ? data_get($surface, 'page.content') : null;
        if (! is_array($surface) || ! is_array($page)) {
            return null;
        }

        try {
            $reader = $this->canonicalContent ?? app(CareerContentV3CanonicalReader::class);
            $hydrated = $reader->hydrate($surface, $slug, $locale);
            if (! is_array($hydrated)) {
                return null;
            }
            $payload['display_surface_v1'] = $hydrated;
        } catch (Throwable) {
            return null;
        }

        if (is_array($payload['trust_manifest'] ?? null)) {
            $payload['trust_manifest'] = $this->normalizeReviewContainer($payload['trust_manifest']);
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function withoutDerivedContentV3(array $payload, string $slug, string $locale): array
    {
        $contentV3 = data_get($payload, 'display_surface_v1.content_v3');
        if (! is_array($contentV3)) {
            return $payload;
        }
        $hydrated = $this->normalizeAndHydrate($payload, $slug, $locale);
        if (is_array($hydrated) && data_get($hydrated, 'display_surface_v1.content_v3') === $contentV3) {
            unset($payload['display_surface_v1']['content_v3']);
        }

        return $payload;
    }

    /** @param array<string,mixed> $review @return array<string,mixed> */
    public function normalizeReviewContainer(array $review): array
    {
        $canonical = array_key_exists('review_state', $review);

        return array_merge($review, $this->publicReviewContract->project(
            $canonical ? $review['review_state'] : ($review['reviewer_status'] ?? null),
            $canonical ? ($review['last_reviewed_at'] ?? null) : ($review['reviewed_at'] ?? null),
        ));
    }

    /** @param callable(array<string,mixed>):bool $published */
    public function snapshotIsValid(mixed $snapshot, string $slug, string $locale, callable $published): bool
    {
        return is_array($snapshot)
            && strtolower(trim((string) ($snapshot['slug'] ?? ''))) === strtolower(trim($slug))
            && $this->normalizeLocale((string) ($snapshot['locale'] ?? '')) === $this->normalizeLocale($locale)
            && $published($snapshot) === true;
    }

    public static function codecDigest(): string
    {
        return hash('sha256', self::CODEC_VERSION.'|gzip-level:6|json:unescaped-unicode+slashes|sha256:json');
    }

    public static function compilerDigest(): string
    {
        return hash('sha256', self::COMPILER_VERSION.'|'.CareerContentV3AuthorityPackage::COMPILER_VERSION.'|'.CareerContentV3Contract::CONTRACT_VERSION);
    }

    private function normalizeLocale(string $locale): string
    {
        return strtolower(trim($locale)) === 'en' ? 'en' : 'zh-CN';
    }
}
