<?php

declare(strict_types=1);

namespace App\Services\Career\Review;

use App\Services\Career\Dataset\CareerPublishTrackResolver;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;

final class CareerSearchEntryQualityEvaluator
{
    private const LOCALES = ['en', 'zh-CN'];

    private const MIN_VISIBLE_CHARACTERS = 500;

    /**
     * Keys whose string values are rendered as reader-facing prose. Contract
     * metadata, identifiers, paths, hrefs, source pointers, and placeholder
     * state are deliberately absent.
     */
    private const VISIBLE_PROSE_FIELDS = [
        'answer',
        'body',
        'body_md',
        'boundary',
        'caption',
        'caveat',
        'caution',
        'content_body_md',
        'copy',
        'definition',
        'definition_block',
        'description',
        'explanation',
        'fit',
        'h1',
        'items',
        'label',
        'limitation',
        'note',
        'notice',
        'primary',
        'question',
        'quick_answer',
        'rows',
        'summary',
        'subtitle',
        'text',
        'title',
        'traits',
        'warning',
    ];

    /** @var array<string,array<string,mixed>> */
    private array $evaluations = [];

    /** @var array<string,array<string,list<array<string,mixed>>>> */
    private array $indexItemsByLocaleAndSlug = [];

    /** @var array<string,array<string,array{published:bool,classification:string,version:string|null,payload:array<string,mixed>|null}>> */
    private array $publicationBySlugAndLocale = [];

    public function __construct(
        private readonly CareerSearchEntryQualityBatchManifestReader $manifestReader,
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerPublishTrackResolver $publishTrackResolver,
        private readonly ReviewAttestationCanonicalizer $canonicalizer,
    ) {}

    /**
     * @return array{
     *   canonical_slug:string,
     *   pool_rank:int,
     *   publish_track:string,
     *   content_quality_tier:string,
     *   target_search_entry_tier:string,
     *   quality_score:int,
     *   canonical_urls:array{en:string,zh-CN:string},
     *   locales:array<string,array<string,mixed>>,
     *   blockers:list<string>
     * }
     */
    public function evaluate(string $slug): array
    {
        $slug = strtolower(trim($slug));
        if (isset($this->evaluations[$slug])) {
            return $this->evaluations[$slug];
        }
        $manifestRow = $this->manifestReader->bySlug()[$slug] ?? null;
        $blockers = [];
        if (! is_array($manifestRow)) {
            return $this->evaluations[$slug] = $this->blocked($slug, ['not_in_bounded_quality_manifest']);
        }

        $publishTrack = $this->publishTrackResolver->resolve($slug);
        if ($publishTrack !== $manifestRow['expected_publish_track']) {
            $blockers[] = 'publish_track_drift';
        }

        $localeEvidence = [];
        $canonicalUrls = [];
        foreach (self::LOCALES as $locale) {
            $evidence = $this->localeEvidence($slug, $locale);
            $localeEvidence[$locale] = $evidence;
            $canonicalUrls[$locale] = $evidence['canonical_url'];
            foreach ($evidence['blockers'] as $blocker) {
                $blockers[] = $locale.':'.$blocker;
            }
        }

        $blockers = array_values(array_unique($blockers));
        $qualityScore = array_sum(array_map(
            static fn (array $evidence): int => (int) $evidence['quality_score'],
            $localeEvidence,
        ));

        return $this->evaluations[$slug] = [
            'canonical_slug' => $slug,
            'pool_rank' => $manifestRow['pool_rank'],
            'publish_track' => (string) $publishTrack,
            'content_quality_tier' => $blockers === []
                ? CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE
                : 'ineligible',
            'target_search_entry_tier' => match ($publishTrack) {
                'stable' => CareerSearchEntryTierResolver::TIER_STABLE,
                'candidate' => CareerSearchEntryTierResolver::TIER_APPROVED_CANDIDATE,
                default => CareerSearchEntryTierResolver::TIER_INELIGIBLE,
            },
            'quality_score' => $qualityScore,
            'canonical_urls' => $canonicalUrls,
            'locales' => $localeEvidence,
            'blockers' => $blockers,
        ];
    }

    public function qualityTierForSlug(string $slug): ?string
    {
        try {
            $evaluation = $this->evaluate($slug);
        } catch (\Throwable) {
            return null;
        }

        return $evaluation['blockers'] === []
            ? CareerSearchEntryTierResolver::CONTENT_QUALITY_TIER_CONTROLLED_CANDIDATE
            : null;
    }

    public function resetEvaluationSnapshot(): void
    {
        $this->evaluations = [];
        $this->indexItemsByLocaleAndSlug = [];
        $this->publicationBySlugAndLocale = [];
    }

    /** @param list<string> $slugs */
    public function primePublicationSnapshot(array $slugs): void
    {
        $missing = array_values(array_filter(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs,
        )), fn (string $slug): bool => $slug !== '' && ! isset($this->publicationBySlugAndLocale[$slug])));
        if ($missing === []) {
            return;
        }

        foreach ($this->responseCache->jobDetailPublicationSnapshot($missing, self::LOCALES) as $slug => $locales) {
            $this->publicationBySlugAndLocale[$slug] = $locales;
        }
    }

    /**
     * Return the same request-bounded payload/version evidence consumed by
     * quality evaluation so review-target hashing can share that exact fence.
     *
     * @param  list<string>  $slugs
     * @return array<string,array<string,array{published:bool,classification:string,version:string|null,payload:array<string,mixed>|null}>>
     */
    public function publicationSnapshot(array $slugs): array
    {
        $this->primePublicationSnapshot($slugs);
        $snapshot = [];
        foreach (array_values(array_unique(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            $slugs,
        ))) as $slug) {
            if ($slug === '' || ! isset($this->publicationBySlugAndLocale[$slug])) {
                throw new \RuntimeException('Career quality publication snapshot is incomplete.');
            }
            $snapshot[$slug] = $this->publicationBySlugAndLocale[$slug];
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function localeEvidence(string $slug, string $locale): array
    {
        $blockers = [];
        $this->primePublicationSnapshot([$slug]);
        $publication = $this->publicationBySlugAndLocale[$slug][$locale] ?? null;
        $payload = is_array($publication) ? ($publication['payload'] ?? null) : null;
        if (! is_array($payload)
            || ! in_array($publication['classification'] ?? null, ['ready_active', 'ready_lkg'], true)
            || ($publication['published'] ?? false) !== true) {
            return $this->blockedLocale($slug, $locale, ['bilingual_detail_not_ready']);
        }

        if (strtolower(trim((string) data_get($payload, 'identity.canonical_slug', ''))) !== $slug) {
            $blockers[] = 'canonical_slug_mismatch';
        }
        $payloadLocale = strtolower(trim((string) data_get($payload, 'locale_policy.locale', '')));
        if (($locale === 'en' && ! in_array($payloadLocale, ['en', 'en-us'], true))
            || ($locale === 'zh-CN' && ! in_array($payloadLocale, ['zh', 'zh-cn'], true))) {
            $blockers[] = 'locale_mismatch';
        }

        $expectedPath = ($locale === 'en' ? '/en' : '/zh').'/career/jobs/'.$slug;
        $canonicalFields = [
            data_get($payload, 'seo_contract.canonical_url'),
            data_get($payload, 'seo_contract.canonical_target'),
            data_get($payload, 'seo_contract.canonical_path'),
        ];
        $canonical = $this->firstString($canonicalFields);
        if (! $this->canonicalFieldsMatch($canonicalFields, $expectedPath)) {
            $blockers[] = 'canonical_url_mismatch';
        }
        if (! $this->robotsIndexable(data_get($payload, 'seo_contract'))) {
            $blockers[] = 'robots_not_indexable';
        }

        $indexItem = $this->exactIndexItem($slug, $locale);
        if ($indexItem === null) {
            $blockers[] = 'index_item_missing_or_duplicate';
        } elseif (! $this->robotsIndexable($indexItem['seo_contract'] ?? null)) {
            $blockers[] = 'index_item_not_indexable';
        } elseif (! $this->canonicalFieldsMatch([
            data_get($indexItem, 'seo_contract.canonical_url'),
            data_get($indexItem, 'seo_contract.canonical_target'),
            data_get($indexItem, 'seo_contract.canonical_path'),
        ], $expectedPath)) {
            $blockers[] = 'index_item_canonical_mismatch';
        }

        $displayContent = data_get($payload, 'display_surface_v1.page.content');
        $visibleContent = is_array($displayContent) && $displayContent !== []
            ? $displayContent
            : [
                'content_sections' => $payload['content_sections'] ?? [],
                'content_body_md' => $payload['content_body_md'] ?? null,
            ];
        $visibleText = implode("\n", $this->visibleProseStrings($visibleContent));
        $visibleCharacters = mb_strlen(preg_replace('/\s+/u', '', $visibleText) ?? '');
        if ($visibleCharacters < self::MIN_VISIBLE_CHARACTERS) {
            $blockers[] = 'visible_content_too_thin';
        }

        $sources = $this->sourceReferences($payload);
        if ($sources === []) {
            $blockers[] = 'source_references_missing';
        }
        $claimBoundary = $this->claimBoundary($payload);
        if ($claimBoundary === []) {
            $blockers[] = 'claim_boundary_missing';
        }

        $faqItems = data_get($payload, 'display_surface_v1.page.content.faq_block.items');
        if (! is_array($faqItems) || $faqItems === []) {
            $blockers[] = 'visible_faq_missing';
            $faqItems = [];
        }
        $normalizedVisibleFaq = $this->visibleFaqItems($faqItems);
        $structuredFaq = $this->structuredFaqItems($payload, $locale);
        if ($normalizedVisibleFaq === []
            || $structuredFaq === []
            || $normalizedVisibleFaq !== $structuredFaq) {
            $blockers[] = 'faq_visible_content_mismatch';
        }

        $internalLinks = array_values(array_unique(array_filter(
            $this->hrefs($visibleContent),
            static fn (string $href): bool => str_starts_with($href, '/'),
        )));
        sort($internalLinks, SORT_STRING);
        $expectedPrefix = $locale === 'en' ? '/en/' : '/zh/';
        if ($internalLinks === []
            || array_any($internalLinks, static fn (string $href): bool => ! str_starts_with($href, $expectedPrefix))) {
            $blockers[] = 'internal_links_missing_or_cross_locale';
        }

        $qualityScore = min($visibleCharacters, 20000)
            + count($sources) * 100
            + count($faqItems) * 100
            + count($internalLinks) * 50;

        return [
            'locale' => $locale,
            'canonical_url' => $canonical ?? '',
            'visible_character_count' => $visibleCharacters,
            'visible_content_sha256' => hash('sha256', $this->canonicalizer->encode($visibleContent)),
            'source_references' => $sources,
            'source_summary_sha256' => hash('sha256', $this->canonicalizer->encode($sources)),
            'claim_boundary' => $claimBoundary,
            'claim_boundary_sha256' => hash('sha256', $this->canonicalizer->encode($claimBoundary)),
            'faq_count' => count($faqItems),
            'faq_visible_sha256' => hash('sha256', $this->canonicalizer->encode($faqItems)),
            'faq_structured_data_sha256' => hash('sha256', $this->canonicalizer->encode($structuredFaq)),
            'internal_links' => $internalLinks,
            'quality_score' => $qualityScore,
            'blockers' => array_values(array_unique($blockers)),
        ];
    }

    /** @return array<string,mixed>|null */
    private function exactIndexItem(string $slug, string $locale): ?array
    {
        if (! isset($this->indexItemsByLocaleAndSlug[$locale])) {
            $this->indexItemsByLocaleAndSlug[$locale] = [];
            $items = $this->responseCache->jobIndexPayload($locale)['items'] ?? null;
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $itemSlug = strtolower(trim((string) data_get($item, 'identity.canonical_slug', '')));
                    if ($itemSlug !== '') {
                        $this->indexItemsByLocaleAndSlug[$locale][$itemSlug][] = $item;
                    }
                }
            }
        }
        $matches = $this->indexItemsByLocaleAndSlug[$locale][$slug] ?? [];

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return list<array<string,mixed>> */
    private function sourceReferences(array $payload): array
    {
        foreach ([
            data_get($payload, 'display_surface_v1.sources'),
            data_get($payload, 'trust_manifest.source_trace'),
            data_get($payload, 'truth_layer.source_refs'),
        ] as $sources) {
            if (! is_array($sources) || $sources === []) {
                continue;
            }
            if (array_key_exists('references', $sources)) {
                $sources = $sources['references'];
                if (! is_array($sources) || $sources === []) {
                    continue;
                }
            }
            $normalized = [];
            foreach ($sources as $source) {
                if (is_string($source) && trim($source) !== '') {
                    $normalized[] = ['label' => trim($source)];
                } elseif (is_array($source)) {
                    $label = $this->nullableString($source['label'] ?? $source['source'] ?? $source['ref'] ?? null);
                    $url = $this->publicSourceUrl($source['url'] ?? null);
                    if ($label === null && $url === null) {
                        continue;
                    }
                    $public = array_filter([
                        'key' => $this->nullableString($source['key'] ?? null),
                        'label' => $label,
                        'url' => $url,
                        'usage' => $this->nullableString($source['usage'] ?? null),
                    ], static fn (mixed $value): bool => $value !== null);
                    $normalized[] = $public;
                }
            }
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /** @return list<array{question:string,answer:string}> */
    private function visibleFaqItems(array $items): array
    {
        $resolved = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                return [];
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                return [];
            }
            $resolved[] = ['question' => $question, 'answer' => $answer];
        }

        return $resolved;
    }

    /** @return list<array{question:string,answer:string}> */
    private function structuredFaqItems(array $payload, string $locale): array
    {
        $localeKey = $locale === 'en' ? 'en' : 'zh';
        $items = data_get(
            $payload,
            "display_surface_v1.structured_data_from_visible_content.faq_page.{$localeKey}.mainEntity",
        );
        if (! is_array($items)) {
            return [];
        }

        $resolved = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                return [];
            }
            $question = trim((string) ($item['name'] ?? $item['question'] ?? ''));
            $answer = trim((string) data_get($item, 'acceptedAnswer.text', ''));
            if ($question === '' || $answer === '') {
                return [];
            }
            $resolved[] = ['question' => $question, 'answer' => $answer];
        }

        return $resolved;
    }

    /** @return array<string,mixed> */
    private function claimBoundary(array $payload): array
    {
        $boundary = data_get($payload, 'display_surface_v1.claim_permissions');
        if (! is_array($boundary) || $boundary === []) {
            $boundary = $payload['claim_permissions'] ?? null;
        }
        if (! is_array($boundary)
            || ! array_any(array_keys($boundary), static fn (string|int $key): bool => is_string($key)
                && str_starts_with($key, 'allow_')
                && is_bool($boundary[$key]))) {
            return [];
        }

        return $boundary;
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

    /** @return list<string> */
    private function visibleProseStrings(mixed $value, ?string $field = null): array
    {
        if (is_string($value)) {
            return trim($value) === '' || ! in_array($field, self::VISIBLE_PROSE_FIELDS, true)
                ? []
                : [trim($value)];
        }
        if (! is_array($value)) {
            return [];
        }
        $strings = [];
        foreach ($value as $key => $child) {
            array_push(
                $strings,
                ...$this->visibleProseStrings($child, is_string($key) ? $key : $field),
            );
        }

        return $strings;
    }

    /** @return list<string> */
    private function hrefs(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $hrefs = [];
        foreach ($value as $key => $child) {
            if ($key === 'href' && is_string($child) && trim($child) !== '') {
                $hrefs[] = trim($child);
            } elseif (is_array($child)) {
                array_push($hrefs, ...$this->hrefs($child));
            }
        }

        return $hrefs;
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->nullableString($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function canonicalFieldsMatch(array $values, string $expectedPath): bool
    {
        $canonicals = array_values(array_filter(
            array_map($this->nullableString(...), $values),
            static fn (?string $value): bool => $value !== null,
        ));

        return $canonicals !== []
            && array_all($canonicals, static fn (string $value): bool => $value === $expectedPath);
    }

    private function publicSourceUrl(mixed $value): ?string
    {
        $url = $this->nullableString($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && is_string(parse_url($url, PHP_URL_HOST))
            ? $url
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @return array<string,mixed> */
    private function blocked(string $slug, array $blockers): array
    {
        return [
            'canonical_slug' => $slug,
            'pool_rank' => 0,
            'publish_track' => '',
            'content_quality_tier' => 'ineligible',
            'target_search_entry_tier' => CareerSearchEntryTierResolver::TIER_INELIGIBLE,
            'quality_score' => 0,
            'canonical_urls' => ['en' => '', 'zh-CN' => ''],
            'locales' => [],
            'blockers' => $blockers,
        ];
    }

    /** @return array<string,mixed> */
    private function blockedLocale(string $slug, string $locale, array $blockers): array
    {
        return [
            'locale' => $locale,
            'canonical_url' => ($locale === 'en' ? '/en' : '/zh').'/career/jobs/'.$slug,
            'visible_character_count' => 0,
            'visible_content_sha256' => '',
            'source_references' => [],
            'source_summary_sha256' => '',
            'claim_boundary' => [],
            'claim_boundary_sha256' => '',
            'faq_count' => 0,
            'faq_visible_sha256' => '',
            'faq_structured_data_sha256' => '',
            'internal_links' => [],
            'quality_score' => 0,
            'blockers' => $blockers,
        ];
    }
}
