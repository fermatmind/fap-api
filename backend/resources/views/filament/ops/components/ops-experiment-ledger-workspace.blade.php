@php
    use App\Filament\Ops\Support\SeoExperimentLedgerUiContract;
    $snapshot = SeoExperimentLedgerUiContract::snapshot();
    $current = $snapshot['items'][0] ?? null;
    $copy = 'ops.custom_pages.seo_operations.experiment_ledger';
@endphp

<section class="ops-experiment-ledger" aria-labelledby="experiment-ledger-title" data-contract-state="{{ $snapshot['state'] }}">
    <div class="ops-seo-section-heading">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span>
            <h2 id="experiment-ledger-title">{{ __($copy.'.title') }}</h2>
        </div>
        <span class="ops-tag">#8</span>
    </div>

    <div class="ops-experiment-ledger__status-row" aria-label="{{ __($copy.'.statuses_label') }}">
        @foreach ($snapshot['statuses'] as $status)
            <span class="ops-tag">{{ __($copy.'.statuses.'.$status) }}</span>
        @endforeach
    </div>

    <div class="ops-experiment-ledger__layout">
        <div class="ops-experiment-ledger__list">
            <div class="ops-experiment-ledger__list-head" aria-hidden="true">
                @foreach (['experiment', 'scope', 'baseline', 'metric', 'window', 'status'] as $column)
                    <span>{{ __($copy.'.columns.'.$column) }}</span>
                @endforeach
            </div>
            @forelse ($snapshot['items'] as $item)
                <article class="ops-experiment-ledger__list-head" data-ledger-id="{{ $item['ledger_id'] }}">
                    <span>{{ $item['hypothesis'] }}</span>
                    <span>{{ $item['scope']['page_family'] ?? '—' }} / {{ $item['scope']['locale'] ?? '—' }}</span>
                    <span>{{ data_get($item, 'baseline.value', '—') }}</span>
                    <span>{{ data_get($item, 'primary_metric.name', data_get($item, 'primary_metric.metric', '—')) }}</span>
                    <span>{{ data_get($item, 'observation_window.window_days', '—') }}</span>
                    <span>{{ $item['status'] }}</span>
                </article>
            @empty
                <x-filament-ops::ops-state-message
                    :state="$snapshot['state']"
                    :title="$snapshot['empty'] ? __($copy.'.empty_title') : __($copy.'.hold_title')"
                    :description="''"
                />
            @endforelse
        </div>

        <aside class="ops-experiment-ledger__inspector" aria-labelledby="experiment-prerequisites-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.inspector_eyebrow') }}</span>
            <h3 id="experiment-prerequisites-title">{{ __($copy.'.inspector_title') }}</h3>
            <dl>
                <div>
                    <dt>{{ __($copy.'.pagination') }}</dt>
                    <dd>{{ $snapshot['pagination']['page'] }} / {{ $snapshot['pagination']['last_page'] }}</dd>
                </div>
                <div>
                    <dt>{{ __($copy.'.total') }}</dt>
                    <dd>{{ $snapshot['pagination']['total'] }}</dd>
                </div>
                <div>
                    <dt>{{ __($copy.'.read_only') }}</dt>
                    <dd>{{ $snapshot['read_only'] ? __($copy.'.yes') : __($copy.'.no') }}</dd>
                </div>
                <div>
                    <dt>{{ __($copy.'.current_status') }}</dt>
                    <dd>{{ $current['status'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __($copy.'.runtime_readback') }}</dt>
                    <dd>{{ data_get($current, 'evidence_readback.public_runtime.status', '—') }}</dd>
                </div>
                <div>
                    <dt>{{ __($copy.'.measurement_evidence') }}</dt>
                    <dd>{{ data_get($current, 'evidence_readback.measurement.quality_state', data_get($current, 'evidence_readback.measurement.state', '—')) }}</dd>
                </div>
            </dl>
        </aside>
    </div>

</section>
