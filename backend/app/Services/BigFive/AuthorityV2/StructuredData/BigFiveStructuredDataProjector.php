<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV2\StructuredData;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Services\BigFive\AuthorityV2\VisibleDate\BigFiveVisibleDateProjector;
use App\Services\BigFive\AuthorityV2\VisibleProvenance\BigFiveVisibleProvenanceProjector;
use App\Services\Career\StructuredData\CareerArticleStructuredDataBuilder;

final class BigFiveStructuredDataProjector
{
    public const CONTRACT_VERSION = 'big5-structured-data.v1';

    public function __construct(
        private readonly BigFiveVisibleDateProjector $visibleDateProjector,
        private readonly BigFiveVisibleProvenanceProjector $visibleProvenanceProjector,
        private readonly CareerArticleStructuredDataBuilder $articleStructuredDataBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function forArticle(
        Article $article,
        ?ArticleTranslationRevision $revision,
        array $context,
    ): array {
        $dates = $this->visibleDateProjector->forArticle($article, $revision);
        $provenance = $this->visibleProvenanceProjector->forArticle($article, $revision);
        $metadata = is_array($context['editorial_package'] ?? null)
            ? $context['editorial_package']
            : [];
        $canonical = $this->absoluteUrl($context['canonical'] ?? null);
        $seoIndexable = ! is_bool($context['seo_indexable'] ?? null)
            || $context['seo_indexable'] === true;
        $robots = strtolower(trim((string) ($context['robots'] ?? '')));
        $robotsIndexable = $robots === '' || ! str_contains($robots, 'noindex');
        $currentAuthority = (string) $article->status === 'published'
            && (bool) $article->is_public
            && (bool) $article->is_indexable
            && $seoIndexable
            && $robotsIndexable
            && $canonical !== null
            && (bool) data_get($dates, 'eligibility.published_date_eligible', false)
            && (bool) data_get($provenance, 'eligibility.promotion_eligible', false);

        $blocked = [];
        if (! $currentAuthority) {
            $blocked[] = 'current_visible_reviewed_authority_missing';
        }
        if ($canonical === null) {
            $blocked[] = 'canonical_public_url_missing';
        }
        if (! $seoIndexable || ! $robotsIndexable) {
            $blocked[] = 'seo_noindex_authority';
        }

        $structured = $currentAuthority
            ? $this->articleStructuredDataBuilder->build('article_public_detail', [
                'id' => $canonical.'#article',
                'headline' => $context['headline'] ?? null,
                'description' => $context['description'] ?? null,
                'url' => $canonical,
                'main_entity_of_page' => $canonical,
                'breadcrumb_root_url' => $context['breadcrumb_root_url'] ?? null,
                'image' => $context['image'] ?? null,
                'date_published' => data_get($dates, 'visible_dates.published_at'),
                'date_modified' => data_get($dates, 'visible_dates.updated_at'),
                'article_section' => $context['article_section'] ?? null,
                'author_name' => data_get($provenance, 'visible_provenance.author.label'),
                'keywords' => $context['keywords'] ?? null,
            ])
            : null;

        $articleEnabled = $currentAuthority
            && ($metadata['article_schema_enabled'] ?? null) === true
            && is_array(data_get($structured, 'fragments.article'));
        $breadcrumbEnabled = $currentAuthority
            && ($metadata['breadcrumb_schema_enabled'] ?? null) === true
            && is_array(data_get($structured, 'fragments.breadcrumb_list'));
        $faq = $this->visibleFaq($metadata, $canonical);
        $faqEnabled = $currentAuthority
            && ($metadata['faq_schema_enabled'] ?? null) === true
            && $faq !== null;

        $articleFragment = $articleEnabled ? data_get($structured, 'fragments.article') : null;
        if (is_array($articleFragment)) {
            $citations = collect((array) data_get($provenance, 'visible_provenance.sources', []))
                ->pluck('label')
                ->filter(static fn (mixed $label): bool => is_string($label) && trim($label) !== '')
                ->map(static fn (string $label): string => trim($label))
                ->unique()
                ->values()
                ->all();
            if ($citations !== []) {
                $articleFragment['citation'] = $citations;
            }
            if ($faqEnabled) {
                $articleFragment['hasPart'] = [$faq];
            }
        }

        foreach ([
            'article' => $metadata['article_schema_enabled'] ?? null,
            'breadcrumb_list' => $metadata['breadcrumb_schema_enabled'] ?? null,
            'faq_page' => $metadata['faq_schema_enabled'] ?? null,
        ] as $surface => $gate) {
            if ($gate !== true) {
                $blocked[] = $surface.'_explicit_gate_missing';
            }
        }
        if (($metadata['faq_schema_enabled'] ?? null) === true && $faq === null) {
            $blocked[] = 'visible_faq_authority_missing';
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'authority_surface' => 'Article',
            'identity' => 'article:'.(int) $article->id.':'.(string) $article->locale.':'.(string) $article->slug,
            'current_public_authority_eligible' => $currentAuthority,
            'visible_alignment' => [
                'canonical' => $canonical,
                'author' => data_get($provenance, 'visible_provenance.author'),
                'reviewer_gate' => data_get($provenance, 'visible_provenance.reviewer'),
                'sources' => data_get($provenance, 'visible_provenance.sources', []),
                'dates' => data_get($dates, 'visible_dates', []),
            ],
            'eligibility' => [
                'article' => ['enabled' => $articleEnabled],
                'breadcrumb_list' => ['enabled' => $breadcrumbEnabled],
                'faq_page' => ['enabled' => $faqEnabled],
                'blocked_reasons' => array_values(array_unique($blocked)),
            ],
            'fragments' => [
                'article' => $articleFragment,
                'breadcrumb_list' => $breadcrumbEnabled
                    ? data_get($structured, 'fragments.breadcrumb_list')
                    : null,
                'faq_page' => $faqEnabled ? $faq : null,
            ],
            'preservation' => [
                'read_only_projection' => true,
                'frontend_inference_required' => false,
                'raw_schema_json_bypass_allowed' => false,
                'reviewer_is_eligibility_gate_not_article_property' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed>|null */
    private function visibleFaq(array $metadata, ?string $canonical): ?array
    {
        $policy = strtolower(trim((string) ($metadata['answer_surface_policy'] ?? '')));
        $visibility = strtolower(trim((string) ($metadata['answer_surface_visibility'] ?? '')));
        if ($policy !== 'editor_supplied'
            || $visibility === ''
            || in_array($visibility, ['disabled', 'hidden', 'private'], true)) {
            return null;
        }

        $items = data_get($metadata, 'answer_surface_v1.faq_items');
        if (! is_array($items)) {
            return null;
        }

        $entities = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ($item['hidden'] ?? false) === true
                || ($item['is_visible'] ?? true) === false
                || in_array(strtolower((string) ($item['visibility'] ?? 'visible')), ['hidden', 'disabled', 'private'], true)) {
                continue;
            }
            $question = $this->text($item['question'] ?? $item['q'] ?? null);
            $answer = $this->text($item['answer'] ?? $item['a'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
            if (count($entities) >= 8) {
                break;
            }
        }

        return $entities === [] || $canonical === null ? null : [
            '@type' => 'FAQPage',
            '@id' => $canonical.'#faq',
            'mainEntity' => $entities,
        ];
    }

    private function absoluteUrl(mixed $value): ?string
    {
        $url = $this->text($value);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ? $url
            : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
