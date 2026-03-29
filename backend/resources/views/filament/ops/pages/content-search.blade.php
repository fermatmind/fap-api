<x-filament-panels::page>
    @php
        $hasQuery = trim($query) !== '';
        $emptyTitle = $hasQuery ? 'No content matched the current query' : 'Start with a content search';
        $emptyDescription = $hasQuery
            ? 'Try another title, slug, excerpt, category, or tag keyword to widen the content search scope.'
            : 'Search across articles, taxonomy, career guides, and career jobs without leaving the CMS product layer.';
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Content search"
            title="Content search"
            description="Search the visible CMS modules, isolate stale lifecycle candidates, and apply lifecycle actions without leaving the Ops CMS surface."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-content-search-input">Search by title / slug / excerpt / category / tag</label>
                    <input
                        id="ops-content-search-input"
                        type="text"
                        wire:model.defer="query"
                        placeholder="article title, slug, category, tag, guide, job"
                        class="ops-input"
                    />
                    <p class="ops-control-hint">Results stay inside current CMS boundaries: articles/taxonomy are current-org, career guides/jobs are global. Lifecycle actions only target editorial content.</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                            Metrics
                        </x-filament::button>
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                            SEO Ops
                        </x-filament::button>

                        <label class="ops-control-stack" for="ops-content-search-type">
                            <span class="ops-control-label">Type</span>
                            <select id="ops-content-search-type" wire:model="typeFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="article">Article</option>
                                <option value="category">Category</option>
                                <option value="tag">Tag</option>
                                <option value="guide">Career Guide</option>
                                <option value="job">Career Job</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="ops-content-search-lifecycle">
                            <span class="ops-control-label">Lifecycle</span>
                            <select id="ops-content-search-lifecycle" wire:model="lifecycleFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="active">Active</option>
                                <option value="downranked">Down-ranked</option>
                                <option value="archived">Archived</option>
                                <option value="soft_deleted">Soft deleted</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="ops-content-search-stale">
                            <span class="ops-control-label">Freshness</span>
                            <select id="ops-content-search-stale" wire:model="staleFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="only_stale">Only stale</option>
                                <option value="only_fresh">Only fresh</option>
                            </select>
                        </label>

                        <x-filament::button color="primary" wire:click="runSearch">
                            Search
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Results"
            description="Open a result directly in its native resource surface, or use batch lifecycle actions on editorial records."
        >
            <x-slot name="actions">
                <div class="ops-toolbar-inline">
                    <span class="ops-results-header__meta">{{ $elapsedMs }} ms</span>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <label class="ops-control-stack" for="ops-content-search-bulk-action">
                            <span class="ops-control-label">Bulk action</span>
                            <select id="ops-content-search-bulk-action" wire:model="bulkAction" class="ops-input">
                                <option value="archive">Archive</option>
                                <option value="soft_delete">Soft delete</option>
                                <option value="down_rank">Down-rank</option>
                            </select>
                        </label>
                        <x-filament::button color="gray" wire:click="applyBulkAction">
                            Apply
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>

            <div class="ops-card-list">
                @forelse ($items as $item)
                    <x-filament-ops::ops-result-card
                        :title="(string) ($item['label'] ?? '-')"
                        :meta="(string) ($item['type'] ?? '-') . ' | ' . (string) ($item['scope'] ?? '-') . ' | status=' . (string) ($item['status'] ?? '-') . ' | lifecycle=' . (string) ($item['lifecycle_state'] ?? 'active') . ' | stale=' . ((bool) ($item['is_stale'] ?? false) ? 'yes' : 'no') . (((string) ($item['subtitle'] ?? '')) !== '' ? ' | ' . (string) ($item['subtitle'] ?? '') : '')"
                    >
                        @if (($item['actionable'] ?? false) && \App\Filament\Ops\Support\ContentAccess::canRelease())
                            <label class="ops-control-stack">
                                <span class="ops-control-label">Select for lifecycle batch</span>
                                <input
                                    type="checkbox"
                                    wire:model="selectedTargets"
                                    value="{{ (string) ($item['selection_key'] ?? '') }}"
                                />
                            </label>
                        @endif
                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ (string) ($item['url'] ?? '/ops') }}">
                                Open
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Content search"
                        icon="heroicon-o-magnifying-glass"
                        :title="$emptyTitle"
                        :description="$emptyDescription"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
