<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.translation_ops.eyebrow')"
            :title="__('ops.translation_ops.heading')"
            :description="__('ops.translation_ops.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.authority_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.translation_ops.authority_revision') }}</p>
                    <p class="ops-control-hint">{{ __('ops.translation_ops.authority_provider') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" type="button" wire:click="resetFilters">
                        {{ __('ops.translation_ops.reset_filters') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.summary.title')" :description="__('ops.translation_ops.summary.description')">
            <x-filament-ops::ops-coverage-summary-cards :cards="$summaryCards" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.filters_title')" :description="__('ops.translation_ops.filters_description')">
            <div class="ops-toolbar-grid ops-toolbar-grid--translation">
                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.content_type') }}</span>
                    <select class="fi-select-input" wire:model.live="contentTypeFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_content_types') }}</option>
                        @foreach (($filterOptions['content_types'] ?? []) as $type)
                            <option value="{{ $type }}">{{ __('ops.translation_ops.content_types.'.$type) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.slug') }}</span>
                    <input class="fi-input" type="search" wire:model.live.debounce.350ms="slugSearch" placeholder="{{ __('ops.translation_ops.filters.search_slug') }}" />
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.target_locale') }}</span>
                    <select class="fi-select-input" wire:model.live="targetLocaleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_target_locales') }}</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.translation_status') }}</span>
                    <select class="fi-select-input" wire:model.live="translationStatusFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_statuses') }}</option>
                        @foreach (($filterOptions['statuses'] ?? []) as $status)
                            <option value="{{ $status }}">{{ \App\Filament\Ops\Support\StatusBadge::label($status) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.freshness') }}</span>
                    <select class="fi-select-input" wire:model.live="staleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_freshness') }}</option>
                        <option value="stale">{{ __('ops.translation_ops.filters.stale_only') }}</option>
                        <option value="current">{{ __('ops.translation_ops.filters.current_only') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.publication') }}</span>
                    <select class="fi-select-input" wire:model.live="publishedFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_publication_states') }}</option>
                        <option value="published">{{ __('ops.translation_ops.filters.has_published_locale') }}</option>
                        <option value="unpublished">{{ __('ops.translation_ops.filters.no_published_locale') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.more_filters') }}</span>
                    <select class="fi-select-input" wire:model.live="sourceLocaleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_source_locales') }}</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.ownership') }}</span>
                    <select class="fi-select-input" wire:model.live="ownershipFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_ownership') }}</option>
                        <option value="mismatch">{{ __('ops.translation_ops.filters.mismatch_only') }}</option>
                        <option value="ok">{{ __('ops.translation_ops.filters.ok_only') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.missing_locale') }}</span>
                    <span class="ops-toolbar-inline">
                        <input type="checkbox" wire:model.live="missingLocaleFilter" />
                        <span class="ops-control-hint">{{ __('ops.translation_ops.filters.missing_locale_hint') }}</span>
                    </span>
                </label>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.matrix.title')" :description="__('ops.translation_ops.matrix.description')">
            <x-filament-ops::ops-coverage-matrix :columns="$localeColumns" :rows="$coverageMatrix" />
        </x-filament-ops::ops-section>

        @if ($selectedGroup)
            <x-filament-ops::ops-section
                :title="__('ops.translation_ops.inspect_title', ['slug' => $selectedGroup['slug']])"
                :description="__('ops.translation_ops.inspect_description')"
            >
                <x-filament-ops::ops-translation-group-detail :group="$selectedGroup" />
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
