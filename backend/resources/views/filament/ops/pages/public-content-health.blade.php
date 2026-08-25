<x-filament-panels::page>
    <div class="ops-shell-page">
        @if ($sourceErrors !== [])
            <x-filament-ops::ops-section
                :title="__('public-content-health.source_errors.title')"
                :description="__('public-content-health.source_errors.description')"
            >
                <div class="ops-card-list">
                    @foreach ($sourceErrors as $errorCode)
                        <x-filament-ops::ops-result-card
                            :title="__('public-content-health.source_errors.'.$errorCode)"
                            :meta="$errorCode"
                        >
                            <p class="ops-control-hint">{{ __('public-content-health.source_errors.safe_hint') }}</p>
                            <x-slot name="actions">
                                <x-filament.ops.shared.status-pill
                                    state="failed"
                                    :label="__('public-content-health.states.unavailable')"
                                />
                            </x-slot>
                        </x-filament-ops::ops-result-card>
                    @endforeach
                </div>
            </x-filament-ops::ops-section>
        @endif

        <x-filament-ops::ops-section
            :title="__('public-content-health.overview.title')"
        >
            <x-filament-ops::ops-field-grid class="ops-field-grid--centered" :fields="$overviewFields" :show-hints="false" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('public-content-health.runtime.title')"
        >
            <div class="ops-card-list">
                @forelse ($runtimeCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('public-content-health.runtime.empty_eyebrow')"
                        icon="heroicon-o-chart-bar"
                        :title="__('public-content-health.runtime.empty_title')"
                        :description="__('public-content-health.runtime.empty_description')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('public-content-health.probe.title')"
        >
            <div class="ops-card-list">
                @forelse ($probeCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('public-content-health.probe.empty_eyebrow')"
                        icon="heroicon-o-signal"
                        :title="__('public-content-health.probe.empty_title')"
                        :description="__('public-content-health.probe.empty_description')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('public-content-health.publication.title')"
        >
            <div class="ops-card-list">
                @forelse ($publicationCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('public-content-health.publication.empty_eyebrow')"
                        icon="heroicon-o-document-magnifying-glass"
                        :title="__('public-content-health.publication.empty_title')"
                        :description="__('public-content-health.publication.empty_description')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('public-content-health.boundary.title')"
        >
            <x-filament-ops::ops-field-grid class="ops-field-grid--centered-head" :fields="$boundaryFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
