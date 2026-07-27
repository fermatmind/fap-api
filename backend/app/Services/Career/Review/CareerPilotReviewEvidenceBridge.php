<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Models\ReviewAttestation;
use App\Services\Career\Dataset\CareerPublishTrackResolver;
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

    private const PRIVATE_DETAIL_KEYS = [
        'source_id',
        'source_ids',
        'source_trace_id',
        'evidence_id',
        'row_hash',
        'search_projection',
        'audit_fields',
        'compile_refs',
        'crosswalk_ids',
        'import_run_id',
        'compile_run_id',
        'index_state_id',
        'attested_by_admin_user_id',
        'target_set_sha256',
        'package_sha256',
        'evidence_sha256',
        'exceptions_json',
    ];

    /** @var array<string, array{review_state:string,last_reviewed_at:string|null,index_item_sha256_by_locale?:array<string,string>,target_sha256_by_locale_and_kind?:array<string,array<string,string>>}>|null */
    private ?array $requestProjection = null;

    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerSeoReviewAttestationService $reviews,
        private readonly ReviewAttestationCanonicalizer $canonicalizer,
        private readonly CareerPublishTrackResolver $publishTrackResolver,
        private readonly CareerSearchEntryTierResolver $searchEntryTierResolver,
        private readonly CareerSearchEntryQualityEvaluator $searchEntryQualityEvaluator,
    ) {}

    /**
     * @param  list<string>  $slugs
     * @return array{schema_version:string,scope_type:string,scope_identity:string,slugs:list<string>,targets:list<array{identity:string,sha256:string}>,target_count:int,target_set_sha256:string,package_sha256:string,index_item_sha256_by_slug:array<string,array<string,string>>}
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

        $indexItems = $this->exactIndexItems($normalizedSlugs);
        $indexItemShaBySlug = [];
        $targets = [];
        foreach ($normalizedSlugs as $slug) {
            foreach (self::LOCALES as $locale) {
                $readiness = $this->responseCache->jobDetailCacheReadiness($slug, $locale);
                $payload = $readiness['payload'];
                if (! is_array($payload)
                    || ! in_array($readiness['classification'], ['ready_active', 'ready_lkg'], true)
                    || ! $this->responseCache->jobDetailCacheIsReady($slug, $locale)) {
                    throw new \RuntimeException(sprintf(
                        'Career pilot review target is not available from active/LKG detail authority: %s %s.',
                        $slug,
                        $locale,
                    ));
                }

                $indexItem = $indexItems[$locale][$slug];
                $indexItemShaBySlug[$slug][$locale] = $this->indexItemSha($indexItem);
                foreach ($this->targetPayloads($payload, $indexItem) as $kind => $targetPayload) {
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
            'index_item_sha256_by_slug' => $indexItemShaBySlug,
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
        if (! $this->detailPayloadMatchesProjection($slug, $payload, $projection)) {
            $projection = self::UNAPPROVED_PROJECTION;
        }

        $payload['trust_manifest'] = array_merge(
            $payload['trust_manifest'],
            Arr::only($projection, ['review_state', 'last_reviewed_at']),
        );
        $authority = $this->searchEntryAuthority($slug, $projection, $payload);
        $payload['search_entry_tier'] = $authority['search_entry_tier'];
        $payload['search_entry_authority'] = $authority;

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function projectJobIndexPayload(array $payload, string $publicLocale): array
    {
        $projections = $this->projectionBySlug();
        $locale = $this->normalizeLocale($publicLocale);
        if (! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $payload['items'] = array_map(function (mixed $item) use ($projections, $locale): mixed {
            if (! is_array($item) || ! is_array($item['trust_summary'] ?? null)) {
                return $item;
            }
            $slug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
            $projection = $projections[$slug] ?? self::UNAPPROVED_PROJECTION;
            $expectedIndexSha = $projection['index_item_sha256_by_locale'][$locale] ?? null;
            if (! is_string($expectedIndexSha) || ! hash_equals($expectedIndexSha, $this->indexItemSha($item))) {
                $projection = self::UNAPPROVED_PROJECTION;
            }
            $item['trust_summary'] = array_merge(
                $item['trust_summary'],
                Arr::only($projection, ['review_state', 'last_reviewed_at']),
            );
            $authority = $this->searchEntryAuthority($slug, $projection, $item);
            $item['search_entry_tier'] = $authority['search_entry_tier'];
            $item['search_entry_authority'] = $authority;

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
        $seenSlugs = [];
        foreach ($attestations as $attestation) {
            $scopeIdentity = (string) $attestation->scope_identity;
            if (isset($seenScopes[$scopeIdentity])) {
                continue;
            }
            $seenScopes[$scopeIdentity] = true;

            $scopeSlugs = $this->slugsFromEvidence($attestation);
            $unresolvedSlugs = [];
            foreach ($scopeSlugs as $slug) {
                if (isset($seenSlugs[$slug])) {
                    continue;
                }
                $seenSlugs[$slug] = true;
                $unresolvedSlugs[] = $slug;
                $resolved[$slug] = ['review_state' => 'unknown', 'last_reviewed_at' => null];
            }
            if ($unresolvedSlugs === [] || (string) $attestation->decision !== 'approved_all') {
                continue;
            }

            try {
                $package = $this->buildPackage($scopeSlugs);
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
            $targetShasBySlug = $this->targetShasBySlug($package['targets']);
            foreach ($unresolvedSlugs as $slug) {
                $resolved[$slug] = [
                    'review_state' => 'approved',
                    'last_reviewed_at' => $reviewedAt,
                    'index_item_sha256_by_locale' => $package['index_item_sha256_by_slug'][$slug],
                    'target_sha256_by_locale_and_kind' => $targetShasBySlug[$slug],
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

    /**
     * @param  list<array{identity:string,sha256:string}>  $targets
     * @return array<string,array<string,array<string,string>>>
     */
    private function targetShasBySlug(array $targets): array
    {
        $resolved = [];
        foreach ($targets as $target) {
            $parts = explode(':', $target['identity']);
            if (count($parts) !== 4) {
                throw new \RuntimeException('Career pilot review target identity is invalid.');
            }
            [, $slug, $locale, $kind] = $parts;
            $resolved[$slug][$locale][$kind] = $target['sha256'];
        }

        return $resolved;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $projection */
    private function detailPayloadMatchesProjection(string $slug, array $payload, array $projection): bool
    {
        if (($projection['review_state'] ?? null) !== 'approved') {
            return true;
        }

        $locale = $this->normalizeLocale((string) data_get($payload, 'locale_policy.locale', ''));
        $expected = $projection['target_sha256_by_locale_and_kind'][$locale] ?? null;
        if (! is_array($expected)) {
            return false;
        }

        try {
            $indexItem = $this->exactIndexItems([strtolower(trim($slug))])[$locale][strtolower(trim($slug))];
            $currentTargets = $this->targetPayloads($payload, $indexItem);
        } catch (\Throwable) {
            return false;
        }

        foreach (self::TARGET_KINDS as $kind) {
            $expectedSha = $expected[$kind] ?? null;
            if (! is_string($expectedSha)
                || ! hash_equals(
                    $expectedSha,
                    hash('sha256', $this->canonicalizer->encode($currentTargets[$kind])),
                )) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $indexItem @return array<string,array<string,mixed>> */
    private function targetPayloads(array $payload, array $indexItem): array
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
                'index_entry' => $indexItem,
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
                'score_bundle' => $payload['score_bundle'] ?? [],
                'white_box_scores' => $payload['white_box_scores'] ?? [],
                'integrity_summary' => $payload['integrity_summary'] ?? [],
                'display_claim_permissions' => $display['claim_permissions'] ?? null,
                'display_sources' => $display['sources'] ?? null,
                'public_trust_evidence' => Arr::except(
                    is_array($payload['trust_manifest'] ?? null) ? $payload['trust_manifest'] : [],
                    ['review_state', 'last_reviewed_at', 'reviewer'],
                ),
                'complete_public_detail_projection' => $this->reviewablePublicDetailPayload($payload),
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function reviewablePublicDetailPayload(array $payload): array
    {
        foreach (self::PRIVATE_DETAIL_KEYS as $key) {
            unset($payload[$key]);
        }

        if (is_array($payload['trust_manifest'] ?? null)) {
            $payload['trust_manifest'] = Arr::except(
                $payload['trust_manifest'],
                ['review_state', 'last_reviewed_at', 'reviewer'],
            );
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->reviewablePublicDetailPayload($value);
            }
        }

        return $payload;
    }

    /** @param list<string> $slugs @return array<string,array<string,array<string,mixed>>> */
    private function exactIndexItems(array $slugs): array
    {
        $resolved = [];
        foreach (self::LOCALES as $locale) {
            $payload = $this->responseCache->jobIndexPayload($locale);
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($slugs as $slug) {
                $matches = array_values(array_filter($items, static fn (mixed $item): bool => is_array($item)
                    && strtolower(trim((string) data_get($item, 'identity.canonical_slug', ''))) === $slug));
                if (count($matches) !== 1) {
                    throw new \RuntimeException(sprintf(
                        'Career pilot review target requires one exact index entry: %s %s.',
                        $slug,
                        $locale,
                    ));
                }
                $resolved[$locale][$slug] = $matches[0];
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $item */
    private function indexItemSha(array $item): string
    {
        return hash('sha256', $this->canonicalizer->encode($item));
    }

    private function normalizeLocale(string $locale): string
    {
        return in_array(strtolower(trim($locale)), ['en', 'en-us'], true) ? 'en' : 'zh-CN';
    }

    /**
     * @param  array<string,mixed>  $projection
     * @param  array<string,mixed>  $publicPayload
     * @return array<string,mixed>
     */
    private function searchEntryAuthority(string $slug, array $projection, array $publicPayload): array
    {
        try {
            $publishTrack = $this->publishTrackResolver->resolve($slug, $publicPayload);
        } catch (\Throwable) {
            $publishTrack = null;
        }
        $reviewState = (string) ($projection['review_state'] ?? 'unknown');
        $lastReviewedAt = is_string($projection['last_reviewed_at'] ?? null)
            ? $projection['last_reviewed_at']
            : null;
        $contentQualityTier = $reviewState === 'approved' && $lastReviewedAt !== null
            ? $this->searchEntryQualityEvaluator->qualityTierForSlug($slug)
            : null;

        return $this->searchEntryTierResolver->resolve(
            slug: $slug,
            publicVisibility: true,
            robotsIndexable: $this->robotsIndexable($publicPayload['seo_contract'] ?? null),
            reviewState: $reviewState,
            lastReviewedAt: $lastReviewedAt,
            publishTrack: $publishTrack,
            contentQualityTier: $contentQualityTier,
        );
    }

    private function robotsIndexable(mixed $seoContract): bool
    {
        if (! is_array($seoContract) || ($seoContract['index_eligible'] ?? null) !== true) {
            return false;
        }

        $tokens = array_values(array_filter(array_map(
            static fn (string $token): string => strtolower(trim($token)),
            explode(',', is_string($seoContract['robots_policy'] ?? null)
                ? $seoContract['robots_policy']
                : ''),
        )));

        return in_array('index', $tokens, true)
            && in_array('follow', $tokens, true)
            && ! in_array('noindex', $tokens, true)
            && ! in_array('nofollow', $tokens, true);
    }
}
