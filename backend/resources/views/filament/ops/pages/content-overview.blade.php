<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="CMS product layer"
            title="Content overview"
            description="Track the production CMS bootstrap boundary across org-scoped editorial, global career content, taxonomy, and the lightweight release surface."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Workspace contract</span>
                    <p class="ops-control-hint">This surface keeps content authoring, content data, and content release visible in one product layer instead of scattered resource lists.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        Editorial Ops
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        Content Metrics
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">
                        Growth Attribution
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
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\PostReleaseObservabilityPage::getUrl() }}">
                            Release Observability
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        Open Workspace
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            Open Release
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Workspace health"
            description="A compact summary of the visible CMS modules that are actually part of the production workspace."
        >
            <x-filament-ops::ops-field-grid :fields="$summaryFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Recent surfaces"
            description="Use these shortcuts to jump into the last active part of the content system."
        >
            <div class="ops-card-list">
                @forelse ($recentItems as $item)
                    <x-filament-ops::ops-result-card
                        :title="$item['title']"
                        :meta="$item['label'].' | '.$item['meta']"
                    >
                        <x-slot name="actions">
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['url'] }}">
                                Open
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Content overview"
                        icon="heroicon-o-clipboard-document-list"
                        title="No content records yet"
                        description="Recent CMS activity will appear here once editorial or taxonomy records exist."
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
