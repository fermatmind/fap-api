<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar :split="false" class="ops-toolbar--center-actions">
                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_review') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.release_queue') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.post_release_observability.telemetry_title')"
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" :show-hints="false" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.post_release_observability.published_title')"
        >
            <div class="ops-card-list">
                @forelse ($releaseCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.post_release_observability.slug_label', ['slug' => $card['latest_title'] !== '' ? $card['latest_title'] : __('ops.custom_pages.post_release_observability.unavailable')]) }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.post_release_observability.eyebrow')"
                        icon="heroicon-o-signal"
                        :title="__('ops.custom_pages.post_release_observability.empty_published_title')"
                        :description="__('ops.custom_pages.post_release_observability.empty_published_desc')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.post_release_observability.events_title')"
        >
            <div class="ops-card-list">
                @forelse ($auditCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">{{ $card['trace'] }}</p>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.post_release_observability.audit_time', ['time' => $card['latest_title']]) }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        eyebrow=""
                        icon="heroicon-o-clipboard-document-check"
                        :title="__('ops.custom_pages.post_release_observability.empty_audits_title')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
