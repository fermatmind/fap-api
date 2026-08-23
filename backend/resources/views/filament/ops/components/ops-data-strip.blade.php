@props(['metrics' => [], 'label' => null])

<div {{ $attributes->class(['ops-data-strip']) }} @if($label) aria-label="{{ $label }}" @endif>
    @foreach ($metrics as $metric)
        <div class="ops-metric">
            <span class="ops-metric__label">{{ $metric['label'] ?? '' }}</span>
            <span @class(['ops-metric__value', 'tnum', 'ops-state-'.($metric['tone'] ?? '') => ! empty($metric['tone'])])>
                {{ $metric['value'] ?? '—' }}
            </span>
            @if (! empty($metric['meta']))
                <span class="ops-metric__freshness">{{ $metric['meta'] }}</span>
            @endif
        </div>
    @endforeach
</div>
