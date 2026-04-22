@props([
    'description' => null,
    'eyebrow' => __('ops.empty_state.eyebrow'),
    'icon' => 'heroicon-o-sparkles',
    'title',
])

<x-filament.ops.shared.empty-state
    {{ $attributes }}
    :description="$description"
    :eyebrow="$eyebrow"
    :icon="$icon"
    :title="$title"
>
    @isset($actions)
        <x-slot name="actions">
            {{ $actions }}
        </x-slot>
    @endisset
</x-filament.ops.shared.empty-state>
