@php
    use App\Filament\Ops\Support\SeoAgentCouncilUiContract;
    use App\Filament\Ops\Support\SeoOperationsUiState;

    $snapshot = SeoAgentCouncilUiContract::unavailableSnapshot();
    $copy = 'ops.custom_pages.seo_operations.agent_council';
@endphp

<section
    class="ops-agent-council"
    aria-labelledby="agent-council-title"
    data-contract-state="{{ $snapshot['state'] }}"
    data-access-level="{{ $snapshot['access_level'] }}"
    data-read-only-gsc="{{ $snapshot['read_only_gsc'] ? 'true' : 'false' }}"
    data-search-submission-allowed="{{ $snapshot['search_submission_allowed'] ? 'true' : 'false' }}"
>
    <div class="ops-seo-section-heading">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span>
            <h2 id="agent-council-title">{{ __($copy.'.title') }}</h2>
            <p>{{ __($copy.'.description') }}</p>
        </div>
        <span class="ops-tag">#11 · {{ __($copy.'.access_levels.'.$snapshot['access_level']) }}</span>
    </div>

    <div class="ops-agent-council__boundaries" aria-label="{{ __($copy.'.boundaries.label') }}">
        <span class="ops-tag">read_only_gsc=true</span>
        <span class="ops-tag">search_submission_allowed=false</span>
        <span class="ops-tag">{{ __($copy.'.boundaries.evidence_bundle') }}</span>
        <span class="ops-tag">{{ __($copy.'.boundaries.no_authority') }}</span>
    </div>

    <div class="ops-agent-council__layout">
        <section class="ops-agent-council__capabilities" aria-labelledby="agent-capabilities-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.capabilities.eyebrow') }}</span>
            <h3 id="agent-capabilities-title">{{ __($copy.'.capabilities.title') }}</h3>
            <p>{{ __($copy.'.capabilities.description') }}</p>

            <div class="ops-agent-council__capability-head" aria-hidden="true">
                @foreach ($snapshot['capability_fields'] as $field)
                    <span>{{ __($copy.'.capabilities.fields.'.$field) }}</span>
                @endforeach
            </div>

            <x-filament-ops::ops-state-message
                :state="$snapshot['state']"
                :title="__($copy.'.hold_title')"
                :description="__($copy.'.hold_description')"
            />
        </section>

        <aside class="ops-agent-council__trace" aria-labelledby="agent-trace-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.trace.eyebrow') }}</span>
            <h3 id="agent-trace-title">{{ __($copy.'.trace.title') }}</h3>
            <p>{{ __($copy.'.trace.description') }}</p>
            <dl>
                @foreach (['policy_decision', 'trace', 'canary', 'circuit_breaker', 'rollback'] as $field)
                    <div>
                        <dt>{{ __($copy.'.trace.fields.'.$field) }}</dt>
                        <dd>{{ SeoOperationsUiState::metricValue($snapshot[$field], SeoOperationsUiState::UNAVAILABLE) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    </div>

    <section class="ops-agent-council__governance" aria-labelledby="agent-governance-title">
        <span class="ops-shell-eyebrow">{{ __($copy.'.governance.eyebrow') }}</span>
        <h3 id="agent-governance-title">{{ __($copy.'.governance.title') }}</h3>
        <p>{{ __($copy.'.governance.description') }}</p>
        <ol>
            @foreach ($snapshot['governance_steps'] as $step)
                <li>
                    <span>{{ $loop->iteration }}</span>
                    <strong>{{ __($copy.'.governance.steps.'.$step) }}</strong>
                    <small>{{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</small>
                </li>
            @endforeach
        </ol>
    </section>

    <p class="ops-control-hint">{{ __($copy.'.privacy_note') }}</p>
</section>
