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

    <p class="ops-control-hint" data-scheduler-activation="disabled">
        {{ __($copy.'.scheduler_disabled') }}
    </p>

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
