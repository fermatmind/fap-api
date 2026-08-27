@php
    use App\Filament\Ops\Support\SeoOperationsUiState;
    use App\Filament\Ops\Support\SeoWorkbenchUiContract;

    $snapshot = SeoWorkbenchUiContract::snapshot();
    $current = $snapshot['decisions'][0] ?? null;
    $copy = 'ops.custom_pages.seo_operations.workbench';
@endphp

<div
    class="ops-seo-workbench-home"
    data-contract-state="{{ $snapshot['state'] }}"
    data-default-decision-count="{{ SeoWorkbenchUiContract::DEFAULT_DECISION_COUNT }}"
    data-max-decision-count="{{ SeoWorkbenchUiContract::MAX_DECISION_COUNT }}"
    data-decision-count="{{ $snapshot['count'] }}"
    data-selection-revision="{{ $snapshot['selection_revision'] }}"
>
    <section class="ops-seo-workbench-home__trend" aria-labelledby="seo-workbench-trend-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.trend.eyebrow') }}</span>
                <h2 id="seo-workbench-trend-title">{{ __($copy.'.trend.title') }}</h2>
            </div>
            <span class="ops-tag">{{ $snapshot['trend']['window'] }}</span>
        </div>
        <div class="ops-seo-workbench-home__metrics">
            @foreach (['clicks', 'impressions', 'ctr', 'position'] as $metric)
                <div>
                    <span>{{ __($copy.'.trend.metrics.'.$metric) }}</span>
                    <strong class="tnum">{{ SeoOperationsUiState::metricValue($snapshot['trend'][$metric], $snapshot['trend_state']) }}</strong>
                </div>
            @endforeach
        </div>
        <x-filament-ops::ops-state-message
            :state="$snapshot['trend_state']"
            :title="__('ops.custom_pages.seo_operations.states.MEASUREMENT_HOLD.label')"
            :description="''"
        />
    </section>

    <section class="ops-seo-workbench-home__decisions" aria-labelledby="seo-workbench-decisions-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.decisions.eyebrow') }}</span>
                <h2 id="seo-workbench-decisions-title">{{ __($copy.'.decisions.title') }}</h2>
            </div>
            <span class="ops-tag">{{ $snapshot['iso_week'] }} · {{ $snapshot['count'] }} / {{ $snapshot['max_count'] }}</span>
        </div>
        <div class="ops-seo-workbench-home__decision-head" aria-hidden="true">
            @foreach (['cause', 'scope', 'evidence', 'impact', 'action'] as $column)
                <span>{{ __($copy.'.decisions.columns.'.$column) }}</span>
            @endforeach
        </div>
        @forelse ($snapshot['decisions'] as $decision)
            <article
                class="ops-seo-workbench-home__decision-head"
                data-cluster-uid="{{ $decision['cluster_uid'] }}"
                data-selection-rank="{{ $decision['selection_rank'] }}"
            >
                <span>{{ $decision['detector'] }} / {{ $decision['root_cause'] }}</span>
                <span>{{ $decision['page_family'] }} / {{ $decision['locale'] }}</span>
                <span>{{ $decision['evidence_state'] }} / {{ $decision['evidence_freshness'] }}</span>
                <span class="tnum">{{ $decision['priority_score'] }} / {{ $decision['affected_unique_url_count'] }}</span>
                <span>{{ $decision['highest_allowed_action'] }} / {{ $decision['next_step'] }}</span>
            </article>
        @empty
            <x-filament-ops::ops-state-message
                :state="$snapshot['state']"
                :title="$snapshot['state'] === 'verified_zero' ? __($copy.'.decisions.empty_title') : __($copy.'.decisions.hold_title')"
                :description="''"
            />
        @endforelse
    </section>

    <section class="ops-seo-workbench-home__health" aria-labelledby="seo-workbench-health-title">
        <div class="ops-seo-section-heading">
            <div>
                <span class="ops-shell-eyebrow">{{ __($copy.'.health.eyebrow') }}</span>
                <h2 id="seo-workbench-health-title">{{ __($copy.'.health.title') }}</h2>
            </div>
        </div>
        <dl>
            @foreach ($snapshot['health'] as $field => $value)
                <div>
                    <dt>{{ __($copy.'.health.fields.'.$field) }}</dt>
                    <dd class="tnum">{{ SeoOperationsUiState::metricValue($value, $snapshot['health_state']) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <aside class="ops-seo-workbench-home__inspector" aria-labelledby="seo-workbench-inspector-title">
        <span class="ops-shell-eyebrow">{{ __($copy.'.inspector.eyebrow') }}</span>
        <h2 id="seo-workbench-inspector-title">{{ __($copy.'.inspector.title') }}</h2>
        <dl>
            <div>
                <dt>{{ __($copy.'.decisions.selection_revision') }}</dt>
                <dd>{{ $snapshot['selection_revision'] ?? '—' }}</dd>
            </div>
            <div>
                <dt>{{ __($copy.'.decisions.current_status') }}</dt>
                <dd>{{ $current['status'] ?? '—' }}</dd>
            </div>
        </dl>
        <div class="ops-seo-workbench-home__action-list">
            @foreach (['preview', 'editor', 'diff'] as $action)
                <span aria-disabled="true">{{ __($copy.'.inspector.actions.'.$action) }} · {{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</span>
            @endforeach
        </div>
    </aside>
</div>
