@php
    use App\Filament\Ops\Support\SeoOperationsUiState;
    use App\Filament\Ops\Support\SeoWorkbenchUiContract;

    $snapshot = SeoWorkbenchUiContract::unavailableSnapshot();
    $copy = 'ops.custom_pages.seo_operations.workbench';
@endphp

<div
    class="ops-seo-workbench-home"
    data-contract-state="{{ $snapshot['state'] }}"
    data-default-decision-count="{{ SeoWorkbenchUiContract::DEFAULT_DECISION_COUNT }}"
    data-max-decision-count="{{ SeoWorkbenchUiContract::MAX_DECISION_COUNT }}"
>
    <section class="ops-seo-workbench-home__trend" aria-labelledby="seo-workbench-trend-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.trend.eyebrow') }}</span>
                <h2 id="seo-workbench-trend-title">{{ __($copy.'.trend.title') }}</h2>
                <p>{{ __($copy.'.trend.description') }}</p>
            </div>
            <span class="ops-tag">{{ $snapshot['trend']['window'] }}</span>
        </div>
        <div class="ops-seo-workbench-home__metrics">
            @foreach (['clicks', 'impressions', 'ctr', 'position'] as $metric)
                <div>
                    <span>{{ __($copy.'.trend.metrics.'.$metric) }}</span>
                    <strong class="tnum">{{ SeoOperationsUiState::metricValue($snapshot['trend'][$metric], $snapshot['state']) }}</strong>
                </div>
            @endforeach
        </div>
        <x-filament-ops::ops-state-message
            :state="$snapshot['state']"
            :title="__('ops.custom_pages.seo_operations.states.measurement_hold.label')"
            :description="__($copy.'.trend.hold')"
        />
    </section>

    <section class="ops-seo-workbench-home__decisions" aria-labelledby="seo-workbench-decisions-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.decisions.eyebrow') }}</span>
                <h2 id="seo-workbench-decisions-title">{{ __($copy.'.decisions.title') }}</h2>
                <p>{{ __($copy.'.decisions.description', [
                    'default' => SeoWorkbenchUiContract::DEFAULT_DECISION_COUNT,
                    'max' => SeoWorkbenchUiContract::MAX_DECISION_COUNT,
                ]) }}</p>
            </div>
        </div>
        <div class="ops-seo-workbench-home__decision-head" aria-hidden="true">
            @foreach (['cause', 'scope', 'evidence', 'impact', 'action'] as $column)
                <span>{{ __($copy.'.decisions.columns.'.$column) }}</span>
            @endforeach
        </div>
        <x-filament-ops::ops-state-message
            :state="$snapshot['state']"
            :title="__($copy.'.decisions.hold_title')"
            :description="__($copy.'.decisions.hold_description')"
        />
    </section>

    <section class="ops-seo-workbench-home__health" aria-labelledby="seo-workbench-health-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.health.eyebrow') }}</span>
                <h2 id="seo-workbench-health-title">{{ __($copy.'.health.title') }}</h2>
                <p>{{ __($copy.'.health.description') }}</p>
            </div>
        </div>
        <dl>
            @foreach ($snapshot['health'] as $field => $value)
                <div>
                    <dt>{{ __($copy.'.health.fields.'.$field) }}</dt>
                    <dd class="tnum">{{ SeoOperationsUiState::metricValue($value, $snapshot['state']) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <aside class="ops-seo-workbench-home__inspector" aria-labelledby="seo-workbench-inspector-title">
        <span class="ops-shell-eyebrow">{{ __($copy.'.inspector.eyebrow') }}</span>
        <h2 id="seo-workbench-inspector-title">{{ __($copy.'.inspector.title') }}</h2>
        <p>{{ __($copy.'.inspector.description') }}</p>
        <div class="ops-seo-workbench-home__action-list">
            @foreach (['preview', 'editor', 'diff'] as $action)
                <span aria-disabled="true">{{ __($copy.'.inspector.actions.'.$action) }} · {{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</span>
            @endforeach
        </div>
        <p class="ops-control-hint">{{ __($copy.'.inspector.hold') }}</p>
    </aside>
</div>
