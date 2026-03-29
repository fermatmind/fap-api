<?php

declare(strict_types=1);

namespace App\Services\Ops;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\Event;
use App\Models\Order;
use App\Services\Commerce\OrderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ContentGrowthAttributionService
{
    public function __construct(
        private readonly OrderManager $orders,
    ) {}

    /**
     * @param  array<int, int>  $currentOrgIds
     * @return array{
     *   headline_fields:list<array<string,mixed>>,
     *   diagnostic_cards:list<array<string,mixed>>,
     *   matrix_rows:list<array<string,mixed>>
     * }
     */
    public function build(array $currentOrgIds): array
    {
        $orgId = max(0, (int) ($currentOrgIds[0] ?? 0));
        $lookbackThreshold = Carbon::now()->subDays(30);
        $surfaces = $this->surfaceInventory($currentOrgIds);

        $events = Event::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('occurred_at', '>=', $lookbackThreshold)
            ->get();

        $orders = Order::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FULFILLED])
            ->where(function ($query) use ($lookbackThreshold): void {
                $query->where('paid_at', '>=', $lookbackThreshold)
                    ->orWhere(function ($fallbackQuery) use ($lookbackThreshold): void {
                        $fallbackQuery
                            ->whereNull('paid_at')
                            ->where('updated_at', '>=', $lookbackThreshold);
                    });
            })
            ->get();

        $matrixRows = $surfaces
            ->map(function (array $surface) use ($events, $orders): array {
                $matchingEvents = $events
                    ->filter(fn (Event $event): bool => $this->matchesSurface($surface, $this->eventCandidates($event)))
                    ->values();

                $matchingOrders = $orders
                    ->filter(fn (Order $order): bool => $this->matchesSurface($surface, $this->orderCandidates($order)))
                    ->values();

                $shareTouchpoints = $this->shareTouchpoints($matchingEvents, $matchingOrders);
                $paidOrders = $matchingOrders->count();
                $shareAssistedOrders = $matchingOrders
                    ->filter(fn (Order $order): bool => $this->isShareAssistedOrder($order))
                    ->count();
                $revenueCents = (int) $matchingOrders->sum(function (Order $order): int {
                    return (int) ($order->amount_cents ?? $order->amount_total ?? 0);
                });
                $signalCount = $matchingEvents->count();
                $lastTouch = $this->latestTouchAt($matchingEvents, $matchingOrders);
                $canonicalReady = $surface['canonical_ready'];
                $indexable = $surface['indexable'];
                $growthState = $paidOrders > 0 ? 'Converting' : ($signalCount > 0 ? 'Discovery' : 'Dormant');
                $growthStateLabel = $indexable
                    ? ($canonicalReady ? 'Indexable' : 'Canonical gap')
                    : 'Noindex';

                return [
                    'title' => $surface['title'],
                    'type' => $surface['type'],
                    'scope' => $surface['scope'],
                    'locale' => $surface['locale'],
                    'slug' => $surface['slug'],
                    'public_path' => $surface['public_path'],
                    'public_url' => $surface['public_url'],
                    'seo_label' => $growthStateLabel,
                    'seo_state' => $indexable && $canonicalReady ? 'success' : ($paidOrders > 0 ? 'warning' : 'gray'),
                    'signals' => $signalCount,
                    'share_touchpoints' => $shareTouchpoints,
                    'share_assisted_orders' => $shareAssistedOrders,
                    'paid_orders' => $paidOrders,
                    'revenue_cents' => $revenueCents,
                    'revenue_label' => $this->formatCurrencyCents($revenueCents),
                    'growth_state' => $growthState,
                    'growth_state_label' => $growthState.' | '.$growthStateLabel,
                    'last_touch_at' => $lastTouch,
                    'last_touch_label' => $lastTouch?->toDateTimeString() ?? 'No recent touch',
                    'published_at' => $surface['published_at'],
                    'sort_weight' => ($paidOrders * 100000) + ($signalCount * 100) + $shareAssistedOrders,
                    'converting_without_indexability' => $paidOrders > 0 && ! $indexable,
                    'converting_without_canonical' => $paidOrders > 0 && ! $canonicalReady,
                    'indexable_without_signals' => $indexable && $signalCount === 0 && $paidOrders === 0,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['sort_weight'])
            ->values();

        $headlineFields = [
            [
                'label' => 'Indexable public surfaces',
                'value' => (string) $matrixRows->where('seo_label', 'Indexable')->count(),
                'hint' => 'Visible public content records that are both indexable and canonical-ready.',
            ],
            [
                'label' => 'Attributed surfaces (30d)',
                'value' => (string) $matrixRows
                    ->filter(fn (array $row): bool => $row['signals'] > 0 || $row['paid_orders'] > 0)
                    ->count(),
                'hint' => 'Content surfaces with at least one entry signal or paid order in the last 30 days.',
            ],
            [
                'label' => 'Tracked share touchpoints',
                'value' => (string) $matrixRows->sum('share_touchpoints'),
                'hint' => 'Distinct share_id or share_click_id touchpoints attributed back to visible content surfaces.',
            ],
            [
                'label' => 'Paid conversions (30d)',
                'value' => (string) $matrixRows->sum('paid_orders'),
                'hint' => 'Paid or fulfilled orders carrying attribution that resolves back to visible content paths.',
            ],
            [
                'label' => 'Attributed revenue (30d)',
                'value' => $this->formatCurrencyCents((int) $matrixRows->sum('revenue_cents')),
                'hint' => 'Revenue from paid orders attributed to visible content paths in the current org boundary.',
            ],
        ];

        $diagnosticCards = [
            $this->diagnosticCard(
                'Converting but noindex',
                'Published content with attributed paid conversions but still not marked indexable.',
                $matrixRows->where('converting_without_indexability', true),
                'SEO and growth mismatch'
            ),
            $this->diagnosticCard(
                'Converting with canonical gaps',
                'Content already producing paid orders while canonical coverage is still incomplete.',
                $matrixRows->where('converting_without_canonical', true),
                'SEO cleanup needed'
            ),
            $this->diagnosticCard(
                'Share-assisted winners',
                'Content surfaces with the strongest share-assisted order signal in the last 30 days.',
                $matrixRows->filter(fn (array $row): bool => $row['share_assisted_orders'] > 0),
                'Share propagation'
            ),
            $this->diagnosticCard(
                'Indexable but dormant',
                'Indexable public content that still has no attributed signals or paid conversions.',
                $matrixRows->where('indexable_without_signals', true),
                'Discovery gap'
            ),
        ];

        return [
            'headline_fields' => $headlineFields,
            'diagnostic_cards' => $diagnosticCards,
            'matrix_rows' => $matrixRows->take(12)->all(),
        ];
    }

    /**
     * @param  array<int, int>  $currentOrgIds
     * @return Collection<int, array<string,mixed>>
     */
    private function surfaceInventory(array $currentOrgIds): Collection
    {
        $articles = Article::query()
            ->whereIn('org_id', $currentOrgIds)
            ->where('status', 'published')
            ->where('is_public', true)
            ->with('seoMeta')
            ->get()
            ->map(fn (Article $article): array => $this->surfaceRecord('article', $article, 'Current org'));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->with('seoMeta')
            ->get()
            ->map(fn (CareerGuide $guide): array => $this->surfaceRecord('guide', $guide, 'Global content'));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->with('seoMeta')
            ->get()
            ->map(fn (CareerJob $job): array => $this->surfaceRecord('job', $job, 'Global content'));

        return collect()
            ->concat($articles)
            ->concat($guides)
            ->concat($jobs)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceRecord(string $type, object $record, string $scope): array
    {
        $publicPath = $this->publicPath($type, $record);
        $publicUrl = $this->frontendBaseUrl().$publicPath;
        $canonical = trim((string) data_get($record, 'seoMeta.canonical_url', ''));

        return [
            'type' => match ($type) {
                'article' => 'Article',
                'guide' => 'Career Guide',
                'job' => 'Career Job',
                default => 'Content',
            },
            'scope' => $scope,
            'locale' => trim((string) data_get($record, 'locale', 'en')),
            'slug' => trim((string) data_get($record, 'slug', '')),
            'title' => trim((string) data_get($record, 'title', 'Untitled')),
            'public_path' => $publicPath,
            'public_url' => $publicUrl,
            'canonical_ready' => $canonical !== '',
            'indexable' => (bool) data_get($record, 'is_indexable'),
            'published_at' => data_get($record, 'published_at'),
        ];
    }

    private function publicPath(string $type, object $record): string
    {
        $slug = rawurlencode(trim((string) data_get($record, 'slug', '')));
        $segment = $this->localeSegment(trim((string) data_get($record, 'locale', 'en')));

        return match ($type) {
            'article' => '/'.$segment.'/articles/'.$slug,
            'guide' => '/'.$segment.'/career/guides/'.$slug,
            'job' => '/'.$segment.'/career/jobs/'.$slug,
            default => '/'.$segment,
        };
    }

    /**
     * @param  list<string>  $candidates
     * @param  array<string,mixed>  $surface
     */
    private function matchesSurface(array $surface, array $candidates): bool
    {
        $path = mb_strtolower((string) $surface['public_path'], 'UTF-8');
        $url = mb_strtolower((string) $surface['public_url'], 'UTF-8');
        $slug = mb_strtolower((string) $surface['slug'], 'UTF-8');

        foreach ($candidates as $candidate) {
            $normalized = mb_strtolower(trim($candidate), 'UTF-8');
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, $path) || str_contains($normalized, $url)) {
                return true;
            }

            if ($slug !== '' && str_contains($normalized, '/'.$slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function eventCandidates(Event $event): array
    {
        $meta = is_array($event->meta_json) ? $event->meta_json : [];

        return array_values(array_filter([
            trim((string) data_get($meta, 'landing_path', '')),
            trim((string) data_get($meta, 'entry_page', '')),
            trim((string) data_get($meta, 'referrer', '')),
            trim((string) data_get($meta, 'ref', '')),
            trim((string) data_get($meta, 'entrypoint', '')),
        ]));
    }

    /**
     * @return list<string>
     */
    private function orderCandidates(Order $order): array
    {
        $attribution = $this->orders->extractAttributionFromOrder($order);

        return array_values(array_filter([
            trim((string) ($attribution['landing_path'] ?? '')),
            trim((string) ($attribution['referrer'] ?? '')),
            trim((string) ($attribution['entrypoint'] ?? '')),
        ]));
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, Order>  $orders
     */
    private function shareTouchpoints(Collection $events, Collection $orders): int
    {
        $touchpoints = [];

        foreach ($events as $event) {
            $shareId = trim((string) ($event->share_id ?? ''));
            if ($shareId !== '') {
                $touchpoints['share:'.$shareId] = true;
            }

            $meta = is_array($event->meta_json) ? $event->meta_json : [];
            $shareClickId = trim((string) data_get($meta, 'share_click_id', ''));
            if ($shareClickId !== '') {
                $touchpoints['click:'.$shareClickId] = true;
            }
        }

        foreach ($orders as $order) {
            $attribution = $this->orders->extractAttributionFromOrder($order);
            $shareId = trim((string) ($attribution['share_id'] ?? ''));
            $shareClickId = trim((string) ($attribution['share_click_id'] ?? ''));

            if ($shareId !== '') {
                $touchpoints['share:'.$shareId] = true;
            }
            if ($shareClickId !== '') {
                $touchpoints['click:'.$shareClickId] = true;
            }
        }

        return count($touchpoints);
    }

    private function isShareAssistedOrder(Order $order): bool
    {
        $attribution = $this->orders->extractAttributionFromOrder($order);

        return trim((string) ($attribution['share_id'] ?? '')) !== ''
            || trim((string) ($attribution['share_click_id'] ?? '')) !== '';
    }

    /**
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, Order>  $orders
     */
    private function latestTouchAt(Collection $events, Collection $orders): ?Carbon
    {
        $timestamps = collect()
            ->concat($events->map(fn (Event $event): ?Carbon => $event->occurred_at instanceof Carbon ? $event->occurred_at : null))
            ->concat($orders->map(function (Order $order): ?Carbon {
                $paidAt = $order->paid_at instanceof Carbon ? $order->paid_at : null;

                return $paidAt ?? ($order->updated_at instanceof Carbon ? $order->updated_at : null);
            }))
            ->filter(fn (?Carbon $value): bool => $value instanceof Carbon)
            ->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())
            ->values();

        $latest = $timestamps->first();

        return $latest instanceof Carbon ? $latest : null;
    }

    /**
     * @param  Collection<int, array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function diagnosticCard(string $title, string $description, Collection $rows, string $meta): array
    {
        $latest = $rows
            ->sortByDesc(fn (array $row): int => ($row['paid_orders'] * 1000) + $row['signals'])
            ->first();

        return [
            'title' => $title,
            'description' => $description,
            'meta' => $meta.' | '.(string) $rows->count().' surfaces',
            'value' => (string) $rows->count(),
            'status' => $rows->isNotEmpty() ? 'Needs action' : 'Healthy',
            'status_state' => $rows->isNotEmpty() ? 'warning' : 'success',
            'latest_title' => is_array($latest) ? (string) ($latest['title'] ?? 'No recent record') : 'No recent record',
        ];
    }

    private function localeSegment(string $locale): string
    {
        return str_starts_with(mb_strtolower(trim($locale), 'UTF-8'), 'zh') ? 'zh' : 'en';
    }

    private function frontendBaseUrl(): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', 'https://example.test')), '/');

        return $base !== '' ? $base : 'https://example.test';
    }

    private function formatCurrencyCents(int $value): string
    {
        return '$'.number_format($value / 100, 2);
    }
}
