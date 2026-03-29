<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SEO operations"
            title="SEO operations"
            description="Operate the visible CMS SEO footprint across current-org articles and global career content without mixing in support search or content-pack control plane work."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">SEO contract</span>
                    <p class="ops-control-hint">This page only measures SEO fields that already exist in the visible CMS models and authoring workspaces.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        Content Metrics
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">
                        Growth Attribution
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        Content Search
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        Editorial Ops
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            Editorial Review
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="SEO readiness"
            description="Headline coverage across selected-org articles and global career content."
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Coverage details"
            description="Canonical, social, and robots coverage using the current SEO metadata tables."
        >
            <x-filament-ops::ops-field-grid :fields="$coverageFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Attention queue"
            description="Use these cards to identify which visible content surfaces still need SEO cleanup."
        >
            <div class="ops-card-list">
                @foreach ($attentionCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Latest record: {{ $card['latest_title'] }}</p>
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
