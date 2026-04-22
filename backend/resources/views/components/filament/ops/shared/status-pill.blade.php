@props([
    'label' => null,
    'state' => null,
])

@php
    $tone = \App\Filament\Ops\Support\StatusBadge::color($state);
    $display = $label ?? \App\Filament\Ops\Support\StatusBadge::label($state);
@endphp

<span {{ $attributes->class(['ops-status-pill', "ops-status-pill--{$tone}"]) }}>
    {{ $display }}
</span>
