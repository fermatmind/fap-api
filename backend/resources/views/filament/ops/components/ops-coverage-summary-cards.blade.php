@props([
    'cards' => [],
])

<div {{ $attributes->class(['ops-coverage-summary']) }}>
    @foreach ($cards as $card)
        <x-filament-ops::ops-result-card class="ops-coverage-summary__card">
            <div class="ops-result-card__header">
                <div class="ops-result-card__heading">
                    <p class="ops-result-card__title">{{ $card['label'] }}</p>
                    <p class="ops-result-card__meta">{{ $card['hint'] }}</p>
                </div>
                <x-filament.ops.shared.status-pill :state="$card['state']" :label="$card['value']" />
            </div>
        </x-filament-ops::ops-result-card>
    @endforeach
</div>
