<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\CareerGuide;
use App\Models\CareerGuideSeoMeta;
use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Models\DataPage;
use App\Models\DataPageSeoMeta;
use App\Models\MethodPage;
use App\Models\MethodPageSeoMeta;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\TopicProfile;
use App\Models\TopicProfileSeoMeta;
use App\Services\Cms\ArticleSeoService;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerJobSeoService;
use App\Services\Cms\ContentPublishGateService;
use App\Services\Cms\DataPageSeoService;
use App\Services\Cms\MethodPageSeoService;
use App\Services\Cms\PersonalityProfileSeoService;
use App\Services\Cms\TopicProfileSeoService;
use Illuminate\Support\Str;

final class SeoOperationsService
{
    public const ACTION_FILL_METADATA = 'fill_metadata';

    public const ACTION_SYNC_CANONICAL = 'sync_canonical';

    public const ACTION_SYNC_ROBOTS = 'sync_robots';

    public const ACTION_MARK_INDEXABLE = 'mark_indexable';

    public const ACTION_MARK_NOINDEX = 'mark_noindex';

    public const ISSUE_METADATA = 'metadata';

    public const ISSUE_CANONICAL = 'canonical';

    public const ISSUE_ROBOTS = 'robots';

    public const ISSUE_INDEXABILITY = 'indexability';

    public const ISSUE_SOCIAL = 'social';

    public const ISSUE_GROWTH = 'growth';

    public const ISSUE_SCHEMA = 'schema';

    public const ISSUE_SITEMAP = 'sitemap';

    /**
     * @var list<string>
     */
    private const CORE_SEO_ISSUES = [
        self::ISSUE_METADATA,
        self::ISSUE_CANONICAL,
        self::ISSUE_ROBOTS,
        self::ISSUE_INDEXABILITY,
        self::ISSUE_SCHEMA,
    ];

    public function __construct(
        private readonly ArticleSeoService $articleSeoService,
        private readonly CareerGuideSeoService $careerGuideSeoService,
        private readonly CareerJobSeoService $careerJobSeoService,
        private readonly MethodPageSeoService $methodPageSeoService,
        private readonly DataPageSeoService $dataPageSeoService,
        private readonly PersonalityProfileSeoService $personalityProfileSeoService,
        private readonly TopicProfileSeoService $topicProfileSeoService,
    ) {}

    /**
     * @param  list<int>  $currentOrgIds
     * @return array{items:list<array<string,mixed>>,elapsed_ms:int}
     */
    public function buildIssueQueue(array $currentOrgIds, string $typeFilter = 'all', string $issueFilter = 'all'): array
    {
        $startedAt = microtime(true);
        $items = [];

        foreach ($this->recordsFor($currentOrgIds, $typeFilter) as [$type, $record]) {
            $item = $this->issueItem($type, $record, $issueFilter);
            if ($item === null) {
                continue;
            }

            $items[] = $item;
        }

        usort($items, static function (array $left, array $right): int {
            $leftPriority = (int) ($left['issue_count'] ?? 0);
            $rightPriority = (int) ($right['issue_count'] ?? 0);

            if ($leftPriority !== $rightPriority) {
                return $rightPriority <=> $leftPriority;
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        return [
            'items' => array_values($items),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /**
     * @param  list<string>  $selectionKeys
     * @param  list<int>  $currentOrgIds
     * @return array{updated_count:int,updated_keys:list<string>}
     */
    public function applyBulkAction(array $selectionKeys, string $action, array $currentOrgIds): array
    {
        $updatedKeys = [];

        foreach ($selectionKeys as $selectionKey) {
            [$type, $record] = $this->recordFromSelectionKey($selectionKey, $currentOrgIds);
            if (! is_object($record)) {
                continue;
            }

            $this->applyActionToRecord($type, $record, $action);
            $updatedKeys[] = $selectionKey;
        }

        return [
            'updated_count' => count($updatedKeys),
            'updated_keys' => $updatedKeys,
        ];
    }

    /**
     * @return list<array{code:string,label:string,autofix:array<int,string>}>
     */
    public function issuesFor(string $type, object $record): array
    {
        $seoMeta = $this->seoMetaFor($record);
        $expectedCanonical = $this->expectedCanonical($type, $record);
        $expectedRobots = $this->expectedRobots($type, $record);
        $isPublishedPublic = $this->isPublishedPublic($type, $record);
        $isIndexable = (bool) data_get($record, 'is_indexable');

        $issues = [];

        if ($this->hasMetadataGap($type, $record, $seoMeta)) {
            $issues[] = [
                'code' => self::ISSUE_METADATA,
                'label' => 'Metadata gaps',
                'autofix' => [self::ACTION_FILL_METADATA],
            ];
        }

        if ($expectedCanonical !== null && trim((string) data_get($seoMeta, 'canonical_url', '')) !== $expectedCanonical) {
            $issues[] = [
                'code' => self::ISSUE_CANONICAL,
                'label' => 'Canonical mismatch',
                'autofix' => [self::ACTION_SYNC_CANONICAL],
            ];
        }

        if (trim((string) data_get($seoMeta, 'robots', '')) !== $expectedRobots) {
            $issues[] = [
                'code' => self::ISSUE_ROBOTS,
                'label' => 'Robots drift',
                'autofix' => [self::ACTION_SYNC_ROBOTS],
            ];
        }

        if ($type === 'article' && $seoMeta instanceof ArticleSeoMeta && $seoMeta->is_indexable !== $isIndexable) {
            $issues[] = [
                'code' => self::ISSUE_INDEXABILITY,
                'label' => 'Indexability mismatch',
                'autofix' => [self::ACTION_SYNC_ROBOTS],
            ];
        }

        if ($this->hasSocialGap($type, $record, $seoMeta)) {
            $issues[] = [
                'code' => self::ISSUE_SOCIAL,
                'label' => 'Social preview gaps',
                'autofix' => $this->socialAutofixActions($type, $record, $seoMeta),
            ];
        }

        if ($this->hasSchemaGap($type, $record)) {
            $issues[] = [
                'code' => self::ISSUE_SCHEMA,
                'label' => 'Schema consistency',
                'autofix' => [self::ACTION_FILL_METADATA, self::ACTION_SYNC_CANONICAL],
            ];
        }

        if ($this->hasSitemapGap($type, $record, $seoMeta)) {
            $issues[] = [
                'code' => self::ISSUE_SITEMAP,
                'label' => 'Sitemap eligibility drift',
                'autofix' => [self::ACTION_SYNC_CANONICAL, self::ACTION_SYNC_ROBOTS],
            ];
        }

        if ($isPublishedPublic && (! $isIndexable || $this->hasGrowthBlocker($type, $record, $seoMeta))) {
            $issues[] = [
                'code' => self::ISSUE_GROWTH,
                'label' => ! $isIndexable ? 'Discovery blocked by noindex' : 'Published discovery blockers',
                'autofix' => $isIndexable
                    ? [self::ACTION_FILL_METADATA, self::ACTION_SYNC_CANONICAL, self::ACTION_SYNC_ROBOTS]
                    : [self::ACTION_MARK_INDEXABLE],
            ];
        }

        return $issues;
    }

    public function isSeoReady(string $type, object $record): bool
    {
        return collect($this->issuesFor($type, $record))
            ->pluck('code')
            ->intersect(self::CORE_SEO_ISSUES)
            ->isEmpty();
    }

    public function isGrowthReady(string $type, object $record): bool
    {
        return $this->isPublishedPublic($type, $record)
            && (bool) data_get($record, 'is_indexable')
            && ! $this->hasGrowthBlocker($type, $record, $this->seoMetaFor($record));
    }

    public function expectedCanonical(string $type, object $record): ?string
    {
        return match ($type) {
            'article' => $this->articleSeoService->buildCanonicalUrl(
                (string) data_get($record, 'slug', ''),
                (string) data_get($record, 'locale', 'en'),
            ),
            'guide' => $record instanceof CareerGuide ? $this->careerGuideSeoService->buildCanonicalUrl($record) : null,
            'job' => $record instanceof CareerJob ? $this->careerJobSeoService->buildCanonicalUrl(
                $record,
                (string) data_get($record, 'locale', 'en'),
            ) : null,
            'method' => $record instanceof MethodPage ? $this->methodPageSeoService->buildCanonicalUrl(
                $record,
                (string) data_get($record, 'locale', 'en'),
            ) : null,
            'data' => $record instanceof DataPage ? $this->dataPageSeoService->buildCanonicalUrl(
                $record,
                (string) data_get($record, 'locale', 'en'),
            ) : null,
            'personality' => $record instanceof PersonalityProfile ? $this->personalityProfileSeoService->buildCanonicalUrl(
                $record,
                (string) data_get($record, 'locale', 'en'),
            ) : null,
            'topic' => $record instanceof TopicProfile ? $this->topicProfileSeoService->buildCanonicalUrl(
                $record,
                (string) data_get($record, 'locale', 'en'),
            ) : null,
            default => null,
        };
    }

    public function expectedRobots(string $type, object $record): string
    {
        $isIndexable = (bool) data_get($record, 'is_indexable');

        return match ($type) {
            'article' => $isIndexable ? 'index,follow' : 'noindex,nofollow',
            'guide', 'job' => $isIndexable ? 'index,follow' : 'noindex,follow',
            default => $isIndexable ? 'index,follow' : 'noindex,follow',
        };
    }

    private function applyActionToRecord(string $type, object $record, string $action): void
    {
        match ($action) {
            self::ACTION_FILL_METADATA => $this->fillMetadata($type, $record),
            self::ACTION_SYNC_CANONICAL => $this->syncCanonical($type, $record),
            self::ACTION_SYNC_ROBOTS => $this->syncRobots($type, $record),
            self::ACTION_MARK_INDEXABLE => $this->markIndexability($type, $record, true),
            self::ACTION_MARK_NOINDEX => $this->markIndexability($type, $record, false),
            default => null,
        };
    }

    private function fillMetadata(string $type, object $record): void
    {
        if ($type === 'article' && $record instanceof Article) {
            $descriptionSource = trim((string) ($record->excerpt ?? ''));
            if ($descriptionSource === '') {
                $descriptionSource = $this->normalizeWhitespace(strip_tags((string) $record->content_md));
            }

            $fallbackTitle = Str::limit(trim((string) $record->title), 60, '');
            $fallbackDescription = Str::limit($descriptionSource, 160, '');
            $fallbackOgTitle = Str::limit(trim((string) $record->title), 90, '');
            $fallbackOgDescription = Str::limit($descriptionSource, 200, '');
            $fallbackOgImage = trim((string) ($record->cover_image_url ?? ''));
            $fallbackCanonical = $this->expectedCanonical('article', $record) ?? '';
            $fallbackRobots = $this->expectedRobots('article', $record);
            $meta = ArticleSeoMeta::query()->withoutGlobalScopes()->firstOrNew([
                'org_id' => (int) $record->org_id,
                'article_id' => (int) $record->id,
                'locale' => (string) $record->locale,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, $fallbackTitle),
                'seo_description' => $this->preserveOrFill($meta->seo_description, $fallbackDescription),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, $fallbackCanonical),
                'og_title' => $this->preserveOrFill($meta->og_title, $fallbackOgTitle),
                'og_description' => $this->preserveOrFill($meta->og_description, $fallbackOgDescription),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, $fallbackOgImage),
                'robots' => $this->preserveOrFill($meta->robots, $fallbackRobots),
                'is_indexable' => (bool) $record->is_indexable,
            ]);
            $meta->save();

            return;
        }

        if ($type === 'guide' && $record instanceof CareerGuide) {
            $payload = $this->careerGuideSeoService->buildSeoPayload($record);
            $meta = CareerGuideSeoMeta::query()->firstOrNew([
                'career_guide_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();

            return;
        }

        if ($type === 'job' && $record instanceof CareerJob) {
            $payload = $this->careerJobSeoService->buildMeta($record, (string) $record->locale);
            $meta = CareerJobSeoMeta::query()->firstOrNew([
                'job_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();

            return;
        }

        if ($type === 'method' && $record instanceof MethodPage) {
            $payload = $this->methodPageSeoService->buildMeta($record, (string) $record->locale);
            $meta = MethodPageSeoMeta::query()->firstOrNew([
                'method_page_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();

            return;
        }

        if ($type === 'data' && $record instanceof DataPage) {
            $payload = $this->dataPageSeoService->buildMeta($record, (string) $record->locale);
            $meta = DataPageSeoMeta::query()->firstOrNew([
                'data_page_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();

            return;
        }

        if ($type === 'personality' && $record instanceof PersonalityProfile) {
            $payload = $this->personalityProfileSeoService->buildMeta($record);
            $meta = PersonalityProfileSeoMeta::query()->firstOrNew([
                'profile_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();

            return;
        }

        if ($type === 'topic' && $record instanceof TopicProfile) {
            $payload = $this->topicProfileSeoService->buildMeta($record, (string) $record->locale);
            $meta = TopicProfileSeoMeta::query()->firstOrNew([
                'profile_id' => (int) $record->id,
            ]);

            $meta->fill([
                'seo_title' => $this->preserveOrFill($meta->seo_title, (string) data_get($payload, 'title', '')),
                'seo_description' => $this->preserveOrFill($meta->seo_description, (string) data_get($payload, 'description', '')),
                'canonical_url' => $this->preserveOrFill($meta->canonical_url, (string) data_get($payload, 'canonical', '')),
                'og_title' => $this->preserveOrFill($meta->og_title, (string) data_get($payload, 'og.title', '')),
                'og_description' => $this->preserveOrFill($meta->og_description, (string) data_get($payload, 'og.description', '')),
                'og_image_url' => $this->preserveOrFill($meta->og_image_url, (string) data_get($payload, 'og.image', '')),
                'twitter_title' => $this->preserveOrFill($meta->twitter_title, (string) data_get($payload, 'twitter.title', '')),
                'twitter_description' => $this->preserveOrFill($meta->twitter_description, (string) data_get($payload, 'twitter.description', '')),
                'twitter_image_url' => $this->preserveOrFill($meta->twitter_image_url, (string) data_get($payload, 'twitter.image', '')),
                'robots' => $this->preserveOrFill($meta->robots, (string) data_get($payload, 'robots', '')),
            ]);
            $meta->save();
        }
    }

    private function syncCanonical(string $type, object $record): void
    {
        $expectedCanonical = $this->expectedCanonical($type, $record);
        if ($expectedCanonical === null) {
            return;
        }

        $meta = $this->ensureSeoMeta($type, $record);
        if (! is_object($meta)) {
            return;
        }

        $meta->canonical_url = $expectedCanonical;
        $meta->save();
    }

    private function syncRobots(string $type, object $record): void
    {
        $meta = $this->ensureSeoMeta($type, $record);
        if (! is_object($meta)) {
            return;
        }

        $meta->robots = $this->expectedRobots($type, $record);
        if ($meta instanceof ArticleSeoMeta) {
            $meta->is_indexable = (bool) data_get($record, 'is_indexable');
        }

        $meta->save();
    }

    private function markIndexability(string $type, object $record, bool $isIndexable): void
    {
        if (method_exists($record, 'forceFill')) {
            $record->forceFill([
                'is_indexable' => $isIndexable,
            ])->save();
        }

        $this->syncRobots($type, $record);
    }

    /**
     * @param  list<int>  $currentOrgIds
     * @return iterable<int, array{0:string,1:object}>
     */
    private function recordsFor(array $currentOrgIds, string $typeFilter): iterable
    {
        if (in_array($typeFilter, ['all', 'article'], true)) {
            $records = Article::query()
                ->whereIn('org_id', $currentOrgIds)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['article', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'guide'], true)) {
            $records = CareerGuide::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['guide', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'job'], true)) {
            $records = CareerJob::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['job', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'method'], true)) {
            $records = MethodPage::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['method', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'data'], true)) {
            $records = DataPage::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['data', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'personality'], true)) {
            $records = PersonalityProfile::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['personality', $record];
            }
        }

        if (in_array($typeFilter, ['all', 'topic'], true)) {
            $records = TopicProfile::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->with('seoMeta')
                ->latest('updated_at')
                ->get();

            foreach ($records as $record) {
                yield ['topic', $record];
            }
        }
    }

    /**
     * @param  list<int>  $currentOrgIds
     * @return array{0:string,1:object|null}
     */
    private function recordFromSelectionKey(string $selectionKey, array $currentOrgIds): array
    {
        $type = Str::before($selectionKey, ':');
        $id = (int) Str::after($selectionKey, ':');

        if ($id <= 0) {
            return [$type, null];
        }

        return match ($type) {
            'article' => [
                'article',
                Article::query()->whereIn('org_id', $currentOrgIds)->with('seoMeta')->find($id),
            ],
            'guide' => [
                'guide',
                CareerGuide::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            'job' => [
                'job',
                CareerJob::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            'method' => [
                'method',
                MethodPage::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            'data' => [
                'data',
                DataPage::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            'personality' => [
                'personality',
                PersonalityProfile::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            'topic' => [
                'topic',
                TopicProfile::query()->withoutGlobalScopes()->where('org_id', 0)->with('seoMeta')->find($id),
            ],
            default => [$type, null],
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function issueItem(string $type, object $record, string $issueFilter): ?array
    {
        $issues = $this->issuesFor($type, $record);
        if ($issues === []) {
            return null;
        }

        if ($issueFilter !== 'all' && ! collect($issues)->contains(fn (array $issue): bool => $issue['code'] === $issueFilter)) {
            return null;
        }

        $autofixActions = array_values(array_unique(array_merge(...array_map(
            static fn (array $issue): array => $issue['autofix'] ?? [],
            $issues
        ))));

        return [
            'selection_key' => $type.':'.(int) data_get($record, 'id'),
            'type' => $type,
            'title' => trim((string) data_get($record, 'title', 'Untitled')),
            'scope' => $type === 'article' ? 'Current org' : 'Global content',
            'status' => trim((string) data_get($record, 'status', 'draft')),
            'is_public' => (bool) data_get($record, 'is_public'),
            'is_indexable' => (bool) data_get($record, 'is_indexable'),
            'issue_codes' => array_values(array_map(static fn (array $issue): string => (string) $issue['code'], $issues)),
            'issue_labels' => array_values(array_map(static fn (array $issue): string => $issue['label'], $issues)),
            'issue_count' => count($issues),
            'autofix_actions' => $autofixActions,
            'growth_signal' => $this->growthSignal($type, $record),
            'edit_url' => $this->editUrl($type, (int) data_get($record, 'id')),
            'updated_at' => optional(data_get($record, 'updated_at'))->toISOString(),
        ];
    }

    private function hasMetadataGap(string $type, object $record, ?object $seoMeta): bool
    {
        $fields = [
            trim((string) data_get($seoMeta, 'seo_title', '')),
            trim((string) data_get($seoMeta, 'seo_description', '')),
            trim((string) data_get($seoMeta, 'og_title', '')),
            trim((string) data_get($seoMeta, 'og_description', '')),
        ];

        if ($type !== 'article') {
            $fields[] = trim((string) data_get($seoMeta, 'twitter_title', ''));
            $fields[] = trim((string) data_get($seoMeta, 'twitter_description', ''));
        }

        return in_array('', $fields, true);
    }

    private function hasSocialGap(string $type, object $record, ?object $seoMeta): bool
    {
        $fields = [
            trim((string) data_get($seoMeta, 'og_title', '')),
            trim((string) data_get($seoMeta, 'og_description', '')),
            trim((string) data_get($seoMeta, 'og_image_url', '')),
        ];

        if ($type !== 'article') {
            $fields[] = trim((string) data_get($seoMeta, 'twitter_title', ''));
            $fields[] = trim((string) data_get($seoMeta, 'twitter_description', ''));
        }

        return in_array('', $fields, true);
    }

    private function hasGrowthBlocker(string $type, object $record, ?object $seoMeta): bool
    {
        return $this->hasMetadataGap($type, $record, $seoMeta)
            || trim((string) data_get($seoMeta, 'canonical_url', '')) !== $this->expectedCanonical($type, $record)
            || trim((string) data_get($seoMeta, 'robots', '')) !== $this->expectedRobots($type, $record);
    }

    private function hasSchemaGap(string $type, object $record): bool
    {
        return in_array('schema consistency', ContentPublishGateService::missing($type, $record), true);
    }

    private function hasSitemapGap(string $type, object $record, ?object $seoMeta): bool
    {
        if (! $this->isPublishedPublic($type, $record) || ! (bool) data_get($record, 'is_indexable')) {
            return false;
        }

        $expectedCanonical = $this->expectedCanonical($type, $record);
        if ($expectedCanonical === null || $expectedCanonical === '') {
            return false;
        }

        $robots = strtolower(trim((string) data_get($seoMeta, 'robots', '')));

        return trim((string) data_get($seoMeta, 'canonical_url', '')) !== $expectedCanonical
            || $robots === ''
            || str_contains($robots, 'noindex');
    }

    private function isPublishedPublic(string $type, object $record): bool
    {
        return (bool) data_get($record, 'is_public')
            && match ($type) {
                'guide' => data_get($record, 'status') === CareerGuide::STATUS_PUBLISHED,
                'job' => data_get($record, 'status') === CareerJob::STATUS_PUBLISHED,
                default => data_get($record, 'status') === 'published',
            };
    }

    public function growthSignal(string $type, object $record): string
    {
        $seoMeta = $this->seoMetaFor($record);

        if (! $this->isPublishedPublic($type, $record)) {
            return 'Not discoverable yet';
        }

        if (! (bool) data_get($record, 'is_indexable')) {
            return 'Blocked by noindex';
        }

        if ($this->hasGrowthBlocker($type, $record, $seoMeta)) {
            return 'Published with discovery blockers';
        }

        return 'Discoverable now';
    }

    /**
     * @return list<string>
     */
    private function socialAutofixActions(string $type, object $record, ?object $seoMeta): array
    {
        $missingCoreSocialFields = [
            trim((string) data_get($seoMeta, 'og_title', '')) === '',
            trim((string) data_get($seoMeta, 'og_description', '')) === '',
        ];

        if ($type !== 'article') {
            $missingCoreSocialFields[] = trim((string) data_get($seoMeta, 'twitter_title', '')) === '';
            $missingCoreSocialFields[] = trim((string) data_get($seoMeta, 'twitter_description', '')) === '';
        }

        $canFillTextualSocialFields = in_array(true, $missingCoreSocialFields, true);

        $missingSocialImage = trim((string) data_get($seoMeta, 'og_image_url', '')) === '';
        $hasImageSource = trim((string) match ($type) {
            'article', 'job' => data_get($record, 'cover_image_url', ''),
            default => '',
        }) !== '';

        if ($canFillTextualSocialFields || ($missingSocialImage && $hasImageSource)) {
            return [self::ACTION_FILL_METADATA];
        }

        return [];
    }

    private function ensureSeoMeta(string $type, object $record): ?object
    {
        $existing = $this->seoMetaFor($record);
        if (is_object($existing)) {
            return $existing;
        }

        $this->fillMetadata($type, $record);

        return $this->seoMetaFor(
            $record instanceof Article
            || $record instanceof CareerGuide
            || $record instanceof CareerJob
            || $record instanceof MethodPage
            || $record instanceof DataPage
            || $record instanceof PersonalityProfile
            || $record instanceof TopicProfile
                ? $record->fresh('seoMeta')
                : $record
        );
    }

    private function seoMetaFor(object $record): ?object
    {
        $seoMeta = data_get($record, 'seoMeta');

        return is_object($seoMeta) ? $seoMeta : null;
    }

    private function preserveOrFill(?string $current, string $fallback): string
    {
        $normalized = trim((string) $current);

        return $normalized !== '' ? $normalized : trim($fallback);
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }

    private function editUrl(string $type, int $id): string
    {
        try {
            return match ($type) {
                'article' => \App\Filament\Ops\Resources\ArticleResource::getUrl('edit', ['record' => $id]),
                'guide' => \App\Filament\Ops\Resources\CareerGuideResource::getUrl('edit', ['record' => $id]),
                'job' => \App\Filament\Ops\Resources\CareerJobResource::getUrl('edit', ['record' => $id]),
                'method' => \App\Filament\Ops\Resources\MethodPageResource::getUrl('edit', ['record' => $id]),
                'data' => \App\Filament\Ops\Resources\DataPageResource::getUrl('edit', ['record' => $id]),
                'personality' => \App\Filament\Ops\Resources\PersonalityProfileResource::getUrl('edit', ['record' => $id]),
                'topic' => \App\Filament\Ops\Resources\TopicProfileResource::getUrl('edit', ['record' => $id]),
                default => '#',
            };
        } catch (\Throwable) {
            return '#';
        }
    }
}
