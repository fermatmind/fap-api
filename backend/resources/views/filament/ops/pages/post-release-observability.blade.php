<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Post-release observability"
            title="Post-release observability"
            description="Observe what the CMS release surface has recently published, which follow-up signals were dispatched, and where cache invalidation or broadcast steps failed."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Observability contract</span>
                    <p class="ops-control-hint">This page stays inside the CMS release boundary. It does not replace broader Ops observability or deploy telemetry.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                        Editorial Review
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                        Release Queue
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release telemetry"
            description="A compact view of release events, cache invalidation signals, and broadcast outcomes inside the selected CMS boundary."
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Recently published records"
            description="The most recent published content objects visible to this boundary."
        >
            <div class="ops-card-list">
                @forelse ($releaseCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Slug: {{ $card['latest_title'] !== '' ? $card['latest_title'] : 'Unavailable' }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Post-release observability"
                        icon="heroicon-o-signal"
                        title="No published records yet"
                        description="Recently published content will appear here after the CMS release queue writes publish state."
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Recent release events"
            description="Publish, cache invalidation, broadcast, and failure-alert audit rows for the current org boundary."
        >
            <div class="ops-card-list">
                @forelse ($auditCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Audit time: {{ $card['latest_title'] }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow="Release audits"
                        icon="heroicon-o-clipboard-document-check"
                        title="No publish audits yet"
                        description="Once content is published through the CMS release workspace, audit events will appear here."
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
