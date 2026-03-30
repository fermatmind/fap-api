<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\ContentGovernance;
use App\Models\DataPage;
use App\Models\DataPageSeoMeta;
use App\Models\MethodPage;
use App\Models\MethodPageSeoMeta;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;
use App\Services\Ops\SeoQualityAuditService;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ContentPublishGateService
{
    /**
     * @return list<string>
     */
    public static function missing(string $type, object $record): array
    {
        self::loadGateRelations($type, $record);

        $missing = [];

        if (! filled(data_get($record, 'title'))) {
            $missing[] = 'title';
        }

        if (! filled(data_get($record, 'slug'))) {
            $missing[] = 'slug';
        }

        if (! self::hasNarrativeBody($type, $record)) {
            $missing[] = 'body';
        }

        if (! filled(data_get($record, 'excerpt'))) {
            $missing[] = 'excerpt';
        }

        $seoMeta = self::seoMeta($record);
        foreach ([
            'seo_title' => 'seo title',
            'seo_description' => 'seo description',
            'canonical_url' => 'canonical url',
            'og_title' => 'og title',
            'og_description' => 'og description',
            'og_image_url' => 'og image',
            'robots' => 'robots',
        ] as $field => $label) {
            if (! filled(data_get($seoMeta, $field))) {
                $missing[] = $label;
            }
        }

        $governance = self::governance($record);
        if (! $governance instanceof ContentGovernance) {
            $missing[] = 'governance';
        }

        $pageType = self::pageType($record, $governance);

        if (! filled(data_get($governance, 'primary_query'))) {
            $missing[] = 'primary query';
        }

        if (! filled(data_get($governance, 'cta_stage'))) {
            $missing[] = 'cta stage';
        }

        if (! self::hasAuthor($record, $governance)) {
            $missing[] = 'author';
        }

        if (! self::hasReviewer($type, $record, $governance)) {
            $missing[] = 'reviewer';
        }

        if (! self::hasCanonicalTarget($governance, $seoMeta)) {
            $missing[] = 'canonical target';
        }

        if (! self::isIndexable($record, $seoMeta)) {
            $missing[] = 'indexability';
        }

        foreach (self::missingBindingsForPageType($pageType, $governance) as $label) {
            $missing[] = $label;
        }

        if (self::internalLinkCount($record, $governance) < self::minimumInternalLinks($pageType)) {
            $missing[] = 'minimum internal links';
        }

        if (self::hasSchemaConsistencyViolations($type, $record, $pageType, $seoMeta)) {
            $missing[] = 'schema consistency';
        }

        if ($type === 'data' && $record instanceof DataPage && ! app(SeoQualityAuditService::class)->hasPassingCitationQa($record)) {
            $missing[] = 'citation qa';
        }

        return array_values(array_unique($missing));
    }

    public static function assertReadyForRelease(string $type, object $record): void
    {
        $missing = self::missing($type, $record);
        if ($missing !== []) {
            throw new InvalidArgumentException('Publish gate failed: '.implode(', ', $missing).'.');
        }

        if ((EditorialReviewAudit::latestState($type, $record)['state'] ?? null) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new InvalidArgumentException('Publish gate failed: editorial review approval required.');
        }
    }

    private static function loadGateRelations(string $type, object $record): void
    {
        if (! $record instanceof Model || ! method_exists($record, 'loadMissing')) {
            return;
        }

        $relations = ['seoMeta', 'governance'];
        if ($type === 'personality') {
            $relations[] = 'sections';
        }

        if ($type === 'topic') {
            $relations[] = 'sections';
            $relations[] = 'entries';
        }

        $record->loadMissing($relations);
    }

    private static function hasNarrativeBody(string $type, object $record): bool
    {
        return match ($type) {
            'article' => filled(data_get($record, 'content_md')),
            'guide', 'job' => filled(data_get($record, 'body_md')),
            'method' => filled(data_get($record, 'body_md')) || filled(data_get($record, 'definition_summary_md')),
            'data' => filled(data_get($record, 'body_md')) || filled(data_get($record, 'summary_statement_md')),
            'personality' => filled(data_get($record, 'hero_summary_md')) || self::hasEnabledSectionBody($record),
            'topic' => self::hasEnabledSectionBody($record) || self::hasEnabledEntries($record),
            default => false,
        };
    }

    private static function hasEnabledSectionBody(object $record): bool
    {
        $sections = data_get($record, 'sections', []);
        if (! is_iterable($sections)) {
            return false;
        }

        foreach ($sections as $section) {
            if (! data_get($section, 'is_enabled', false)) {
                continue;
            }

            if (filled(data_get($section, 'body_md')) || filled(data_get($section, 'body_html')) || self::filledPayload(data_get($section, 'payload_json'))) {
                return true;
            }
        }

        return false;
    }

    private static function hasEnabledEntries(object $record): bool
    {
        $entries = data_get($record, 'entries', []);
        if (! is_iterable($entries)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (! data_get($entry, 'is_enabled', false)) {
                continue;
            }

            if (
                filled(data_get($entry, 'target_key'))
                || filled(data_get($entry, 'target_url_override'))
                || self::filledPayload(data_get($entry, 'payload_json'))
            ) {
                return true;
            }
        }

        return false;
    }

    private static function filledPayload(mixed $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ($payload as $value) {
            if (is_array($value) && self::filledPayload($value)) {
                return true;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function governance(object $record): ?ContentGovernance
    {
        $governance = data_get($record, 'governance');

        return $governance instanceof ContentGovernance ? $governance : null;
    }

    private static function seoMeta(object $record): ?object
    {
        $seoMeta = data_get($record, 'seoMeta');

        return is_object($seoMeta) ? $seoMeta : null;
    }

    private static function pageType(object $record, ?ContentGovernance $governance): string
    {
        if ($governance instanceof ContentGovernance && filled($governance->page_type)) {
            return (string) $governance->page_type;
        }

        if ($record instanceof Model) {
            return (string) (ContentGovernanceService::stateFromRecord($record)['page_type'] ?? ContentGovernanceService::PAGE_TYPE_GUIDE);
        }

        return ContentGovernanceService::PAGE_TYPE_GUIDE;
    }

    private static function hasAuthor(object $record, ?ContentGovernance $governance): bool
    {
        if (is_numeric(data_get($governance, 'author_admin_user_id')) && (int) data_get($governance, 'author_admin_user_id') > 0) {
            return true;
        }

        foreach (['author_admin_user_id', 'created_by_admin_user_id', 'updated_by_admin_user_id'] as $key) {
            if (is_numeric(data_get($record, $key)) && (int) data_get($record, $key) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function hasReviewer(string $type, object $record, ?ContentGovernance $governance): bool
    {
        if (is_numeric(data_get($governance, 'reviewer_admin_user_id')) && (int) data_get($governance, 'reviewer_admin_user_id') > 0) {
            return true;
        }

        return (int) (EditorialReviewAudit::latestState($type, $record)['reviewer_admin_user_id'] ?? 0) > 0;
    }

    private static function hasCanonicalTarget(?ContentGovernance $governance, ?object $seoMeta): bool
    {
        return filled(data_get($governance, 'canonical_target')) || filled(data_get($seoMeta, 'canonical_url'));
    }

    private static function isIndexable(object $record, ?object $seoMeta): bool
    {
        if (! (bool) data_get($record, 'is_indexable', false)) {
            return false;
        }

        $robots = strtolower(trim((string) data_get($seoMeta, 'robots', '')));
        if ($robots === '' || str_contains($robots, 'noindex')) {
            return false;
        }

        if ($seoMeta instanceof ArticleSeoMeta && ! (bool) $seoMeta->is_indexable) {
            return false;
        }

        return true;
    }

    private static function hasSchemaConsistencyViolations(string $type, object $record, string $pageType, ?object $seoMeta): bool
    {
        if (! $record instanceof Model) {
            return true;
        }

        if (self::protectedOverrideViolations($seoMeta) !== []) {
            return true;
        }

        $schema = self::buildSchemaForType($type, $record);
        if (! is_array($schema) || $schema === []) {
            return true;
        }

        if ((string) data_get($schema, '@type') !== SeoSchemaPolicyService::expectedSchemaTypeForPageType($pageType)) {
            return true;
        }

        $canonical = trim((string) data_get($seoMeta, 'canonical_url', ''));
        if ($canonical !== '') {
            if ((string) data_get($schema, 'url') !== $canonical) {
                return true;
            }

            if ((string) data_get($schema, 'mainEntityOfPage') !== $canonical) {
                return true;
            }
        }

        $visibleTitle = trim((string) data_get($record, 'title', ''));
        if ($visibleTitle !== '') {
            $schemaTitleKey = self::isArticleLikePageType($pageType) ? 'headline' : 'name';
            if (trim((string) data_get($schema, $schemaTitleKey, '')) !== $visibleTitle) {
                return true;
            }
        }

        $visibleDescription = self::visibleDescription($record, $pageType);
        if ($visibleDescription !== '' && trim((string) data_get($schema, 'description', '')) !== $visibleDescription) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function protectedOverrideViolations(?object $seoMeta): array
    {
        if ($seoMeta instanceof ArticleSeoMeta) {
            return SeoSchemaPolicyService::protectedOverrideViolations($seoMeta->schema_json);
        }

        if (
            $seoMeta instanceof CareerGuideSeoMeta
            || $seoMeta instanceof CareerJobSeoMeta
            || $seoMeta instanceof MethodPageSeoMeta
            || $seoMeta instanceof DataPageSeoMeta
            || $seoMeta instanceof PersonalityProfileSeoMeta
            || $seoMeta instanceof TopicProfileSeoMeta
        ) {
            return SeoSchemaPolicyService::protectedOverrideViolations($seoMeta->jsonld_overrides_json);
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildSchemaForType(string $type, Model $record): ?array
    {
        return match ($type) {
            'article' => $record instanceof Article ? app(ArticleSeoService::class)->generateJsonLd($record) : null,
            'guide' => $record instanceof CareerGuide ? app(CareerGuideSeoService::class)->buildJsonLd($record) : null,
            'job' => $record instanceof CareerJob ? app(CareerJobSeoService::class)->buildJsonLd($record, (string) $record->locale) : null,
            'method' => $record instanceof MethodPage ? app(MethodPageSeoService::class)->buildJsonLd($record, (string) $record->locale) : null,
            'data' => $record instanceof DataPage ? app(DataPageSeoService::class)->buildJsonLd($record, (string) $record->locale) : null,
            'personality' => $record instanceof PersonalityProfile ? app(PersonalityProfileSeoService::class)->buildJsonLd($record) : null,
            'topic' => $record instanceof TopicProfile ? app(TopicProfileSeoService::class)->buildJsonLd($record, (string) $record->locale) : null,
            default => null,
        };
    }

    private static function isArticleLikePageType(string $pageType): bool
    {
        return in_array($pageType, [
            ContentGovernanceService::PAGE_TYPE_GUIDE,
            ContentGovernanceService::PAGE_TYPE_METHOD,
            ContentGovernanceService::PAGE_TYPE_DATA,
        ], true);
    }

    private static function visibleDescription(Model $record, string $pageType): string
    {
        if ($pageType === ContentGovernanceService::PAGE_TYPE_ENTITY && $record instanceof PersonalityProfile) {
            return trim((string) ($record->excerpt ?? $record->hero_summary_md ?? ''));
        }

        if ($pageType === ContentGovernanceService::PAGE_TYPE_DATA && $record instanceof DataPage) {
            return trim((string) ($record->excerpt ?? $record->summary_statement_md ?? ''));
        }

        return trim((string) data_get($record, 'excerpt', ''));
    }

    /**
     * @return list<string>
     */
    private static function missingBindingsForPageType(string $pageType, ?ContentGovernance $governance): array
    {
        $requirements = match ($pageType) {
            ContentGovernanceService::PAGE_TYPE_HUB => [
                'method_binding' => 'method binding',
                'test_binding' => 'test binding',
            ],
            ContentGovernanceService::PAGE_TYPE_TEST => [
                'hub_ref' => 'hub binding',
                'method_binding' => 'method binding',
            ],
            ContentGovernanceService::PAGE_TYPE_METHOD => [
                'hub_ref' => 'hub binding',
                'test_binding' => 'test binding',
            ],
            default => [
                'hub_ref' => 'hub binding',
                'test_binding' => 'test binding',
                'method_binding' => 'method binding',
            ],
        };

        $missing = [];
        foreach ($requirements as $field => $label) {
            if (! filled(data_get($governance, $field))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private static function minimumInternalLinks(string $pageType): int
    {
        return match ($pageType) {
            ContentGovernanceService::PAGE_TYPE_HUB,
            ContentGovernanceService::PAGE_TYPE_TEST,
            ContentGovernanceService::PAGE_TYPE_METHOD => 2,
            default => 3,
        };
    }

    private static function internalLinkCount(object $record, ?ContentGovernance $governance): int
    {
        $targets = [];

        foreach (['hub_ref', 'test_binding', 'method_binding'] as $field) {
            $value = trim((string) data_get($governance, $field, ''));
            if ($value !== '') {
                $targets[$field.':'.$value] = true;
            }
        }

        foreach (self::internalLinksFromContent($record) as $href) {
            $targets['content:'.$href] = true;
        }

        $entries = data_get($record, 'entries', []);
        if (is_iterable($entries)) {
            foreach ($entries as $entry) {
                if (! data_get($entry, 'is_enabled', false)) {
                    continue;
                }

                $target = trim((string) (data_get($entry, 'target_url_override') ?: data_get($entry, 'target_key', '')));
                if ($target !== '') {
                    $targets['entry:'.$target] = true;
                }
            }
        }

        return count($targets);
    }

    /**
     * @return list<string>
     */
    private static function internalLinksFromContent(object $record): array
    {
        $chunks = [];

        foreach ([
            data_get($record, 'content_md'),
            data_get($record, 'body_md'),
            data_get($record, 'hero_summary_md'),
        ] as $chunk) {
            if (is_scalar($chunk)) {
                $chunks[] = (string) $chunk;
            }
        }

        foreach ((array) data_get($record, 'sections', []) as $section) {
            foreach ([data_get($section, 'body_md'), data_get($section, 'body_html')] as $chunk) {
                if (is_scalar($chunk)) {
                    $chunks[] = (string) $chunk;
                }
            }
        }

        $links = [];
        foreach ($chunks as $chunk) {
            if ($chunk === '') {
                continue;
            }

            if (preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $chunk, $matches) === 1 || (isset($matches[1]) && $matches[1] !== [])) {
                foreach ($matches[1] as $href) {
                    $normalized = self::normalizeInternalHref((string) $href);
                    if ($normalized !== null) {
                        $links[] = $normalized;
                    }
                }
            }

            if (preg_match_all('#https?://[^\\s)"]+#', $chunk, $absoluteMatches) === 1 || (isset($absoluteMatches[0]) && $absoluteMatches[0] !== [])) {
                foreach ($absoluteMatches[0] as $href) {
                    $normalized = self::normalizeInternalHref((string) $href);
                    if ($normalized !== null) {
                        $links[] = $normalized;
                    }
                }
            }
        }

        return array_values(array_unique($links));
    }

    private static function normalizeInternalHref(string $href): ?string
    {
        $normalized = trim($href);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (! preg_match('#^https?://#i', $normalized)) {
            return null;
        }

        $host = parse_url($normalized, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $trustedHosts = [
            parse_url((string) config('app.frontend_url', ''), PHP_URL_HOST),
            'fermatmind.com',
            'www.fermatmind.com',
            'example.test',
        ];

        if (! in_array($host, array_filter($trustedHosts), true)) {
            return null;
        }

        $path = parse_url($normalized, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }
}
