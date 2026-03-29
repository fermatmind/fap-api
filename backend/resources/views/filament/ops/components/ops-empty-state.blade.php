@props([
    'description' => null,
    'eyebrow' => 'Workspace state',
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
