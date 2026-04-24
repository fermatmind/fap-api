<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.editorial_operations.eyebrow')"
            :title="__('ops.custom_pages.editorial_operations.title')"
            :description="__('ops.custom_pages.editorial_operations.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.editorial_operations.contract_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.editorial_operations.contract_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_metrics') }}
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
                    @endif
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.workspace') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.release_queue') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.editorial_operations.snapshot_title')"
            :description="__('ops.custom_pages.editorial_operations.snapshot_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$snapshotFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.editorial_operations.surfaces_title')"
            :description="__('ops.custom_pages.editorial_operations.surfaces_desc')"
        >
            <div class="ops-card-list">
                @forelse ($surfaceCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['scope'].' | '.$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $card['index_url'] }}">
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    {{ __('ops.custom_pages.common.actions.create') }}
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.editorial_operations.eyebrow')"
                        icon="heroicon-o-pencil-square"
                        :title="__('ops.custom_pages.editorial_operations.empty_title')"
                        :description="__('ops.custom_pages.editorial_operations.empty_desc')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.editorial_operations.boundary_title')"
            :description="__('ops.custom_pages.editorial_operations.boundary_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$boundaryFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
