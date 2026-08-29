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
    data-registry-status="{{ $snapshot['registry_metadata']['registry_status'] }}"
    data-registry-version="{{ $snapshot['registry_metadata']['registry_version'] }}"
    data-policy-decision="{{ $snapshot['policy_decision'] }}"
    data-policy-mode="{{ $snapshot['policy_mode'] }}"
    data-runtime-mode="{{ $snapshot['runtime_mode'] }}"
    data-active-manifest-count="{{ $snapshot['active_manifest_count'] }}"
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
        <span class="ops-tag">registry={{ $snapshot['registry_metadata']['registry_status'] }} · v{{ $snapshot['registry_metadata']['registry_version'] }}</span>
        <span class="ops-tag">state=DEPLOYED_DISABLED</span>
        <span class="ops-tag">mode={{ $snapshot['policy_mode'] }}</span>
        <span class="ops-tag">runtime_mode={{ $snapshot['runtime_mode'] }}</span>
        <span class="ops-tag">decision={{ $snapshot['policy_decision'] }}</span>
        @foreach ($snapshot['global_guards'] as $guard => $value)
            <span class="ops-tag">{{ $guard }}={{ $value ? 'true' : 'false' }}</span>
        @endforeach
        <span class="ops-tag">registry_hash={{ $snapshot['registry_metadata']['registry_hash'] }}</span>
        <span class="ops-tag">active_manifest={{ $snapshot['active_manifest_count'] }}</span>
        <span class="ops-tag">binding=v{{ $snapshot['binding_metadata']['version'] }} · {{ $snapshot['binding_metadata']['hash'] }}</span>
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

            <form method="POST" action="{{ route('ops.seo_intel.council.ui_missions.store') }}" class="ops-agent-council__mission-form">
                @csrf
                <input type="hidden" name="mission_id" value="mission:seo-operations-ui" />
                <label><span>Idempotency key</span><input name="idempotency_key" required pattern="[A-Za-z0-9._:-]{1,128}" value="seo-operations-ui:route-plan" /></label>
                <label><span>Mission</span><select name="mission_type"><option value="weekly_opportunity">weekly_opportunity</option><option value="monthly_portfolio">monthly_portfolio</option><option value="breakthrough_sprint">breakthrough_sprint</option><option value="global_portfolio">global_portfolio</option><option value="bounded_review">bounded_review</option><option value="independent_registry_review">independent_registry_review</option><option value="career_candidate_generation">career_candidate_generation</option></select></label>
                <label><span>Family</span><select name="family"><option value="tests">tests</option><option value="articles_topics">articles_topics</option><option value="career">career</option><option value="personality">personality</option><option value="trust_method_help">trust_method_help</option><option value="other_public">other_public</option></select></label>
                <label><span>Locale</span><select name="locale"><option value="zh-CN">zh-CN</option><option value="en">en</option></select></label>
                <label><span>Review domain (bounded only)</span><select name="review_domain"><option value="">none</option><option value="technical">technical</option><option value="analytics">analytics</option><option value="content">content</option><option value="competitor">competitor</option><option value="stability">stability</option><option value="cro">cro</option></select></label>
                <button type="submit">Generate deterministic RunReceipt / HOLD</button>
            </form>
            <p class="ops-control-hint">The UI submits only a zero-budget MissionRequest. It cannot select roles, tools, writers, manifests, or production execution.</p>
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
