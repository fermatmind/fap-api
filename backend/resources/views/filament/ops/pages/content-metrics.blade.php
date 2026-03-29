<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Content metrics"
            title="Content metrics"
            description="Track the visible CMS footprint across current-org articles, global career content, taxonomy, and release pressure."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Metrics contract</span>
                    <p class="ops-control-hint">This page measures only the production CMS bootstrap surfaces. Support search, SEO operations, and post-release observability stay separate.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        Editorial Ops
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
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            Release Queue
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Metric snapshot"
            description="A compact KPI layer for the production CMS footprint."
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Boundary health"
            description="Keep org-scoped and global content boundaries explicit while watching publish coverage and visibility mismatches."
        >
            <x-filament-ops::ops-field-grid :fields="$scopeFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Freshness and pressure"
            description="These cards help operators spot stale drafts and public visibility mismatches before opening the full resource surfaces."
        >
            <div class="ops-card-list">
                @foreach ($freshnessCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Latest record: {{ $card['latest_title'] ?? 'No recent record' }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status'].' | '.$card['value']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
