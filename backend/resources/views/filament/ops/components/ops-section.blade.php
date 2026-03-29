@props([
    'description' => null,
    'eyebrow' => null,
    'title' => null,
])

<x-filament::section {{ $attributes->class(['ops-section-block']) }}>
    @if (filled($eyebrow) || filled($title) || filled($description) || isset($actions))
        <div class="ops-results-header ops-section-block__header">
            <div class="ops-section-block__intro">
                @if (filled($eyebrow))
                    <span class="ops-shell-inline-intro__eyebrow">{{ $eyebrow }}</span>
                @endif

                @if (filled($title))
                    <h3 class="ops-results-header__title">{{ $title }}</h3>
                @endif

                @if (filled($description))
                    <p class="ops-results-header__meta">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="ops-section-block__actions">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div class="ops-section-block__body">
        {{ $slot }}
    </div>
</x-filament::section>
