@props(['items' => [], 'label' => null])

<div {{ $attributes->class(['ops-breakdown-stack']) }} @if($label) aria-label="{{ $label }}" @endif>
    @foreach ($items as $item)
        <div class="ops-breakdown-row">
            <span class="ops-breakdown-row__label">{{ $item['label'] ?? '' }}</span>
            <span class="ops-breakdown-row__track" aria-hidden="true">
                <span class="ops-breakdown-row__bar" style="width: {{ max(0, min(100, (int) ($item['percent'] ?? $item['pct'] ?? 0))) }}%"></span>
            </span>
            <span class="ops-breakdown-row__value tnum">{{ $item['value'] ?? $item['count'] ?? 0 }}</span>
        </div>
    @endforeach
</div>
