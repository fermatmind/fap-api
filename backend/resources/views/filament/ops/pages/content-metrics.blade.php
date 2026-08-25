<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar class="ops-toolbar--center-actions">
                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_ops') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.seo_operations') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_search') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.editorial_review') }}
                        </x-filament::button>
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.release_queue') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_metrics.snapshot_title')"
        >
            <x-filament-ops::ops-field-grid class="ops-field-grid--centered" :fields="$headlineFields" :show-hints="false" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_metrics.boundary_title')"
        >
            <x-filament-ops::ops-field-grid class="ops-field-grid--centered" :fields="$scopeFields" :show-hints="false" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_metrics.freshness_title')"
        >
            <div class="ops-card-list">
                @foreach ($freshnessCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ __('ops.custom_pages.content_metrics.latest_record', ['title' => $card['latest_title'] ?? __('ops.custom_pages.common.values.no_recent_record')]) }}</p>
                        <x-slot name="actions">
                            <div class="ops-toolbar-inline">
                                <x-filament.ops.shared.status-pill
                                    :state="$card['status_state']"
                                    :label="$card['status'].' | '.$card['value']"
                                />
                                @if (($card['can_archive'] ?? false) && ($card['action_type'] ?? null) !== null)
                                    <x-filament::button
                                        size="xs"
                                        color="warning"
                                        type="button"
                                        wire:click="archiveStale('{{ $card['action_type'] }}')"
                                    >
                                        {{ __('ops.custom_pages.common.actions.archive_stale') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
