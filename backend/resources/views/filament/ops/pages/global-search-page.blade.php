<x-filament-panels::page>
    @php
        $hasQuery = trim($query) !== '';
        $emptyTitle = $hasQuery
            ? __('ops.custom_pages.global_search.empty_query_title')
            : __('ops.custom_pages.global_search.empty_initial_title');
        $emptyDescription = $hasQuery
            ? __('ops.custom_pages.global_search.empty_query_desc')
            : __('ops.custom_pages.global_search.empty_initial_desc');
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.global_search.eyebrow')"
            :title="__('ops.custom_pages.global_search.title')"
            :description="__('ops.custom_pages.global_search.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-global-search-input">{{ __('ops.custom_pages.global_search.search_label') }}</label>
                    <input
                        id="ops-global-search-input"
                        type="text"
                        wire:model.defer="query"
                        placeholder="{{ __('ops.custom_pages.global_search.placeholder') }}"
                        class="ops-input"
                    />
                    <p class="ops-control-hint">{{ __('ops.custom_pages.global_search.hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="primary" wire:click="runSearch">
                        {{ __('ops.custom_pages.common.actions.search') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.global_search.results_title')"
            :description="__('ops.custom_pages.global_search.results_desc')"
        >
            <x-slot name="actions">
                <span class="ops-results-header__meta">{{ $elapsedMs }} ms</span>
            </x-slot>

            <div class="ops-card-list">
                @forelse ($items as $item)
                    <x-filament-ops::ops-result-card
                        :title="(string) ($item['label'] ?? '-')"
                        :meta="(string) ($item['type'] ?? '-') . (((int) ($item['org_id'] ?? 0) > 0) ? ' | org='.(int) ($item['org_id'] ?? 0) : '') . (((string) ($item['subtitle'] ?? '')) !== '' ? ' | '.(string) ($item['subtitle'] ?? '') : '')"
                    >
                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ (string) ($item['url'] ?? '/ops') }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.global_search.empty_eyebrow')"
                        icon="heroicon-o-magnifying-glass"
                        :title="$emptyTitle"
                        :description="$emptyDescription"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
