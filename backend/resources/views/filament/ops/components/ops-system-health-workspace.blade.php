@props(['snapshot' => null])

@php
    use App\Services\SeoCouncil\Platform12\Operations\Platform12SystemHealthReadService;

    $snapshot = is_array($snapshot) ? $snapshot : app(Platform12SystemHealthReadService::class)->snapshot();
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
        {{ __('seo-council.capabilities.computation') }}={{ ($snapshot['daily_missions']['enabled'] ?? false) ? 'ACTIVE_READ_ONLY' : 'HOLD' }} ·
        {{ __('seo-council.capabilities.audit') }}={{ ($snapshot['daily_missions']['audit_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }} ·
        {{ __('seo-council.capabilities.business_write') }}={{ ($snapshot['daily_missions']['business_write_enabled'] ?? false) ? 'ENABLED' : 'DISABLED' }}
    </p>

    @if (isset($snapshot['daily_missions']))
        <div class="ops-data-strip" aria-label="{{ __('seo-council.overview') }}">
            <p>
                {{ $snapshot['daily_missions']['runtime_state'] ?? 'UNAVAILABLE' }} ·
                {{ $snapshot['daily_missions']['runtime_phase'] ?? (($snapshot['daily_missions']['enabled'] ?? false) ? 'ACTIVE_READ_ONLY' : 'DISABLED') }} ·
                pause_source={{ $snapshot['daily_missions']['pause_source'] ?? 'none' }} ·
                {{ __('seo-council.actionable') }}: {{ $snapshot['daily_missions']['actionable_count'] }}
            </p>
            @foreach ($snapshot['daily_missions']['items'] as $mission)
                <div class="ops-metric">
                    <strong>{{ __($mission['label_key']) }}</strong>
                    <span>{{ __('seo-council.states.'.$mission['state']) }}</span>
                    <small><code>{{ $mission['reason_code'] }}</code> · {{ __($mission['problem_key']) }}</small>
                    <small>{{ __('seo-council.impact') }}：{{ __($mission['impact_key']) }}</small>
                    <small>{{ __('seo-council.recommendation') }}：{{ __($mission['recommendation_key']) }}</small>
                    @foreach ($mission['source_checks'] as $source)
                        <small>
                            {{ __($source['label_key']) }} · {{ $source['state'] }} ·
                            @if ($source['observed_at'])
                                <time datetime="{{ $source['observed_at'] }}">{{ $source['observed_at'] }}</time>
                            @else
                                {{ __('seo-council.time_unknown') }}
                            @endif
                        </small>
                    @endforeach
                    @if ($mission['observed_at'])
                        <time datetime="{{ $mission['observed_at'] }}">{{ $mission['observed_at'] }}</time>
                    @endif
                    <small>{{ __('seo-council.next_run') }}: {{ $mission['next_run'] }}</small>
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
                    <small>{{ __($copy.'.summaries.'.$item['summary_code']) }} · {{ $item['count'] }}</small>
                    @if (isset($item['observed_at']))
                        <time datetime="{{ $item['observed_at'] }}">{{ $item['observed_at'] }}</time>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <p class="ops-control-hint">{{ __($copy.'.privacy_note') }}</p>
</section>
