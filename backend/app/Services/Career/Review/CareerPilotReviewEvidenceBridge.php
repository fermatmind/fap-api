<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Models\ReviewAttestation;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\ReviewGovernance\CareerSeoReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use Illuminate\Support\Arr;

/**
 * Exact, read-only reviewer-evidence projection for bounded career pilots.
 *
 * The source is the already-published bilingual active/LKG detail read model.
 * Evidence binding remains private and cannot publish, index, enqueue, submit,
 * or otherwise change discoverability.
 *
 * @review-surface career_trust_manifest
 */
final class CareerPilotReviewEvidenceBridge
{
    public const SURFACE_ID = 'career_trust_manifest';

    public const SCOPE_TYPE = 'career_search_entry_pilot';

    private const TARGET_SCHEMA_VERSION = 'career.search_entry.review_targets.v1';

    private const TARGET_KINDS = ['content', 'seo', 'visible_claims'];

    private const LOCALES = ['en', 'zh-CN'];

    private const UNAPPROVED_PROJECTION = [
        'review_state' => 'unknown',
        'last_reviewed_at' => null,
    ];

    /** @var array<string, array{review_state:string,last_reviewed_at:string|null}>|null */
    private ?array $requestProjection = null;

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerSeoReviewAttestationService $reviews,
        private readonly ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  list<string>  $slugs
     * @return array{schema_version:string,scope_type:string,scope_identity:string,slugs:list<string>,targets:list<array{identity:string,sha256:string}>,target_count:int,target_set_sha256:string,package_sha256:string}
     */
    public function buildPackage(array $slugs): array
    {
        $normalizedSlugs = array_values(array_unique(array_filter(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            $slugs,
        ))));
        sort($normalizedSlugs, SORT_STRING);

        if ($normalizedSlugs === []) {
            throw new \RuntimeException('Career pilot review package requires at least one slug.');
        }
        foreach ($normalizedSlugs as $slug) {
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
                throw new \RuntimeException('Career pilot review package contains an invalid slug.');
            }
        }

        $targets = [];
        foreach ($normalizedSlugs as $slug) {
            foreach (self::LOCALES as $locale) {
                $read = $this->responseCache->jobDetailRead($slug, $locale);
                $payload = $read['payload'];
                if (! is_array($payload) || ! in_array($read['state'], ['fresh', 'stale'], true)) {
                    throw new \RuntimeException(sprintf(
                        'Career pilot review target is not available from active/LKG detail authority: %s %s.',
                        $slug,
                        $locale,
                    ));
                }

                foreach ($this->targetPayloads($payload) as $kind => $targetPayload) {
                    $targets[] = [
                        'identity' => sprintf('career-job:%s:%s:%s', $slug, $locale, $kind),
                        'sha256' => hash('sha256', $this->canonicalizer->encode($targetPayload)),
                    ];
                }
            }
        }

        usort($targets, static fn (array $left, array $right): int => strcmp($left['identity'], $right['identity']));
        $centralTargets = $this->reviews->targets(self::SURFACE_ID, $targets);
        $targetSetSha256 = hash('sha256', $this->canonicalizer->encode($centralTargets));
        $slugSetSha256 = hash('sha256', $this->canonicalizer->encode($normalizedSlugs));
        $scopeIdentity = 'career-search-entry-pilot:'.$slugSetSha256;
        $packageSha256 = hash('sha256', $this->canonicalizer->encode([
            'schema_version' => self::TARGET_SCHEMA_VERSION,
            'scope_type' => self::SCOPE_TYPE,
            'scope_identity' => $scopeIdentity,
            'targets' => $targets,
        ]));

        return [
            'schema_version' => self::TARGET_SCHEMA_VERSION,
            'scope_type' => self::SCOPE_TYPE,
            'scope_identity' => $scopeIdentity,
            'slugs' => $normalizedSlugs,
            'targets' => $targets,
            'target_count' => count($targets),
            'target_set_sha256' => $targetSetSha256,
            'package_sha256' => $packageSha256,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function projectDetailPayload(string $slug, array $payload): array
    {
        if (! is_array($payload['trust_manifest'] ?? null)) {
            return $payload;
        }

        $projection = $this->projectionBySlug()[strtolower(trim($slug))]
            ?? self::UNAPPROVED_PROJECTION;

        $payload['trust_manifest'] = array_merge($payload['trust_manifest'], $projection);

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function projectJobIndexPayload(array $payload): array
    {
        $projections = $this->projectionBySlug();
        if (! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $payload['items'] = array_map(static function (mixed $item) use ($projections): mixed {
            if (! is_array($item) || ! is_array($item['trust_summary'] ?? null)) {
                return $item;
            }
            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            $item['trust_summary'] = array_merge(
                $item['trust_summary'],
                $projections[$slug] ?? self::UNAPPROVED_PROJECTION,
            );

            return $item;
        }, $payload['items']);

        return $payload;
    }

    /**
     * @return array<string, array{review_state:string,last_reviewed_at:string|null}>
     */
    private function projectionBySlug(): array
    {
        if ($this->requestProjection !== null) {
            return $this->requestProjection;
        }

        $this->requestProjection = [];
        try {
            $attestations = ReviewAttestation::query()
                ->where('scope_type', self::SCOPE_TYPE)
                ->where('scope_identity', 'like', 'career-search-entry-pilot:%')
                ->with('targetEvidences')
                ->orderByDesc('attested_at')
                ->orderByDesc('id')
                ->get();
        } catch (\Throwable) {
            return $this->requestProjection;
        }

        $resolved = [];
        $seenScopes = [];
        foreach ($attestations as $attestation) {
            $scopeIdentity = (string) $attestation->scope_identity;
            if (isset($seenScopes[$scopeIdentity])) {
                continue;
            }
            $seenScopes[$scopeIdentity] = true;

            $slugs = $this->slugsFromEvidence($attestation);
            foreach ($slugs as $slug) {
                $resolved[$slug] ??= ['review_state' => 'unknown', 'last_reviewed_at' => null];
            }
            if ($slugs === [] || (string) $attestation->decision !== 'approved_all') {
                continue;
            }

            try {
                $package = $this->buildPackage($slugs);
            } catch (\Throwable) {
                continue;
            }

            if ((string) $attestation->scope_identity !== $package['scope_identity']
                || (int) $attestation->target_count !== $package['target_count']
                || ! hash_equals((string) $attestation->target_set_sha256, $package['target_set_sha256'])
                || ! hash_equals((string) $attestation->package_sha256, $package['package_sha256'])) {
                continue;
            }

            $approved = $this->reviews->approvedAllEvidence(
                self::SURFACE_ID,
                $package['targets'],
                $package['package_sha256'],
                self::SCOPE_TYPE,
                $package['scope_identity'],
            );
            if (! $approved instanceof ReviewAttestation || $approved->isNot($attestation)) {
                continue;
            }

            $reviewedAt = $attestation->attested_at?->utc()->toISOString();
            foreach ($slugs as $slug) {
                $resolved[$slug] = [
                    'review_state' => 'approved',
                    'last_reviewed_at' => $reviewedAt,
                ];
            }
        }

        return $this->requestProjection = $resolved;
    }

    /** @return list<string> */
    private function slugsFromEvidence(ReviewAttestation $attestation): array
    {
        $slugs = [];
        foreach ($attestation->targetEvidences as $evidence) {
            $identity = (string) $evidence->target_identity;
            $prefix = self::SURFACE_ID.':career-job:';
            if (! str_starts_with($identity, $prefix)) {
                return [];
            }
            $relative = substr($identity, strlen($prefix));
            $parts = explode(':', $relative);
            if (count($parts) !== 3
                || ! in_array($parts[1], self::LOCALES, true)
                || ! in_array($parts[2], self::TARGET_KINDS, true)) {
                return [];
            }
            $slugs[] = $parts[0];
        }

        $slugs = array_values(array_unique($slugs));
        sort($slugs, SORT_STRING);

        return count($attestation->targetEvidences) === count($slugs) * count(self::LOCALES) * count(self::TARGET_KINDS)
            ? $slugs
            : [];
    }

    /** @param array<string,mixed> $payload @return array<string,array<string,mixed>> */
    private function targetPayloads(array $payload): array
    {
        $display = is_array($payload['display_surface_v1'] ?? null) ? $payload['display_surface_v1'] : [];

        return [
            'content' => [
                'identity' => Arr::only($payload['identity'] ?? [], ['canonical_slug']),
                'locale_policy' => $payload['locale_policy'] ?? [],
                'titles' => $payload['titles'] ?? [],
                'content_sections' => $payload['content_sections'] ?? [],
                'content_body_md' => $payload['content_body_md'] ?? null,
                'display_page' => $display['page'] ?? null,
                'component_order' => $display['component_order'] ?? null,
            ],
            'seo' => [
                'seo_contract' => $payload['seo_contract'] ?? [],
                'structured_data' => $payload['structured_data'] ?? [],
                'display_structured_data' => $display['structured_data_from_visible_content'] ?? null,
            ],
            'visible_claims' => [
                'truth_layer' => $payload['truth_layer'] ?? [],
                'claim_permissions' => $payload['claim_permissions'] ?? [],
                'warnings' => $payload['warnings'] ?? [],
                'display_claim_permissions' => $display['claim_permissions'] ?? null,
                'display_sources' => $display['sources'] ?? null,
            ],
        ];
    }
}
