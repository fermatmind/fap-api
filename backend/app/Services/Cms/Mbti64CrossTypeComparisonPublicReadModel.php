<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Services\ReviewGovernance\PublicReviewContract;
use App\Support\CanonicalFrontendUrl;
use Illuminate\Support\Facades\File;

/** @review-surface mbti_cross_type_comparison_authority */
final class Mbti64CrossTypeComparisonPublicReadModel
{
    private const SOURCE_DIR = 'docs/seo/import-packages/mbti-cross-type-comparison-content-assets-draft-20260702';

    private const CONTRACT_VERSION = 'mbti.cross_type_comparison.public.v1';

    private const AUTHORITY_CONTRACT_VERSION = 'mbti.cross_type_comparison.authority.v1';

    private const READMODEL_CONTRACT_VERSION = 'mbti.cross_type_comparison.readmodel.v1';

    private const COMPARISON_TYPE = 'mbti_cross_type';

    private const LOCALE = 'zh-CN';

    /**
     * @var array<string,array<string,mixed>>|null
     */
    private ?array $assetsBySlug = null;

    public function __construct(
        private readonly Mbti64CrossTypeComparisonAssetsDryRunPlanner $planner,
        private readonly PublicReviewContract $publicReviewContract,
    ) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function list(string $locale): array
    {
        if ($locale !== self::LOCALE) {
            return [];
        }

        $items = [];
        foreach ($this->assetsBySlug() as $asset) {
            $item = $this->listItem($asset, $locale);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        usort($items, static fn (array $left, array $right): int => strcmp((string) $left['slug'], (string) $right['slug']));

        return $items;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $slug, string $locale): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        if ($locale !== self::LOCALE || ! $this->isCrossTypeSlug($normalizedSlug)) {
            return null;
        }

        $asset = $this->assetsBySlug()[$normalizedSlug] ?? null;
        if (! is_array($asset)) {
            return null;
        }

        return $this->detail($asset, $locale);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function assetsBySlug(): array
    {
        if ($this->assetsBySlug !== null) {
            return $this->assetsBySlug;
        }

        $authorityAssets = $this->authorityAssetsBySlug();
        $plan = $this->planner->planSourceDir(self::SOURCE_DIR);
        if (($plan['ok'] ?? false) !== true) {
            return $this->assetsBySlug = $authorityAssets;
        }

        $assets = [];
        foreach ((array) ($plan['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $slug = $this->stringValue($row['slug'] ?? null);
            $sourceFile = $this->stringValue($row['source_file'] ?? null);
            if ($slug === null || $sourceFile === null) {
                continue;
            }

            $path = base_path($sourceFile);
            if (! File::isFile($path)) {
                continue;
            }

            $asset = json_decode((string) File::get($path), true);
            if (! is_array($asset) || $this->stringValue($asset['slug'] ?? null) !== $slug) {
                continue;
            }

            $asset['_source_sha256'] = (string) ($row['source_sha256'] ?? hash('sha256', (string) File::get($path)));
            $assets[$slug] = $asset;
        }

        ksort($assets);
        $assets = array_merge($assets, $authorityAssets);
        ksort($assets);

        return $this->assetsBySlug = $assets;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function authorityAssetsBySlug(): array
    {
        $assets = [];
        $rows = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', self::LOCALE)
            ->where('comparison_type', self::COMPARISON_TYPE)
            ->where('is_public', true)
            ->orderBy('slug')
            ->get();

        foreach ($rows as $row) {
            if (! $row instanceof MbtiCrossTypeComparisonAuthority) {
                continue;
            }

            $asset = $this->assetFromAuthority($row);
            if ($asset === null) {
                continue;
            }

            $assets[(string) $asset['slug']] = $asset;
        }

        return $assets;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function assetFromAuthority(MbtiCrossTypeComparisonAuthority $authority): ?array
    {
        $slug = $this->stringValue($authority->slug);
        if ($slug === null || ! $this->isCrossTypeSlug($slug)) {
            return null;
        }

        $leftType = strtoupper((string) $authority->left_type_code);
        $rightType = strtoupper((string) $authority->right_type_code);
        if (! in_array($leftType, PersonalityProfile::BASE_TYPE_CODES, true)
            || ! in_array($rightType, PersonalityProfile::BASE_TYPE_CODES, true)
            || $leftType === $rightType
        ) {
            return null;
        }

        $payload = is_array($authority->content_payload_json) ? $authority->content_payload_json : [];

        return [
            '_authority_source' => 'database',
            '_is_public' => (bool) $authority->is_public,
            '_is_indexable' => (bool) $authority->is_indexable,
            '_sitemap_eligible' => (bool) $authority->sitemap_eligible,
            '_llms_eligible' => (bool) $authority->llms_eligible,
            '_source_sha256' => (string) ($authority->source_sha256 ?? ''),
            'slug' => $slug,
            'comparison_type' => self::COMPARISON_TYPE,
            'locale' => (string) $authority->locale,
            'left_type' => $leftType,
            'right_type' => $rightType,
            'title' => (string) $authority->title,
            'seo_title' => (string) $authority->seo_title,
            'seo_description' => (string) $authority->seo_description,
            'summary' => (string) $authority->summary,
            'sections' => is_array($payload['sections'] ?? null) ? $payload['sections'] : [],
            'faq' => is_array($payload['faq'] ?? null) ? $payload['faq'] : [],
            'internal_links' => is_array($payload['internal_links'] ?? null) ? $payload['internal_links'] : [],
            'claim_boundary' => (string) ($authority->claim_boundary ?: ($payload['claim_boundary'] ?? '')),
            'source_notes' => is_array($payload['source_notes'] ?? null) ? $payload['source_notes'] : [],
            'source_package_id' => (string) ($authority->source_package_id ?? ''),
            'review_status' => (string) $authority->review_status,
            ...$this->publicReviewContract->project($authority->review_status),
            'publish_status' => (string) $authority->publish_status,
            'indexability_status' => (string) $authority->indexability_status,
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>|null
     */
    private function listItem(array $asset, string $locale): ?array
    {
        $slug = $this->stringValue($asset['slug'] ?? null);
        if ($slug === null || ! $this->isCrossTypeSlug($slug)) {
            return null;
        }

        $leftType = strtoupper((string) ($asset['left_type'] ?? ''));
        $rightType = strtoupper((string) ($asset['right_type'] ?? ''));
        if (! in_array($leftType, PersonalityProfile::BASE_TYPE_CODES, true)
            || ! in_array($rightType, PersonalityProfile::BASE_TYPE_CODES, true)
            || $leftType === $rightType
        ) {
            return null;
        }

        return [
            'slug' => $slug,
            'comparison_type' => self::COMPARISON_TYPE,
            'left_type' => $leftType,
            'right_type' => $rightType,
            'base_type_codes' => [$leftType, $rightType],
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'locale' => $locale,
            'public_route_type' => 'cross-type-comparison',
            'title' => (string) $asset['title'],
            'seo_title' => (string) $asset['seo_title'],
            'description' => (string) $asset['seo_description'],
            'summary' => (string) $asset['summary'],
            'public_url' => $this->canonicalUrl($slug, $locale),
            'canonical_url' => $this->canonicalUrl($slug, $locale),
            'is_public' => (bool) ($asset['_is_public'] ?? true),
            'is_indexable' => (bool) ($asset['_is_indexable'] ?? false),
            'sitemap_eligible' => (bool) ($asset['_sitemap_eligible'] ?? false),
            'llms_eligible' => (bool) ($asset['_llms_eligible'] ?? false),
            'status' => 'authority_ready',
            'review_status' => (string) $asset['review_status'],
            ...$this->publicReviewContract->project($asset['review_status']),
            'publish_status' => (string) $asset['publish_status'],
            'indexability_status' => (string) $asset['indexability_status'],
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function detail(array $asset, string $locale): array
    {
        $slug = (string) $asset['slug'];
        $leftType = strtoupper((string) $asset['left_type']);
        $rightType = strtoupper((string) $asset['right_type']);
        $sections = $this->sections($asset);
        $faq = $this->faq($asset);
        $internalLinks = $this->internalLinks($asset);
        $canonicalUrl = $this->canonicalUrl($slug, $locale);

        return [
            'comparison_contract_version' => self::CONTRACT_VERSION,
            'authority_contract_version' => self::AUTHORITY_CONTRACT_VERSION,
            'readmodel_contract_version' => self::READMODEL_CONTRACT_VERSION,
            'comparison_slug' => $slug,
            'comparison_type' => self::COMPARISON_TYPE,
            'public_route_type' => 'cross-type-comparison',
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'locale' => $locale,
            'left_type' => $leftType,
            'right_type' => $rightType,
            'base_type_codes' => [$leftType, $rightType],
            'title' => (string) $asset['title'],
            'description' => (string) $asset['seo_description'],
            'seo_title' => (string) $asset['seo_title'],
            'seo_description' => (string) $asset['seo_description'],
            'summary' => (string) $asset['summary'],
            'canonical_url' => $canonicalUrl,
            'alternates' => [
                self::LOCALE => $canonicalUrl,
            ],
            'sections' => $sections,
            'faq' => $faq,
            'internal_links' => $internalLinks,
            'claim_boundary' => (string) $asset['claim_boundary'],
            'source_notes' => $this->sourceNotes($asset),
            'source_refs' => [
                (string) ($asset['source_package_id'] ?? 'mbti-cross-type-comparison-content-assets-draft-20260702'),
                self::AUTHORITY_CONTRACT_VERSION,
                self::READMODEL_CONTRACT_VERSION,
            ],
            'source_sha256' => (string) ($asset['_source_sha256'] ?? ''),
            'is_public' => (bool) ($asset['_is_public'] ?? true),
            'is_indexable' => (bool) ($asset['_is_indexable'] ?? false),
            'sitemap_eligible' => (bool) ($asset['_sitemap_eligible'] ?? false),
            'llms_eligible' => (bool) ($asset['_llms_eligible'] ?? false),
            'review_status' => (string) $asset['review_status'],
            ...$this->publicReviewContract->project($asset['review_status']),
            'publish_status' => (string) $asset['publish_status'],
            'indexability_status' => (string) $asset['indexability_status'],
        ];
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<array<string,mixed>>
     */
    private function sections(array $asset): array
    {
        $sections = [];
        foreach (array_values((array) ($asset['sections'] ?? [])) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $id = $this->stringValue($section['id'] ?? null) ?? 'section-'.($index + 1);
            $id = $this->stringValue($section['key'] ?? null) ?? $id;
            $title = $this->stringValue($section['title'] ?? null);
            $bodySource = $section['body'] ?? [];
            $body = array_values(array_filter(array_map(
                fn (mixed $line): ?string => $this->stringValue($line),
                is_array($bodySource) ? $bodySource : [$bodySource]
            )));
            $rows = is_array($section['rows'] ?? null) ? array_values((array) $section['rows']) : [];

            if ($title === null || ($body === [] && $rows === [])) {
                continue;
            }

            $projection = [
                'id' => $id,
                'title' => $title,
                'body' => $body,
            ];

            if ($rows !== []) {
                $projection['rows'] = $rows;
            }

            $sections[] = $projection;
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<array<string,string>>
     */
    private function faq(array $asset): array
    {
        $faq = [];
        foreach (array_values((array) ($asset['faq'] ?? [])) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->stringValue($item['question'] ?? null);
            $answer = $this->stringValue($item['answer'] ?? null);
            if ($question === null || $answer === null) {
                continue;
            }

            $faq[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $faq;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<array<string,string>>
     */
    private function internalLinks(array $asset): array
    {
        $links = [];
        foreach (array_values((array) ($asset['internal_links'] ?? [])) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $href = $this->stringValue($link['href'] ?? null);
            $anchor = $this->stringValue($link['anchor_text'] ?? null);
            if ($href === null || $anchor === null || ! $this->isSafePublicHref($href)) {
                continue;
            }

            $links[] = [
                'href' => $href,
                'anchor_text' => $anchor,
                'link_intent' => $this->stringValue($link['link_intent'] ?? null) ?? 'related_public_page',
            ];
        }

        return $links;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function sourceNotes(array $asset): array
    {
        $sourceNotes = $asset['source_notes'] ?? [];

        return is_array($sourceNotes) ? $sourceNotes : [];
    }

    private function canonicalUrl(string $slug, string $locale): ?string
    {
        $baseUrl = CanonicalFrontendUrl::fromConfig();
        if ($baseUrl === '') {
            return null;
        }

        return $baseUrl.'/'.$this->frontendLocaleSegment($locale).'/personality/'.$slug;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    private function isCrossTypeSlug(string $slug): bool
    {
        if (preg_match('/^(?<left>[a-z]{4})-vs-(?<right>[a-z]{4})$/', $slug, $matches) !== 1) {
            return false;
        }

        return $matches['left'] !== $matches['right'];
    }

    private function isSafePublicHref(string $href): bool
    {
        if (! str_starts_with($href, '/')) {
            return false;
        }

        if (preg_match('~^/(?:[a-z]{2}(?:-[A-Z]{2})?/)?(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#]|$)~i', $href) === 1) {
            return false;
        }

        if (preg_match('/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i', $href) === 1) {
            return false;
        }

        return true;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
