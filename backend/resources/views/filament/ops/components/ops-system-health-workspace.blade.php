@props(['snapshot' => null, 'runtime' => null])

@php
    use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;
    use App\Services\SeoCouncil\Platform12\Operations\Platform12OperationsTime;

    $snapshot = is_array($snapshot) ? $snapshot : app(Platform12SystemHealthReadService::class)->snapshot($runtime);
    $copy = 'ops.custom_pages.seo_operations.system_health';
@endphp

<section
    class="ops-system-health"
    aria-labelledby="system-health-title"
    data-state="{{ $snapshot['status'] }}"
    data-read-only="{{ $snapshot['read_only'] ? 'true' : 'false' }}"
    data-execution-allowed="{{ $snapshot['execution_allowed'] ? 'true' : 'false' }}"
    data-write-allowed="{{ $snapshot['write_allowed'] ? 'true' : 'false' }}"
>
    <div class="ops-seo-section-heading">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span>
            <h3 id="system-health-title">{{ __($copy.'.title') }}</h3>
            <p>{{ __($copy.'.description') }}</p>
        </div>
        <span class="ops-tag">{{ $snapshot['status'] }}</span>
    </div>

    <p class="ops-control-hint" data-scheduler-activation="{{ ($snapshot['daily_missions']['enabled'] ?? false) ? 'read-only' : 'disabled' }}">
        {{ __('seo-council.scheduler_enabled') }}={{ ($snapshot['daily_missions']['scheduler_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }} ·
        {{ __('seo-council.capabilities.computation') }}={{ ($snapshot['daily_missions']['enabled'] ?? false) ? 'ACTIVE_READ_ONLY' : 'HOLD' }} ·
        {{ __('seo-council.capabilities.audit') }}={{ ($snapshot['daily_missions']['audit_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }} ·
        {{ __('seo-council.capabilities.business_write') }}={{ ($snapshot['daily_missions']['business_write_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }}
    </p>

    @if (isset($snapshot['daily_missions']))
        <div class="ops-data-strip" aria-label="{{ __('seo-council.overview') }}">
            <p>{{ __('seo-council.public_gate') }}: {{ $snapshot['daily_missions']['public_gate'] ?? 'UNAVAILABLE' }} · {{ __('seo-council.pause') }}: {{ $snapshot['daily_missions']['pause_intent'] ?? 'UNSET' }}</p>
            <p>{{ $snapshot['daily_missions']['runtime_state'] }} · {{ __('seo-council.actionable') }}: {{ $snapshot['daily_missions']['actionable_count'] ?? '—' }} {{ __('seo-council.mission_unit') }}</p>
            @foreach ($snapshot['daily_missions']['items'] as $mission)
                <div class="ops-metric">
                    <strong>{{ __($mission['label_key']) }}</strong>
                    <small>{{ __('seo-council.execution_state') }}: {{ $mission['execution_state'] ?? 'UNAVAILABLE' }}</small>
                    <span>{{ __('seo-council.last_check') }}: {{ __('seo-council.states.'.$mission['state']) }}</span>
                    <small><code>{{ $mission['business_result'] ?? 'UNAVAILABLE' }}</code></small>
                    <small><code>{{ $mission['reason_code'] }}</code> · {{ __($mission['problem_key']) }}</small>
                    <small>{{ __('seo-council.impact') }}：{{ __($mission['impact_key']) }}</small>
                    <small>{{ __('seo-council.recommendation') }}：{{ __($mission['recommendation_key']) }}</small>
                    @foreach ($mission['source_checks'] as $source)
                        <small>
                            {{ __($source['label_key']) }} · {{ $source['state'] }} · {{ __('seo-council.source_observed_at') }}:
                            @if ($source['observed_at'])
                                <time datetime="{{ $source['observed_at'] }}">{{ Platform12OperationsTime::display($source['observed_at']) ?? __('seo-council.time_unknown') }}</time>
                            @else
                                {{ __('seo-council.time_unknown') }}
                            @endif
                            · {{ __('seo-council.source_read_at') }}: {{ Platform12OperationsTime::display($source['read_at'] ?? null) ?? __('seo-council.time_unknown') }}
                        </small>
                    @endforeach
                    <small>{{ __('seo-council.check_time') }}:
                        @if ($mission['observed_at'])
                            <time datetime="{{ $mission['observed_at'] }}">{{ Platform12OperationsTime::display($mission['observed_at']) }}</time>
                        @else
                            {{ __('seo-council.time_unknown') }}
                        @endif
                    </small>
                    <small>{{ __('seo-council.record_updated') }}: {{ __('seo-council.time_unknown') }}</small>
                    <small>{{ __('seo-council.evidence_origin') }}: {{ __('seo-council.origins.'.($mission['evidence_origin'] ?? 'unknown')) }}</small>
                    <x-filament-ops::ops-mission-result-evidence label="latest_natural" :result="$mission['latest_natural'] ?? null" />
                    <x-filament-ops::ops-mission-result-evidence label="latest_controlled" :result="$mission['latest_controlled'] ?? null" />
                    <small>{{ __('seo-council.acceptance_ready') }}: {{ ($mission['acceptance_ready'] ?? false) ? 'READY' : 'HOLD' }} · {{ $mission['gate_reason'] ?? 'UNAVAILABLE' }}</small>
                    <strong>{{ __('seo-council.'.($mission['gate_next_step'] ?? 'authorization_unknown')) }}</strong>
                    @if (($mission['gate_next_step'] ?? null) === 'natural_run_authorized')
                        <small>{{ __('seo-council.'.(in_array($mission['state'], ['FAILED', 'EXECUTION_HOLD'], true) ? 'reasons.EXECUTION_HOLD.problem' : ($mission['state'] === 'RUNNING' ? 'currently_executing' : 'waiting_window'))) }}</small>
                    @endif
                    <small>{{ __('seo-council.source_accepted') }}: {{ isset($mission['source_accepted']) ? ($mission['source_accepted'] ? 'READY' : 'HOLD') : 'UNAVAILABLE' }} · {{ __('seo-council.end_to_end_accepted') }}: {{ isset($mission['end_to_end_accepted']) ? ($mission['end_to_end_accepted'] ? 'READY' : 'HOLD') : 'UNAVAILABLE' }}</small>
                    <small>{{ __('seo-council.selected') }}: {{ isset($mission['selected']) ? ($mission['selected'] ? 'YES' : 'NO') : 'UNAVAILABLE' }} · {{ __('seo-council.run_allowed') }}: {{ isset($mission['run_allowed']) ? ($mission['run_allowed'] ? 'YES' : 'NO') : 'UNAVAILABLE' }}</small>
                    <small>{{ __($mission['next_run'] ? 'seo-council.next_run' : 'seo-council.planned_disabled') }}: <time datetime="{{ $mission['next_run'] ?? $mission['planned_time'] }}">{{ Platform12OperationsTime::display($mission['next_run'] ?? $mission['planned_time']) ?? __('seo-council.time_unknown') }}</time></small>
                    @if ($mission['receipt_hash'])
                        <a href="#trace-drilldown-title">{{ __('seo-council.trace') }} · {{ substr($mission['receipt_hash'], 0, 12) }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($snapshot['status'] === 'UNAVAILABLE')
        <x-filament-ops::ops-state-message
            state="unavailable"
            :title="__($copy.'.unavailable_title')"
            :description="__($copy.'.unavailable_description')"
        />
    @else
        <div class="ops-data-strip">
            @foreach ($snapshot['items'] as $item)
                <div class="ops-metric" data-component="{{ $item['component'] }}" data-component-state="{{ $item['state'] }}">
                    <span class="ops-metric__label">{{ __($copy.'.components.'.$item['component']) }}</span>
                    <strong>{{ $item['state'] }}</strong>
                    <small>{{ __($copy.'.summaries.'.$item['summary_code']) }} · {{ $item['count'] ?? '—' }}</small>
                    @if (isset($item['observed_at']))
                        <time datetime="{{ $item['observed_at'] }}">{{ Platform12OperationsTime::display($item['observed_at']) }}</time>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <p class="ops-control-hint">{{ __($copy.'.privacy_note') }}</p>
</section>
