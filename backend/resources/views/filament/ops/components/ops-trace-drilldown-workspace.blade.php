@php
    use App\Services\SeoCouncil\Platform12\Operations\Platform12TraceDrilldownReadService;

    $snapshot = app(Platform12TraceDrilldownReadService::class)->snapshot();
    $copy = 'ops.custom_pages.seo_operations.trace_drilldown';
@endphp

<section class="ops-trace-drilldown" aria-labelledby="trace-drilldown-title" data-state="{{ $snapshot['state'] }}" data-rendered-rows="{{ count($snapshot['items']) }}">
    <div class="ops-seo-section-heading">
        <div><span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span><h3 id="trace-drilldown-title">{{ __($copy.'.title') }}</h3></div>
        <span class="ops-tag">{{ $snapshot['pagination']['per_page'] }} / {{ $snapshot['query_budget']['retention_days'] }}d</span>
    </div>
    <div class="ops-trace-drilldown__head" aria-hidden="true">
        @foreach (['mission', 'mode', 'role', 'evidence', 'receipt', 'status'] as $field)<span>{{ __($copy.'.fields.'.$field) }}</span>@endforeach
    </div>
    <div class="ops-trace-drilldown__rows">
        @forelse ($snapshot['items'] as $item)
            <article>
                <span>{{ $item['mission'] }}</span><span>{{ $item['mode'] }}</span><span>{{ $item['role'] }}</span>
                <span title="{{ $item['evidence_hash'] }}">{{ substr($item['evidence_hash'], 0, 12) }}</span>
                <span title="{{ $item['receipt_hash'] }}">{{ substr($item['receipt_hash'], 0, 12) }}</span>
                <span>{{ $item['status'] }} · {{ $item['stop_reason'] }}<small>{{ $item['cost_microusd'] ?? 'unavailable' }} μUSD · {{ $item['latency_ms'] }} ms · {{ $item['catalog_version'] }}</small>
                    @if ($item['source_checks'] !== [])
                        <details><summary>{{ __('seo-council.trace') }}</summary>
                            @foreach ($item['source_checks'] as $source)
                                <small>{{ __($source['label_key']) }} · {{ $source['state'] }} · {{ $source['observed_at'] ?? __('seo-council.time_unknown') }} · {{ substr($source['hash'], 0, 12) }}</small>
                            @endforeach
                            <small>{{ __('seo-council.inspect_trace') }}</small>
                        </details>
                    @endif
                </span>
            </article>
        @empty
            <x-filament-ops::ops-state-message :state="$snapshot['state']" :title="__($copy.'.states.'.$snapshot['state'])" :description="''" />
        @endforelse
    </div>
    <p class="ops-control-hint">{{ __($copy.'.boundary') }}</p>
</section>
