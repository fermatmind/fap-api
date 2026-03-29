<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Support\OrgContext;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

class SeoOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Content Overview';

    protected static ?string $navigationLabel = 'SEO Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'seo-operations';

    protected static string $view = 'filament.ops.pages.seo-operations';

    /** @var list<array<string, mixed>> */
    public array $headlineFields = [];

    /** @var list<array<string, mixed>> */
    public array $coverageFields = [];

    /** @var list<array<string, mixed>> */
    public array $attentionCards = [];

    public function mount(): void
    {
        $currentOrgIds = $this->currentOrgIds();

        $articleBaseQuery = Article::query()->whereIn('org_id', $currentOrgIds);
        $guideBaseQuery = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);
        $jobBaseQuery = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);

        $articleTotal = (clone $articleBaseQuery)->count();
        $guideTotal = (clone $guideBaseQuery)->count();
        $jobTotal = (clone $jobBaseQuery)->count();
        $careerTotal = $guideTotal + $jobTotal;

        $articleSeoReady = $this->countSeoReady(clone $articleBaseQuery);
        $guideSeoReady = $this->countSeoReady(clone $guideBaseQuery);
        $jobSeoReady = $this->countSeoReady(clone $jobBaseQuery);
        $careerSeoReady = $guideSeoReady + $jobSeoReady;

        $articleCanonicalCoverage = $this->countCanonicalCoverage(clone $articleBaseQuery);
        $guideCanonicalCoverage = $this->countCanonicalCoverage(clone $guideBaseQuery);
        $jobCanonicalCoverage = $this->countCanonicalCoverage(clone $jobBaseQuery);

        $articleSocialCoverage = $this->countSocialCoverage(clone $articleBaseQuery);
        $careerSocialCoverage = $this->countSocialCoverage(clone $guideBaseQuery) + $this->countSocialCoverage(clone $jobBaseQuery);

        $indexableFootprint = (clone $articleBaseQuery)->where('is_indexable', true)->count()
            + (clone $guideBaseQuery)->where('is_indexable', true)->count()
            + (clone $jobBaseQuery)->where('is_indexable', true)->count();

        $publicSeoReady = $this->countPublicSeoReady(clone $articleBaseQuery)
            + $this->countPublicSeoReady(clone $guideBaseQuery)
            + $this->countPublicSeoReady(clone $jobBaseQuery);

        $seoAttentionQueue = ($articleTotal - $articleSeoReady)
            + ($guideTotal - $guideSeoReady)
            + ($jobTotal - $jobSeoReady);

        $robotsGaps = $this->countRobotsGap(clone $articleBaseQuery)
            + $this->countRobotsGap(clone $guideBaseQuery)
            + $this->countRobotsGap(clone $jobBaseQuery);

        $this->headlineFields = [
            [
                'label' => 'Current org article SEO-ready',
                'value' => $this->ratioLabel($articleSeoReady, $articleTotal),
                'hint' => 'Selected-org article coverage for title, description, canonical, OG, and robots fields.',
            ],
            [
                'label' => 'Global career SEO-ready',
                'value' => $this->ratioLabel($careerSeoReady, $careerTotal),
                'hint' => 'Global guide and job coverage for the visible SEO authoring surface.',
            ],
            [
                'label' => 'Indexable footprint',
                'value' => (string) $indexableFootprint,
                'hint' => 'Visible records currently marked indexable across article and global career surfaces.',
            ],
            [
                'label' => 'Public SEO-ready records',
                'value' => (string) $publicSeoReady,
                'hint' => 'Published and public records that also satisfy the current SEO completeness checks.',
            ],
            [
                'label' => 'SEO attention queue',
                'value' => (string) $seoAttentionQueue,
                'hint' => 'Visible content objects still missing at least one core SEO field.',
            ],
        ];

        $this->coverageFields = [
            [
                'label' => 'Article canonical coverage',
                'value' => $this->ratioLabel($articleCanonicalCoverage, $articleTotal),
                'hint' => 'Current-org article canonical URL coverage.',
            ],
            [
                'label' => 'Article social coverage',
                'value' => $this->ratioLabel($articleSocialCoverage, $articleTotal),
                'hint' => 'Current-org Open Graph coverage for articles.',
            ],
            [
                'label' => 'Guide canonical coverage',
                'value' => $this->ratioLabel($guideCanonicalCoverage, $guideTotal),
                'hint' => 'Global career guide canonical URL coverage.',
            ],
            [
                'label' => 'Job canonical coverage',
                'value' => $this->ratioLabel($jobCanonicalCoverage, $jobTotal),
                'hint' => 'Global career job canonical URL coverage.',
            ],
            [
                'label' => 'Robots gaps',
                'value' => (string) $robotsGaps,
                'kind' => 'pill',
                'state' => $robotsGaps > 0 ? 'warning' : 'success',
                'hint' => 'Records where robots is still blank in SEO metadata.',
            ],
        ];

        $this->attentionCards = [
            $this->attentionCard(
                'Article SEO gaps',
                'Current-org articles that still need core SEO fields before the editorial surface is truly ready.',
                $articleTotal - $articleSeoReady,
                'Current org',
                $this->latestSeoGapTitle(clone $articleBaseQuery)
            ),
            $this->attentionCard(
                'Career guide SEO gaps',
                'Global guides that still need SEO completion before they should be treated as SEO-ready inventory.',
                $guideTotal - $guideSeoReady,
                'Global content',
                $this->latestSeoGapTitle(clone $guideBaseQuery)
            ),
            $this->attentionCard(
                'Career job SEO gaps',
                'Global jobs that still need SEO completion before they should be treated as SEO-ready inventory.',
                $jobTotal - $jobSeoReady,
                'Global content',
                $this->latestSeoGapTitle(clone $jobBaseQuery)
            ),
            [
                'title' => 'Career social gaps',
                'description' => 'Open Graph coverage across global guides and jobs. This helps keep social previews consistent after release.',
                'meta' => 'Global content | '.($careerTotal - $careerSocialCoverage).' records need OG work',
                'value' => (string) ($careerTotal - $careerSocialCoverage),
                'status' => ($careerTotal - $careerSocialCoverage) > 0 ? 'Needs attention' : 'Healthy',
                'status_state' => ($careerTotal - $careerSocialCoverage) > 0 ? 'warning' : 'success',
                'latest_title' => $this->latestSocialGapTitle(clone $guideBaseQuery) ?? $this->latestSocialGapTitle(clone $jobBaseQuery) ?? 'No recent record',
            ],
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_overview');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.seo_operations');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRead();
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }

    private function countSeoReady(Builder $query): int
    {
        return $query->whereHas('seoMeta', function (Builder $seoQuery): void {
            $seoQuery
                ->whereNotNull('seo_title')
                ->where('seo_title', '!=', '')
                ->whereNotNull('seo_description')
                ->where('seo_description', '!=', '')
                ->whereNotNull('canonical_url')
                ->where('canonical_url', '!=', '')
                ->whereNotNull('og_title')
                ->where('og_title', '!=', '')
                ->whereNotNull('og_description')
                ->where('og_description', '!=', '')
                ->whereNotNull('og_image_url')
                ->where('og_image_url', '!=', '')
                ->whereNotNull('robots')
                ->where('robots', '!=', '');
        })->count();
    }

    private function countCanonicalCoverage(Builder $query): int
    {
        return $query->whereHas('seoMeta', function (Builder $seoQuery): void {
            $seoQuery
                ->whereNotNull('canonical_url')
                ->where('canonical_url', '!=', '');
        })->count();
    }

    private function countSocialCoverage(Builder $query): int
    {
        return $query->whereHas('seoMeta', function (Builder $seoQuery): void {
            $seoQuery
                ->whereNotNull('og_title')
                ->where('og_title', '!=', '')
                ->whereNotNull('og_description')
                ->where('og_description', '!=', '')
                ->whereNotNull('og_image_url')
                ->where('og_image_url', '!=', '');
        })->count();
    }

    private function countPublicSeoReady(Builder $query): int
    {
        return $this->countSeoReady(
            $query
                ->where('status', 'published')
                ->where('is_public', true)
        );
    }

    private function countRobotsGap(Builder $query): int
    {
        return $query->where(function (Builder $itemQuery): void {
            $itemQuery
                ->whereDoesntHave('seoMeta')
                ->orWhereHas('seoMeta', function (Builder $seoQuery): void {
                    $seoQuery
                        ->whereNull('robots')
                        ->orWhere('robots', '');
                });
        })->count();
    }

    private function latestSeoGapTitle(Builder $query): ?string
    {
        /** @var object|null $record */
        $record = $query
            ->where(function (Builder $itemQuery): void {
                $itemQuery
                    ->whereDoesntHave('seoMeta')
                    ->orWhereHas('seoMeta', function (Builder $seoQuery): void {
                        $seoQuery
                            ->whereNull('seo_title')
                            ->orWhere('seo_title', '')
                            ->orWhereNull('seo_description')
                            ->orWhere('seo_description', '')
                            ->orWhereNull('canonical_url')
                            ->orWhere('canonical_url', '')
                            ->orWhereNull('og_title')
                            ->orWhere('og_title', '')
                            ->orWhereNull('og_description')
                            ->orWhere('og_description', '')
                            ->orWhereNull('og_image_url')
                            ->orWhere('og_image_url', '')
                            ->orWhereNull('robots')
                            ->orWhere('robots', '');
                    });
            })
            ->latest('updated_at')
            ->first();

        return is_object($record) ? trim((string) data_get($record, 'title', '')) : null;
    }

    private function latestSocialGapTitle(Builder $query): ?string
    {
        /** @var object|null $record */
        $record = $query
            ->where(function (Builder $itemQuery): void {
                $itemQuery
                    ->whereDoesntHave('seoMeta')
                    ->orWhereHas('seoMeta', function (Builder $seoQuery): void {
                        $seoQuery
                            ->whereNull('og_title')
                            ->orWhere('og_title', '')
                            ->orWhereNull('og_description')
                            ->orWhere('og_description', '')
                            ->orWhereNull('og_image_url')
                            ->orWhere('og_image_url', '');
                    });
            })
            ->latest('updated_at')
            ->first();

        return is_object($record) ? trim((string) data_get($record, 'title', '')) : null;
    }

    private function ratioLabel(int $value, int $total): string
    {
        if ($total <= 0) {
            return '0% (0/0)';
        }

        $ratio = (int) round(($value / $total) * 100);

        return $ratio.'% ('.$value.'/'.$total.')';
    }

    /**
     * @return array<string, string>
     */
    private function attentionCard(
        string $title,
        string $description,
        int $count,
        string $scope,
        ?string $latestTitle,
    ): array {
        return [
            'title' => $title,
            'description' => $description,
            'meta' => $scope.' | '.$count.' records need SEO work',
            'value' => (string) $count,
            'status' => $count > 0 ? 'Needs attention' : 'Healthy',
            'status_state' => $count > 0 ? 'warning' : 'success',
            'latest_title' => trim((string) $latestTitle) !== '' ? trim((string) $latestTitle) : 'No recent record',
        ];
    }
}
