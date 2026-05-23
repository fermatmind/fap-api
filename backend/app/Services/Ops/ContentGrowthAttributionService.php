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
    public const MAX_SURFACES_PER_TYPE = 200;

    public const MAX_EVENTS = 1000;

    public const MAX_ORDERS = 500;

    public const MAX_SHARE_ALIASES_PER_SURFACE = 100;

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
    public function build(array $currentOrgIds, bool $includeCommerceMetrics = true): array
    {
        $orgId = max(0, (int) ($currentOrgIds[0] ?? 0));
        $lookbackThreshold = Carbon::now()->subDays(30);
        $surfaces = $this->surfaceInventory($currentOrgIds);

        $events = Event::query()
            ->withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('occurred_at', '>=', $lookbackThreshold)
            ->latest('occurred_at')
            ->limit(self::MAX_EVENTS)
            ->get();

        $orders = $includeCommerceMetrics
            ? Order::query()
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
                ->latest('updated_at')
                ->limit(self::MAX_ORDERS)
                ->get()
            : collect();

        $matrixRows = $surfaces
            ->map(function (array $surface) use ($events, $orders, $includeCommerceMetrics): array {
                $matchingEvents = $events
                    ->filter(fn (Event $event): bool => $this->matchesSurface($surface, $this->eventCandidates($event)))
                    ->values();

                $matchingOrders = $orders
                    ->filter(fn (Order $order): bool => $this->matchesSurface($surface, $this->orderCandidates($order)))
                    ->values();

                $seedAliases = $this->shareAliases($matchingEvents, $matchingOrders);

                if ($seedAliases !== []) {
                    $matchingEvents = $events
                        ->filter(function (Event $event) use ($surface, $seedAliases): bool {
                            return $this->matchesSurface($surface, $this->eventCandidates($event))
                                || $this->hasAnyAlias($this->eventShareAliases($event), $seedAliases);
                        })
                        ->values();

                    $matchingOrders = $orders
                        ->filter(function (Order $order) use ($surface, $seedAliases): bool {
                            return $this->matchesSurface($surface, $this->orderCandidates($order))
                                || $this->hasAnyAlias($this->orderShareAliases($order), $seedAliases);
                        })
                        ->values();
                }

                $shareTouchpoints = $this->shareTouchpoints($matchingEvents, $matchingOrders);
                $paidOrders = $includeCommerceMetrics ? $matchingOrders->count() : 0;
                $shareAssistedOrders = $includeCommerceMetrics
                    ? $matchingOrders
                        ->filter(fn (Order $order): bool => $this->isShareAssistedOrder($order))
                        ->count()
                    : 0;
                $revenueCents = $includeCommerceMetrics
                    ? (int) $matchingOrders->sum(function (Order $order): int {
                        return (int) ($order->amount_cents ?? $order->amount_total ?? 0);
                    })
                    : 0;
                $signalCount = $matchingEvents->count();
                $lastTouch = $this->latestTouchAt($matchingEvents, $matchingOrders);
                $canonicalReady = $surface['canonical_ready'];
                $indexable = $surface['indexable'];
                $growthState = $paidOrders > 0
                    ? __('ops.custom_pages.content_growth_attribution.states.converting')
                    : ($signalCount > 0
                        ? __('ops.custom_pages.content_growth_attribution.states.discovery')
                        : __('ops.custom_pages.content_growth_attribution.states.dormant'));
                $growthStateLabel = $indexable
                    ? ($canonicalReady
                        ? __('ops.custom_pages.content_growth_attribution.states.indexable')
                        : __('ops.custom_pages.content_growth_attribution.states.canonical_gap'))
                    : __('ops.custom_pages.content_growth_attribution.states.noindex');

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
                    'revenue_cents' => $includeCommerceMetrics ? $revenueCents : null,
                    'revenue_label' => $includeCommerceMetrics ? $this->formatCurrencyCents($revenueCents) : __('ops.custom_pages.content_growth_attribution.restricted'),
                    'growth_state' => $growthState,
                    'growth_state_label' => $growthState.' | '.$growthStateLabel,
                    'last_touch_at' => $lastTouch,
                    'last_touch_label' => $lastTouch?->toDateTimeString() ?? __('ops.custom_pages.content_growth_attribution.no_recent_touch'),
                    'published_at' => $surface['published_at'],
                    'canonical_ready' => $canonicalReady,
                    'indexable' => $indexable,
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
                'label' => __('ops.custom_pages.content_growth_attribution.fields.indexable_public_surfaces'),
                'value' => (string) $matrixRows
                    ->filter(fn (array $row): bool => (bool) ($row['indexable'] ?? false) && (bool) ($row['canonical_ready'] ?? false))
                    ->count(),
                'hint' => __('ops.custom_pages.content_growth_attribution.fields.indexable_public_surfaces_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_growth_attribution.fields.attributed_surfaces'),
                'value' => (string) $matrixRows
                    ->filter(fn (array $row): bool => $row['signals'] > 0 || $row['paid_orders'] > 0)
                    ->count(),
                'hint' => __('ops.custom_pages.content_growth_attribution.fields.attributed_surfaces_hint'),
            ],
            [
                'label' => __('ops.custom_pages.content_growth_attribution.fields.tracked_share_touchpoints'),
                'value' => (string) $matrixRows->sum('share_touchpoints'),
                'hint' => __('ops.custom_pages.content_growth_attribution.fields.tracked_share_touchpoints_hint'),
            ],
        ];

        if ($includeCommerceMetrics) {
            $headlineFields[] = [
                'label' => __('ops.custom_pages.content_growth_attribution.fields.paid_conversions'),
                'value' => (string) $matrixRows->sum('paid_orders'),
                'hint' => __('ops.custom_pages.content_growth_attribution.fields.paid_conversions_hint'),
            ];
            $headlineFields[] = [
                'label' => __('ops.custom_pages.content_growth_attribution.fields.attributed_revenue'),
                'value' => $this->formatCurrencyCents((int) $matrixRows->sum('revenue_cents')),
                'hint' => __('ops.custom_pages.content_growth_attribution.fields.attributed_revenue_hint'),
            ];
        }

        $diagnosticCards = [
            $this->diagnosticCard(
                __('ops.custom_pages.content_growth_attribution.diagnostics.converting_noindex.title'),
                __('ops.custom_pages.content_growth_attribution.diagnostics.converting_noindex.description'),
                $matrixRows->where('converting_without_indexability', true),
                __('ops.custom_pages.content_growth_attribution.diagnostics.converting_noindex.meta')
            ),
            $this->diagnosticCard(
                __('ops.custom_pages.content_growth_attribution.diagnostics.canonical_gaps.title'),
                __('ops.custom_pages.content_growth_attribution.diagnostics.canonical_gaps.description'),
                $matrixRows->where('converting_without_canonical', true),
                __('ops.custom_pages.content_growth_attribution.diagnostics.canonical_gaps.meta')
            ),
            $this->diagnosticCard(
                __('ops.custom_pages.content_growth_attribution.diagnostics.share_winners.title'),
                __('ops.custom_pages.content_growth_attribution.diagnostics.share_winners.description'),
                $matrixRows->filter(fn (array $row): bool => $row['share_assisted_orders'] > 0),
                __('ops.custom_pages.content_growth_attribution.diagnostics.share_winners.meta')
            ),
            $this->diagnosticCard(
                __('ops.custom_pages.content_growth_attribution.diagnostics.indexable_dormant.title'),
                __('ops.custom_pages.content_growth_attribution.diagnostics.indexable_dormant.description'),
                $matrixRows->where('indexable_without_signals', true),
                __('ops.custom_pages.content_growth_attribution.diagnostics.indexable_dormant.meta')
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
            ->latest('updated_at')
            ->limit(self::MAX_SURFACES_PER_TYPE)
            ->get()
            ->map(fn (Article $article): array => $this->surfaceRecord('article', $article, __('ops.custom_pages.content_growth_attribution.scopes.current_org')));

        $guides = CareerGuide::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerGuide::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->with('seoMeta')
            ->latest('updated_at')
            ->limit(self::MAX_SURFACES_PER_TYPE)
            ->get()
            ->map(fn (CareerGuide $guide): array => $this->surfaceRecord('guide', $guide, __('ops.custom_pages.content_growth_attribution.scopes.global_content')));

        $jobs = CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('status', CareerJob::STATUS_PUBLISHED)
            ->where('is_public', true)
            ->with('seoMeta')
            ->latest('updated_at')
            ->limit(self::MAX_SURFACES_PER_TYPE)
            ->get()
            ->map(fn (CareerJob $job): array => $this->surfaceRecord('job', $job, __('ops.custom_pages.content_growth_attribution.scopes.global_content')));

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
                'article' => __('ops.custom_pages.content_growth_attribution.types.article'),
                'guide' => __('ops.custom_pages.content_growth_attribution.types.career_guide'),
                'job' => __('ops.custom_pages.content_growth_attribution.types.career_job'),
                default => __('ops.custom_pages.content_growth_attribution.types.content'),
            },
            'scope' => $scope,
            'locale' => trim((string) data_get($record, 'locale', 'en')),
            'slug' => trim((string) data_get($record, 'slug', '')),
            'title' => trim((string) data_get($record, 'title', __('ops.custom_pages.content_growth_attribution.untitled'))),
            'public_path' => $publicPath,
            'public_url' => $publicUrl,
            'canonical_ready' => $canonical !== '' && $this->urlsMatch($canonical, $publicUrl),
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
        return count($this->shareAliases($events, $orders));
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
            'meta' => $meta.' | '.__('ops.custom_pages.content_growth_attribution.surfaces_count', ['count' => (string) $rows->count()]),
            'value' => (string) $rows->count(),
            'status' => $rows->isNotEmpty()
                ? __('ops.custom_pages.content_growth_attribution.status.needs_action')
                : __('ops.custom_pages.content_growth_attribution.status.healthy'),
            'status_state' => $rows->isNotEmpty() ? 'warning' : 'success',
            'latest_title' => is_array($latest)
                ? (string) ($latest['title'] ?? __('ops.custom_pages.content_growth_attribution.no_recent_record'))
                : __('ops.custom_pages.content_growth_attribution.no_recent_record'),
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

    /**
     * @param  Collection<int, Event>  $events
     * @param  Collection<int, Order>  $orders
     * @return list<string>
     */
    private function shareAliases(Collection $events, Collection $orders): array
    {
        $aliases = [];

        foreach ($events as $event) {
            foreach ($this->eventShareAliases($event) as $alias) {
                $aliases[$alias] = true;
                if (count($aliases) >= self::MAX_SHARE_ALIASES_PER_SURFACE) {
                    return array_keys($aliases);
                }
            }
        }

        foreach ($orders as $order) {
            foreach ($this->orderShareAliases($order) as $alias) {
                $aliases[$alias] = true;
                if (count($aliases) >= self::MAX_SHARE_ALIASES_PER_SURFACE) {
                    return array_keys($aliases);
                }
            }
        }

        return array_keys($aliases);
    }

    /**
     * @return list<string>
     */
    private function eventShareAliases(Event $event): array
    {
        $aliases = [];
        $shareId = trim((string) ($event->share_id ?? ''));

        if ($shareId !== '') {
            $aliases[] = 'share:'.$shareId;
        }

        $meta = is_array($event->meta_json) ? $event->meta_json : [];
        $shareClickId = trim((string) data_get($meta, 'share_click_id', ''));

        if ($shareClickId !== '') {
            $aliases[] = 'click:'.$shareClickId;
        }

        return $aliases;
    }

    /**
     * @return list<string>
     */
    private function orderShareAliases(Order $order): array
    {
        $aliases = [];
        $attribution = $this->orders->extractAttributionFromOrder($order);
        $shareId = trim((string) ($attribution['share_id'] ?? ''));
        $shareClickId = trim((string) ($attribution['share_click_id'] ?? ''));

        if ($shareId !== '') {
            $aliases[] = 'share:'.$shareId;
        }

        if ($shareClickId !== '') {
            $aliases[] = 'click:'.$shareClickId;
        }

        return $aliases;
    }

    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $knownAliases
     */
    private function hasAnyAlias(array $aliases, array $knownAliases): bool
    {
        if ($aliases === [] || $knownAliases === []) {
            return false;
        }

        $lookup = array_fill_keys($knownAliases, true);

        foreach ($aliases as $alias) {
            if (isset($lookup[$alias])) {
                return true;
            }
        }

        return false;
    }

    private function urlsMatch(string $left, string $right): bool
    {
        return $this->normalizeUrl($left) === $this->normalizeUrl($right);
    }

    private function normalizeUrl(string $value): string
    {
        return rtrim(mb_strtolower(trim($value), 'UTF-8'), '/');
    }

    private function formatCurrencyCents(int $value): string
    {
        return '$'.number_format($value / 100, 2);
    }
}
