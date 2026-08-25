@props(['scheduler' => []])

@php
    use App\Filament\Ops\Support\SeoOperationsUiState;

    $copy = 'ops.custom_pages.seo_operations.scheduler_workspace';
    $safeReceipt = static function (array $receipt): array {
        $eligible = ($receipt['trigger_mode'] ?? null) === 'scheduled' && ($receipt['receipt_complete'] ?? false) === true;

        return [
            'status' => $eligible ? ($receipt['status'] ?? null) : null,
            'completed_at' => $eligible ? ($receipt['completed_at'] ?? null) : null,
            'age_hours' => $eligible ? ($receipt['age_hours'] ?? null) : null,
        ];
    };
    $gsc = $safeReceipt((array) ($scheduler['gsc'] ?? []));
    $funnel = $safeReceipt((array) ($scheduler['public_funnel'] ?? []));
@endphp

<section class="ops-scheduler-workspace" aria-labelledby="scheduler-workspace-title" data-contract-state="production_unproven" data-read-only-gsc="true" data-search-submission-allowed="false">
    <div class="ops-seo-section-heading">
        <div><span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span><h2 id="scheduler-workspace-title">{{ __($copy.'.title') }}</h2><p>{{ __($copy.'.description') }}</p></div>
        <span class="ops-tag">#12 · MEASUREMENT_HOLD</span>
    </div>
    <div class="ops-scheduler-workspace__cadence" aria-label="{{ __($copy.'.cadence_label') }}">
        @foreach (['daily', 'weekly', 'monthly'] as $cadence)<div><span>{{ __($copy.'.cadences.'.$cadence) }}</span><strong>{{ $cadence === 'daily' && ($gsc['status'] || $funnel['status']) ? __('ops.custom_pages.seo_operations.states.production_unproven.label') : SeoOperationsUiState::metricValue(null, SeoOperationsUiState::UNAVAILABLE) }}</strong></div>@endforeach
    </div>
    <div class="ops-scheduler-workspace__layout">
        <section aria-labelledby="scheduled-receipts-title"><span class="ops-shell-eyebrow">{{ __($copy.'.receipts.eyebrow') }}</span><h3 id="scheduled-receipts-title">{{ __($copy.'.receipts.title') }}</h3><p>{{ __($copy.'.receipts.description') }}</p>
            <div class="ops-scheduler-workspace__receipts">
                @foreach (['gsc' => $gsc, 'funnel' => $funnel] as $name => $receipt)<article><strong>{{ __($copy.'.receipts.sources.'.$name) }}</strong><dl><div><dt>{{ __($copy.'.fields.status') }}</dt><dd>{{ SeoOperationsUiState::metricValue($receipt['status'], $receipt['status'] === null ? SeoOperationsUiState::UNAVAILABLE : SeoOperationsUiState::PRODUCTION_HEALTHY) }}</dd></div><div><dt>{{ __($copy.'.fields.completed_at') }}</dt><dd>{{ SeoOperationsUiState::metricValue($receipt['completed_at'], $receipt['completed_at'] === null ? SeoOperationsUiState::UNAVAILABLE : SeoOperationsUiState::PRODUCTION_HEALTHY) }}</dd></div><div><dt>{{ __($copy.'.fields.latency') }}</dt><dd>{{ $receipt['age_hours'] === null ? '—' : $receipt['age_hours'].'h' }}</dd></div></dl></article>@endforeach
            </div>
        </section>
        <aside aria-labelledby="activation-gate-title"><span class="ops-shell-eyebrow">{{ __($copy.'.gate.eyebrow') }}</span><h3 id="activation-gate-title">{{ __($copy.'.gate.title') }}</h3><p>{{ __($copy.'.gate.description') }}</p><dl>@foreach (['detector', 'url_truth', 'content_lifecycle', 'agent_runs', 'notifications', 'next_run', 'acceptance_28d', 'post_12_gate'] as $field)<div><dt>{{ __($copy.'.gate.fields.'.$field) }}</dt><dd>—</dd></div>@endforeach</dl></aside>
    </div>
    <x-filament-ops::ops-state-message state="MEASUREMENT_HOLD" :title="__($copy.'.hold_title')" :description="__($copy.'.hold_description')" />
</section>
