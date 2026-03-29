@props([
    'eyebrow' => null,
    'meta' => 'Operations shell',
    'title' => 'Fermat Ops',
])

<div {{ $attributes->class(['ops-context-bar']) }}>
    <div class="ops-topbar-context">
        @if (filled($eyebrow))
            <span class="ops-topbar-context__eyebrow">{{ $eyebrow }}</span>
        @endif

        <div class="ops-topbar-context__body">
            <span class="ops-topbar-context__title">{{ $title }}</span>
            <span class="ops-topbar-context__meta">{{ $meta }}</span>
        </div>
    </div>

    @isset($actions)
        <div class="ops-context-bar__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
