<x-filament-panels::page>
    @php
        $hasQuery = trim($query) !== '';
        $emptyTitle = $hasQuery
            ? __('ops.custom_pages.content_search.empty_query_title')
            : __('ops.custom_pages.content_search.empty_initial_title');
        $emptyDescription = $hasQuery
            ? __('ops.custom_pages.content_search.empty_query_desc')
            : __('ops.custom_pages.content_search.empty_initial_desc');
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.content_search.eyebrow')"
            :title="__('ops.custom_pages.content_search.title')"
            :description="__('ops.custom_pages.content_search.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-content-search-input">{{ __('ops.custom_pages.content_search.search_label') }}</label>
                    <input
                        id="ops-content-search-input"
                        type="text"
                        wire:model.defer="query"
                        placeholder="{{ __('ops.custom_pages.content_search.placeholder') }}"
                        class="ops-input"
                    />
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_search.hint') }}</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.metrics') }}
                        </x-filament::button>
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.seo_ops') }}
                        </x-filament::button>

                        <label class="ops-control-stack" for="ops-content-search-type">
                            <span class="ops-control-label">{{ __('ops.custom_pages.content_search.type') }}</span>
                            <select id="ops-content-search-type" wire:model="typeFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="article">{{ __('ops.custom_pages.common.filters.article') }}</option>
                                <option value="category">{{ __('ops.custom_pages.common.filters.category') }}</option>
                                <option value="tag">{{ __('ops.custom_pages.common.filters.tag') }}</option>
                                <option value="guide">{{ __('ops.custom_pages.common.filters.career_guide') }}</option>
                                <option value="job">{{ __('ops.custom_pages.common.filters.career_job') }}</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="ops-content-search-lifecycle">
                            <span class="ops-control-label">{{ __('ops.custom_pages.content_search.lifecycle') }}</span>
                            <select id="ops-content-search-lifecycle" wire:model="lifecycleFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="active">{{ __('ops.custom_pages.content_search.filters.active') }}</option>
                                <option value="downranked">{{ __('ops.custom_pages.content_search.filters.downranked') }}</option>
                                <option value="archived">{{ __('ops.custom_pages.content_search.filters.archived') }}</option>
                                <option value="soft_deleted">{{ __('ops.custom_pages.content_search.filters.soft_deleted') }}</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="ops-content-search-stale">
                            <span class="ops-control-label">{{ __('ops.custom_pages.content_search.freshness') }}</span>
                            <select id="ops-content-search-stale" wire:model="staleFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="only_stale">{{ __('ops.custom_pages.content_search.filters.only_stale') }}</option>
                                <option value="only_fresh">{{ __('ops.custom_pages.content_search.filters.only_fresh') }}</option>
                            </select>
                        </label>

                        <x-filament::button color="primary" wire:click="runSearch">
                            {{ __('ops.custom_pages.common.actions.search') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_search.results_title')"
            :description="__('ops.custom_pages.content_search.results_desc')"
        >
            <x-slot name="actions">
                <div class="ops-toolbar-inline">
                    <span class="ops-results-header__meta">{{ $elapsedMs }} ms</span>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <label class="ops-control-stack" for="ops-content-search-bulk-action">
                            <span class="ops-control-label">{{ __('ops.custom_pages.content_search.bulk_action') }}</span>
                            <select id="ops-content-search-bulk-action" wire:model="bulkAction" class="ops-input">
                                <option value="archive">{{ __('ops.custom_pages.content_search.filters.archive') }}</option>
                                <option value="soft_delete">{{ __('ops.custom_pages.content_search.filters.soft_delete') }}</option>
                                <option value="down_rank">{{ __('ops.custom_pages.content_search.filters.down_rank') }}</option>
                            </select>
                        </label>
                        <x-filament::button color="gray" wire:click="applyBulkAction">
                            {{ __('ops.custom_pages.common.actions.apply') }}
                        </x-filament::button>
                    @endif
                </div>
            </x-slot>

            <div class="ops-card-list">
                @forelse ($items as $item)
                    <x-filament-ops::ops-result-card
                        :title="(string) ($item['label'] ?? '-')"
                        :meta="(string) ($item['type'] ?? '-') . ' | ' . (string) ($item['scope'] ?? '-') . ' | ' . __('ops.custom_pages.content_search.meta', ['status' => (string) ($item['status'] ?? '-'), 'lifecycle' => (string) ($item['lifecycle_state'] ?? __('ops.custom_pages.common.values.active')), 'stale' => (bool) ($item['is_stale'] ?? false) ? __('ops.custom_pages.content_search.yes') : __('ops.custom_pages.content_search.no')]) . (((string) ($item['subtitle'] ?? '')) !== '' ? ' | ' . (string) ($item['subtitle'] ?? '') : '')"
                    >
                        @if (($item['actionable'] ?? false) && \App\Filament\Ops\Support\ContentAccess::canRelease())
                            <label class="ops-control-stack">
                                <span class="ops-control-label">{{ __('ops.custom_pages.content_search.select_batch') }}</span>
                                <input
                                    type="checkbox"
                                    wire:model="selectedTargets"
                                    value="{{ (string) ($item['selection_key'] ?? '') }}"
                                />
                            </label>
                        @endif
                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ (string) ($item['url'] ?? '/ops') }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.content_search.eyebrow')"
                        icon="heroicon-o-magnifying-glass"
                        :title="$emptyTitle"
                        :description="$emptyDescription"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
