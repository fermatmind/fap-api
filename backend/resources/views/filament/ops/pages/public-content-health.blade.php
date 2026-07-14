<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('public-content-health.eyebrow')"
            :title="__('public-content-health.title')"
            :description="__('public-content-health.description')"
        >
            <p class="ops-control-hint">
                {{ __('public-content-health.generated_at', ['time' => $generatedAt]) }}
            </p>
        </x-filament-ops::ops-section>

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
            :description="__('public-content-health.overview.description')"
        >
            <x-filament-ops::ops-field-grid :fields="$overviewFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('public-content-health.runtime.title')"
            :description="__('public-content-health.runtime.section_description', ['minutes' => $windowMinutes])"
        >
            <div class="ops-card-list">
                @forelse ($runtimeCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
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
            :description="__('public-content-health.probe.section_description')"
        >
            <div class="ops-card-list">
                @forelse ($probeCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
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
            :description="__('public-content-health.publication.section_description')"
        >
            <div class="ops-card-list">
                @forelse ($publicationCards as $card)
                    <x-filament-ops::ops-result-card :title="$card['title']" :meta="$card['meta']">
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
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
            :description="__('public-content-health.boundary.description')"
        >
            <x-filament-ops::ops-field-grid :fields="$boundaryFields" />
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
