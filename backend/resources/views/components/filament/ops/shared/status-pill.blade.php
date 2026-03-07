@props([
    'label' => null,
    'state' => null,
])

@php
    $tone = \App\Filament\Ops\Support\StatusBadge::color($state);
    $display = $label ?? (is_bool($state) ? ($state ? 'active' : 'inactive') : (string) $state);
@endphp

<span {{ $attributes->class(['ops-status-pill', "ops-status-pill--{$tone}"]) }}>
    {{ $display }}
</span>
