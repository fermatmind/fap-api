@props([
    'description' => null,
    'eyebrow' => __('ops.empty_state.eyebrow'),
    'icon' => 'heroicon-o-sparkles',
    'title',
])

<div {{ $attributes->class(['ops-empty-state']) }}>
    <span class="ops-empty-state__icon">
        <x-filament::icon :icon="$icon" class="h-6 w-6" />
    </span>

    <div class="ops-empty-state__body">
        @if (filled($eyebrow))
            <span class="ops-empty-state__eyebrow">{{ $eyebrow }}</span>
        @endif

        <h3 class="ops-empty-state__title">{{ $title }}</h3>

        @if (filled($description))
            <p class="ops-empty-state__description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="ops-empty-state__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
