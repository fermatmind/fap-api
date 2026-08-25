@php
    use App\Filament\Ops\Support\SeoExperimentLedgerUiContract;
    use App\Filament\Ops\Support\SeoOperationsUiState;

    $snapshot = SeoExperimentLedgerUiContract::unavailableSnapshot();
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
            <x-filament-ops::ops-state-message
                :state="$snapshot['state']"
                :title="__($copy.'.hold_title')"
                :description="''"
            />
        </div>

        <aside class="ops-experiment-ledger__inspector" aria-labelledby="experiment-prerequisites-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.inspector_eyebrow') }}</span>
            <h3 id="experiment-prerequisites-title">{{ __($copy.'.inspector_title') }}</h3>
            <dl>
                @foreach ($snapshot['required_fields'] as $field)
                    <div>
                        <dt>{{ __($copy.'.fields.'.$field) }}</dt>
                        <dd>{{ SeoOperationsUiState::metricValue(null, SeoOperationsUiState::UNAVAILABLE) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    </div>

</section>
