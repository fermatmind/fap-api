@php
    use App\Filament\Ops\Support\SeoOperationsUiState;
    use App\Filament\Ops\Support\SeoTechnicalHealthUiContract;

    $snapshot = SeoTechnicalHealthUiContract::snapshot();
    $copy = 'ops.custom_pages.seo_operations.technical_health';
@endphp

<div class="ops-technical-health" data-contract-state="{{ $snapshot['state'] }}">
    <x-filament-ops::ops-trust-strip
        :label="__($copy.'.trust_label')"
        :items="collect($snapshot['trust'])->map(fn (array $item): array => [
            'label' => __($copy.'.trust.'.$item['label_key']),
            'state' => $item['state'],
            'value' => SeoOperationsUiState::metricValue($item['value'], $item['state']),
            'detail' => __($copy.'.trust.'.$item['detail_key']),
        ])->all()"
    />

    <div class="ops-technical-health__grid">
        <section class="ops-technical-health__panel ops-technical-health__panel--trend" aria-labelledby="technical-reliability-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.reliability.eyebrow') }}</span>
                    <h2 id="technical-reliability-title">{{ __($copy.'.reliability.title') }}</h2>
                </div>
                <span class="ops-tag">24h</span>
            </div>
            <x-filament-ops::ops-state-message
                :state="data_get($snapshot, 'scheduler_window.state', SeoOperationsUiState::MEASUREMENT_HOLD)"
                :title="__('ops.custom_pages.seo_operations.states.production_unproven.label')"
                :description="__($copy.'.reliability.hold')"
            />
            <div class="ops-tag-list">
                @foreach ((array) data_get($snapshot, 'trend', []) as $point)
                    <span class="ops-tag">{{ $point['scheduled_for'] ?? '—' }} · {{ $point['status'] ?? '—' }} · {{ $point['crawler_hit_count'] ?? '—' }}</span>
                @endforeach
            </div>
        </section>

        <section class="ops-technical-health__panel ops-technical-health__panel--clusters" aria-labelledby="technical-clusters-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.clusters.eyebrow') }}</span>
                    <h2 id="technical-clusters-title">{{ __($copy.'.clusters.title') }}</h2>
                </div>
            </div>
            <div class="ops-technical-health__cluster-head" aria-hidden="true">
                @foreach (['severity', 'detector', 'family', 'blast_radius', 'observed', 'status'] as $column)
                    <span>{{ __($copy.'.clusters.columns.'.$column) }}</span>
                @endforeach
            </div>
            @forelse ((array) data_get($snapshot, 'clusters', []) as $cluster)
                <div class="ops-technical-health__cluster-head">
                    <span>{{ $cluster['severity'] }}</span>
                    <span>{{ $cluster['detector'] }}</span>
                    <span>{{ $cluster['page_family'] }} · {{ $cluster['locale'] }}</span>
                    <span>{{ $cluster['affected_count'] }}</span>
                    <span>{{ $cluster['first_observed_at'] ?? '—' }} / {{ $cluster['last_observed_at'] ?? '—' }}</span>
                    <span>{{ $cluster['status'] }}</span>
                </div>
            @empty
                <x-filament-ops::ops-state-message :state="$snapshot['state']" :title="__($copy.'.clusters.hold_title')" :description="''" />
            @endforelse
        </section>

        <section class="ops-technical-health__panel" aria-labelledby="technical-evidence-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.evidence.eyebrow') }}</span>
                    <h2 id="technical-evidence-title">{{ __($copy.'.evidence.title') }}</h2>
                </div>
            </div>
            <ol class="ops-technical-health__evidence-chain">
                @foreach ($snapshot['evidence'] as $step)
                    <li>
                        <span class="ops-technical-health__step">{{ $loop->iteration }}</span>
                        <div>
                            <strong>{{ __($copy.'.evidence.steps.'.$step) }}</strong>
                            <small>{{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</small>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="ops-technical-health__panel" aria-labelledby="technical-samples-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.samples.eyebrow') }}</span>
                    <h2 id="technical-samples-title">{{ __($copy.'.samples.title') }}</h2>
                </div>
            </div>
            <x-filament-ops::ops-state-message
                :state="SeoOperationsUiState::UNAVAILABLE"
                :title="__('ops.custom_pages.seo_operations.states.unavailable.label')"
                :description="''"
            />
        </section>
    </div>
</div>
