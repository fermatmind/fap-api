<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use RuntimeException;
use Throwable;

/**
 * Reads the separately released Career 1046 sitemap/llms permit.
 *
 * One instance resolves one immutable authority snapshot. The frozen 1046
 * cohort is withheld on every missing, malformed, or drifted authority state;
 * Career URLs outside that cohort retain their existing eligibility.
 */
final class Career1046DiscoverabilityReleaseGate
{
    public const SCHEMA_VERSION = 'career.1046.discoverability_release.v1';

    public const TARGET_SLUG_COUNT = 1046;

    public const TARGET_LOCALE_ROW_COUNT = 2092;

    public const TARGET_SLUG_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const TARGET_LOCALE_ROW_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';

    /** @var null|array{target:array<string,true>,released:array<string,true>,identity:string} */
    private ?array $snapshot = null;

    private int $validationCount = 0;

    public function __construct(private readonly ?string $authorityRoot = null) {}

    /** @return bool Whether this exact locale row is allowed into sitemap and llms. */
    public function allows(string $slug, string $locale): bool
    {
        $snapshot = $this->snapshot();
        $normalizedSlug = strtolower(trim($slug));
        if (isset($snapshot['target']['*'])) {
            return false;
        }
        if (! isset($snapshot['target'][$normalizedSlug])) {
            return true;
        }

        return isset($snapshot['released'][$normalizedSlug.'|'.$this->locale($locale)]);
    }

    public function cacheIdentity(): string
    {
        return $this->snapshot()['identity'];
    }

    public function validationCount(): int
    {
        return $this->validationCount;
    }

    /**
     * Validates a complete release payload before the operations runner writes
     * it. This deliberately has no database, cache, or pointer side effect.
     *
     * @param  array<string, mixed>  $permit
     */
    public function validatePermit(string $root, array $permit): void
    {
        $activeRaw = $this->readFile($root, $root.'/active-generation.json');
        $this->validatePermitAgainstActive($root, $permit, $activeRaw, $this->decode($activeRaw));
    }

    /** @return array{target:array<string,true>,released:array<string,true>,identity:string} */
    private function snapshot(): array
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $target = $this->frozenTargetSlugSet();
        $identity = [
            'schema_version' => 'career.1046.discoverability_cache_identity.v1',
            'target_slug_set_sha256' => self::TARGET_SLUG_SET_SHA256,
            'state' => 'held',
            'active_pointer_sha256' => null,
            'permit_sha256' => null,
        ];
        $released = [];
        $root = $this->authorityRoot ?? storage_path('app/private/career_generation_authority');

        try {
            $activeRaw = $this->readFile($root, $root.'/active-generation.json');
            $identity['active_pointer_sha256'] = hash('sha256', $activeRaw);
            $active = $this->decode($activeRaw);
            $payload = $active['payload'] ?? null;
            $generationId = is_array($payload) ? ($payload['generation_id'] ?? null) : null;
            if (! is_string($generationId) || preg_match('/^career-1046-[0-9a-f]{32}$/', $generationId) !== 1) {
                throw new RuntimeException('career_1046_discoverability_active_generation_invalid');
            }

            $permitRaw = $this->readFile(
                $root,
                $root.'/discoverability-releases/'.$generationId.'/release.json',
            );
            $identity['permit_sha256'] = hash('sha256', $permitRaw);
            $permit = $this->decode($permitRaw);
            $this->validationCount++;
            $this->validatePermitAgainstActive($root, $permit, $activeRaw, $active);
            $rows = $permit['payload']['released_locale_rows'] ?? null;
            if (! is_array($rows)) {
                throw new RuntimeException('career_1046_discoverability_locale_rows_invalid');
            }
            $released = array_fill_keys($rows, true);
            $identity['state'] = 'released';
        } catch (Throwable) {
            $released = [];
        }

        return $this->snapshot = [
            'target' => $target,
            'released' => $released,
            'identity' => CareerGenerationCanonicalJson::sha256($identity),
        ];
    }

    /** @return array<string, true> */
    private function frozenTargetSlugSet(): array
    {
        try {
            $manifestRaw = file_get_contents(base_path('docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json'));
            if (! is_string($manifestRaw)) {
                throw new RuntimeException('career_1046_discoverability_manifest_unavailable');
            }
            $manifest = $this->decode($manifestRaw);
            $slugs = array_values(array_unique(array_map(
                static fn (mixed $slug): string => is_string($slug) ? strtolower(trim($slug)) : '',
                [
                    ...(is_array($manifest['baseline_slugs'] ?? null) ? $manifest['baseline_slugs'] : []),
                    ...(is_array($manifest['delta_slugs'] ?? null) ? $manifest['delta_slugs'] : []),
                ],
            )));
            $slugs = array_values(array_filter($slugs, static fn (string $slug): bool => $slug !== ''));
            sort($slugs, SORT_STRING);
            if (count($slugs) !== self::TARGET_SLUG_COUNT
                || ! hash_equals(self::TARGET_SLUG_SET_SHA256, hash('sha256', implode("\n", $slugs)."\n"))) {
                throw new RuntimeException('career_1046_discoverability_target_manifest_drift');
            }

            return array_fill_keys($slugs, true);
        } catch (Throwable) {
            // A corrupt repository authority is not a reason to expose any
            // Career URL through this release gate.
            return ['*' => true];
        }
    }

    /** @param array<string, mixed> $permit */
    private function validatePermitAgainstActive(string $root, array $permit, string $activeRaw, array $active): void
    {
        if (($permit['schema_version'] ?? null) !== self::SCHEMA_VERSION || ! is_array($permit['payload'] ?? null)) {
            throw new RuntimeException('career_1046_discoverability_permit_schema_invalid');
        }
        $payload = $permit['payload'];
        if (! is_string($permit['payload_sha256'] ?? null)
            || ! hash_equals((string) $permit['payload_sha256'], CareerGenerationCanonicalJson::sha256($payload))) {
            throw new RuntimeException('career_1046_discoverability_permit_payload_hash_invalid');
        }
        $generationId = $payload['generation_id'] ?? null;
        if (! is_string($generationId) || preg_match('/^career-1046-[0-9a-f]{32}$/', $generationId) !== 1) {
            throw new RuntimeException('career_1046_discoverability_generation_invalid');
        }
        foreach (['active_pointer_sha256', 'immutable_pointer_sha256', 'task7a_receipt_sha256', 'database_state_sha256'] as $key) {
            if (! is_string($payload[$key] ?? null) || preg_match('/^[0-9a-f]{64}$/', $payload[$key]) !== 1) {
                throw new RuntimeException('career_1046_discoverability_hash_invalid');
            }
        }
        if (! hash_equals($payload['active_pointer_sha256'], $payload['immutable_pointer_sha256'])
            || ($payload['slug_count'] ?? null) !== self::TARGET_SLUG_COUNT
            || ($payload['locale_row_count'] ?? null) !== self::TARGET_LOCALE_ROW_COUNT
            || ($payload['target_slug_set_sha256'] ?? null) !== self::TARGET_SLUG_SET_SHA256
            || ($payload['target_locale_row_set_sha256'] ?? null) !== self::TARGET_LOCALE_ROW_SET_SHA256
            || ($payload['sitemap_released'] ?? null) !== true
            || ($payload['llms_released'] ?? null) !== true
            || ($payload['search_submission_enabled'] ?? null) !== false) {
            throw new RuntimeException('career_1046_discoverability_permit_contract_invalid');
        }
        $rows = $payload['released_locale_rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== self::TARGET_LOCALE_ROW_COUNT
            || count(array_unique($rows, SORT_STRING)) !== self::TARGET_LOCALE_ROW_COUNT) {
            throw new RuntimeException('career_1046_discoverability_locale_rows_invalid');
        }
        foreach ($rows as $row) {
            if (! is_string($row) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\|(en|zh)$/', $row) !== 1) {
                throw new RuntimeException('career_1046_discoverability_locale_rows_invalid');
            }
        }
        sort($rows, SORT_STRING);
        if (! hash_equals(self::TARGET_LOCALE_ROW_SET_SHA256, hash('sha256', implode("\n", $rows)."\n"))) {
            throw new RuntimeException('career_1046_discoverability_locale_set_drift');
        }
        $slugs = array_values(array_unique(array_map(static fn (string $row): string => strtok($row, '|'), $rows)));
        sort($slugs, SORT_STRING);
        if (count($slugs) !== self::TARGET_SLUG_COUNT
            || ! hash_equals(self::TARGET_SLUG_SET_SHA256, hash('sha256', implode("\n", $slugs)."\n"))) {
            throw new RuntimeException('career_1046_discoverability_slug_set_drift');
        }
        if (! hash_equals($payload['active_pointer_sha256'], hash('sha256', $activeRaw))) {
            throw new RuntimeException('career_1046_discoverability_active_pointer_drift');
        }
        $activePayload = $active['payload'] ?? null;
        if (! is_array($activePayload) || ($activePayload['generation_id'] ?? null) !== $generationId) {
            throw new RuntimeException('career_1046_discoverability_generation_drift');
        }
        $immutableRaw = $this->readFile($root, $root.'/generations/'.$generationId.'/generation-pointer.json');
        if (! hash_equals($activeRaw, $immutableRaw)) {
            throw new RuntimeException('career_1046_discoverability_immutable_pointer_drift');
        }
        $documents = $payload['document_sha256'] ?? null;
        if (! is_array($documents) || array_keys($documents) !== [
            'career-directory-en.json', 'career-directory-zh.json', 'career-job-details-en.json', 'career-job-details-zh.json', 'generation-manifest.json',
        ]) {
            throw new RuntimeException('career_1046_discoverability_document_set_invalid');
        }
        foreach ($documents as $filename => $sha256) {
            $raw = $this->readFile($root, $root.'/generations/'.$generationId.'/'.$filename);
            if (! is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
                || ! hash_equals($sha256, hash('sha256', $raw))) {
                throw new RuntimeException('career_1046_discoverability_document_drift');
            }
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('career_1046_discoverability_json_invalid');
        }

        return $decoded;
    }

    private function readFile(string $root, string $path): string
    {
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        if (! is_string($rootReal) || ! is_string($pathReal) || is_link($path) || ! str_starts_with($pathReal, $rootReal.'/')) {
            throw new RuntimeException('career_1046_discoverability_path_invalid');
        }
        $raw = file_get_contents($pathReal);
        if (! is_string($raw)) {
            throw new RuntimeException('career_1046_discoverability_read_failed');
        }

        return $raw;
    }

    private function locale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }
}
