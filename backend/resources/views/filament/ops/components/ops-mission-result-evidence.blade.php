@props(['result' => null, 'label'])

@php
    use App\Services\SeoCouncil\Platform12\Operations\Platform12OperationsTime;
@endphp

<details class="ops-control-hint" data-mission-result="{{ $label }}">
    <summary>{{ __('seo-council.'.$label) }} · {{ $result['state'] ?? 'UNAVAILABLE' }}</summary>
    @if (is_array($result))
        <small>{{ __('seo-council.evidence_origin') }}: {{ __('seo-council.origins.'.$result['origin']) }}</small>
        <small>{{ __('seo-council.evaluated_at') }}:
            <time datetime="{{ $result['evaluated_at'] }}">{{ Platform12OperationsTime::display($result['evaluated_at']) }}</time>
        </small>
        @foreach ($result['sources'] as $source)
            <small>{{ __('seo-council.sources.'.$source['id']) }} · {{ __('seo-council.source_observed_at') }}:
                {{ Platform12OperationsTime::display($source['observed_at']) ?? __('seo-council.time_unknown') }} ·
                {{ __('seo-council.source_read_at') }}: {{ Platform12OperationsTime::display($source['read_at']) ?? __('seo-council.time_unknown') }}
                <code>{{ substr($source['hash'], 0, 12) }}</code>
            </small>
        @endforeach
        @if ($result['is_gsc'])
            <small>{{ __('seo-council.data_max_date') }}: {{ $result['data_max_date'] ?? 'UNAVAILABLE' }} ·
                {{ __('seo-council.utc_lag') }}: {{ $result['lag_days'] ?? 'UNAVAILABLE' }}</small>
            <small>{{ __('seo-council.lag_explanation') }}</small>
            @if ($result['gsc_collection'])
                <small>{{ __('seo-council.pt_collection') }}: {{ $result['gsc_collection']['start_date'] }} — {{ $result['gsc_collection']['end_date'] }}
                    ({{ $result['gsc_collection']['timezone'] }}) · {{ __('seo-council.pt_cutoff') }}: {{ $result['gsc_collection']['cutoff_date'] }}</small>
            @else
                <small>{{ __('seo-council.pt_collection') }}: {{ __('seo-council.source_unlinked') }}</small>
            @endif
            @foreach ($result['runtime'] as $dimension => $state)
                <small>{{ __('seo-council.runtime_dimensions.'.$dimension) }}: {{ $state }}</small>
            @endforeach
            <small>Production SHA: <code>{{ $result['production_sha'] ?? 'UNAVAILABLE' }}</code></small>
            <small>Readback SHA: <code>{{ $result['readback_sha'] ?? 'UNAVAILABLE' }}</code></small>
        @endif
        <small>{{ __('seo-council.result_receipt') }}: <code>{{ $result['receipt_hash'] }}</code></small>
    @else
        <small>{{ __('seo-council.source_unlinked') }}</small>
    @endif
</details>
