@props([
    'meta' => null,
    'title' => null,
])

<div {{ $attributes->class(['ops-result-card']) }}>
    @if (filled($title) || filled($meta) || isset($actions))
        <div class="ops-result-card__header">
            <div class="ops-result-card__heading">
                @if (filled($title))
                    <p class="ops-result-card__title">{{ $title }}</p>
                @endif

                @if (filled($meta))
                    <p class="ops-result-card__meta">{{ $meta }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="ops-result-card__actions">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    @isset($badges)
        <div class="ops-result-card__badges">
            {{ $badges }}
        </div>
    @endisset

    <div class="ops-result-card__body">
        {{ $slot }}
    </div>
</div>
