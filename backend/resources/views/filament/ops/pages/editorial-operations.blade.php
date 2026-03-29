<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Editorial operations"
            title="Editorial operations"
            description="Operate the article and career authoring surfaces through one editorial control layer before handing approved records into the release queue."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Editorial contract</span>
                    <p class="ops-control-hint">This page is the operational shell for visible editorial modules only. Search, metrics, SEO ops, and post-release observability stay out of this loop.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        Content Metrics
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        SEO Operations
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        Content Search
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            Editorial Review
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        Workspace
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            Release Queue
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Operations snapshot"
            description="Keep the org-scoped article surface and the global career surfaces visible in one place."
        >
            <x-filament-ops::ops-field-grid :fields="$snapshotFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Editorial surfaces"
            description="Jump into the core authoring surfaces that belong to the production CMS bootstrap."
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
                                Open
                            </x-filament::button>
                            @if ($card['can_write'])
                                <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['create_url'] }}">
                                    Create
                                </x-filament::button>
                            @endif
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Editorial operations"
                        icon="heroicon-o-pencil-square"
                        title="No editorial surfaces available"
                        description="Visible editorial resources will appear here once the CMS bootstrap surfaces are enabled."
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Boundary handoff"
            description="Keep the current production bootstrap scope explicit: author here, publish in the release surface."
        >
            <x-filament-ops::ops-field-grid :fields="$boundaryFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
