<?php

declare(strict_types=1);

namespace App\Filament\Ops\Pages;

use App\Filament\Ops\Support\ContentAccess;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Support\OrgContext;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PostReleaseObservabilityPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Content Release';

    protected static ?string $navigationLabel = 'Post-Release Observability';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'post-release-observability';

    protected static string $view = 'filament.ops.pages.post-release-observability';

    /** @var list<array<string, mixed>> */
    public array $headlineFields = [];

    /** @var list<array<string, mixed>> */
    public array $releaseCards = [];

    /** @var list<array<string, mixed>> */
    public array $auditCards = [];

    public function mount(): void
    {
        $currentOrgIds = $this->currentOrgIds();
        $recentThreshold = Carbon::now()->subDay();

        $articleBaseQuery = Article::query()->whereIn('org_id', $currentOrgIds);
        $guideBaseQuery = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);
        $jobBaseQuery = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0);

        $articleRecentPublishes = (clone $articleBaseQuery)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $recentThreshold)
            ->count();
        $guideRecentPublishes = (clone $guideBaseQuery)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $recentThreshold)
            ->count();
        $jobRecentPublishes = (clone $jobBaseQuery)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $recentThreshold)
            ->count();

        $recentPublishes = $articleRecentPublishes + $guideRecentPublishes + $jobRecentPublishes;

        $visibilityGaps = (clone $articleBaseQuery)
            ->where('status', 'published')
            ->where('is_public', false)
            ->count()
            + (clone $guideBaseQuery)
                ->where('status', CareerGuide::STATUS_PUBLISHED)
                ->where('is_public', false)
                ->count()
            + (clone $jobBaseQuery)
                ->where('status', CareerJob::STATUS_PUBLISHED)
                ->where('is_public', false)
                ->count();

        $publicFootprint = (clone $articleBaseQuery)
            ->where('status', 'published')
            ->where('is_public', true)
            ->count()
            + (clone $guideBaseQuery)
                ->where('status', CareerGuide::STATUS_PUBLISHED)
                ->where('is_public', true)
                ->count()
            + (clone $jobBaseQuery)
                ->where('status', CareerJob::STATUS_PUBLISHED)
                ->where('is_public', true)
                ->count();

        $releaseAuditsQuery = AuditLog::query()
            ->where('action', 'content_release_publish')
            ->where('org_id', max(0, (int) app(OrgContext::class)->orgId()))
            ->latest('created_at');

        $recentAuditRows = (clone $releaseAuditsQuery)
            ->limit(6)
            ->get();

        $publishAuditCount24h = (clone $releaseAuditsQuery)
            ->where('created_at', '>=', $recentThreshold)
            ->count();

        $this->headlineFields = [
            [
                'label' => 'Publishes in last 24h',
                'value' => (string) $recentPublishes,
                'hint' => 'Recently published records across selected-org articles and global career content.',
            ],
            [
                'label' => 'Release audits in last 24h',
                'value' => (string) $publishAuditCount24h,
                'hint' => 'Audit rows written by the CMS release workspace during the last 24 hours.',
            ],
            [
                'label' => 'Public delivery footprint',
                'value' => (string) $publicFootprint,
                'hint' => 'Published and public records currently reachable through the visible public content contract.',
            ],
            [
                'label' => 'Visibility gaps',
                'value' => (string) $visibilityGaps,
                'kind' => 'pill',
                'state' => $visibilityGaps > 0 ? 'warning' : 'success',
                'hint' => 'Published-but-not-public records that can cause release confusion after approval.',
            ],
            [
                'label' => 'Recent audit stream',
                'value' => (string) $recentAuditRows->count(),
                'hint' => 'Most recent publish audit rows available to this selected-org release boundary.',
            ],
        ];

        $publishedRows = collect()
            ->concat($this->publishedCards(
                (clone $articleBaseQuery)
                    ->whereNotNull('published_at')
                    ->latest('published_at')
                    ->limit(3)
                    ->get(),
                'Article',
                'Current org'
            ))
            ->concat($this->publishedCards(
                (clone $guideBaseQuery)
                    ->whereNotNull('published_at')
                    ->latest('published_at')
                    ->limit(3)
                    ->get(),
                'Career Guide',
                'Global content'
            ))
            ->concat($this->publishedCards(
                (clone $jobBaseQuery)
                    ->whereNotNull('published_at')
                    ->latest('published_at')
                    ->limit(3)
                    ->get(),
                'Career Job',
                'Global content'
            ))
            ->sortByDesc('sort_at')
            ->take(6)
            ->values()
            ->all();

        $this->releaseCards = $publishedRows;

        $this->auditCards = $recentAuditRows
            ->map(function (AuditLog $row): array {
                $meta = is_array($row->meta_json) ? $row->meta_json : [];

                return [
                    'title' => trim((string) data_get($meta, 'title', 'Untitled release event')),
                    'meta' => trim((string) ($row->action.' | '.(string) ($row->target_type ?? 'unknown'))),
                    'description' => 'Actor: '.trim((string) data_get($meta, 'actor_email', 'unknown')).' | Visibility: '.trim((string) data_get($meta, 'visibility', 'unknown')).' | Source: '.trim((string) data_get($meta, 'source', 'unknown')),
                    'status' => trim((string) data_get($meta, 'status_after', 'published')),
                    'status_state' => 'success',
                    'latest_title' => optional($row->created_at)?->toDateTimeString() ?? 'Unknown',
                ];
            })
            ->values()
            ->all();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content_release');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.post_release_observability');
    }

    public static function canAccess(): bool
    {
        return ContentAccess::canRelease();
    }

    /**
     * @return array<int, int>
     */
    private function currentOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [$orgId] : [];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function publishedCards(Collection $rows, string $typeLabel, string $scope): array
    {
        return $rows
            ->map(function (object $row) use ($typeLabel, $scope): array {
                $publishedAt = data_get($row, 'published_at');

                return [
                    'title' => trim((string) data_get($row, 'title', 'Untitled')),
                    'meta' => $typeLabel.' | '.$scope.' | '.(data_get($row, 'is_public') ? 'Public' : 'Private'),
                    'description' => 'Published at '.(optional($publishedAt)?->toDateTimeString() ?? 'Unknown'),
                    'status' => trim((string) data_get($row, 'status', 'published')),
                    'status_state' => data_get($row, 'is_public') ? 'success' : 'warning',
                    'latest_title' => trim((string) data_get($row, 'slug', '')),
                    'sort_at' => $publishedAt instanceof \DateTimeInterface ? $publishedAt->getTimestamp() : 0,
                ];
            })
            ->values()
            ->all();
    }
}
