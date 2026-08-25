@props(['items' => [], 'label' => null])

<section {{ $attributes->class(['ops-trust-strip']) }} aria-label="{{ $label }}" data-item-count="{{ count($items) }}">
    @foreach ($items as $item)
        @php
            $state = \App\Filament\Ops\Support\SeoOperationsUiState::normalize($item['state'] ?? null);
            $tone = \App\Filament\Ops\Support\SeoOperationsUiState::tone($state);
        @endphp
        <div class="ops-trust-strip__item" data-state="{{ $state }}">
            <span class="ops-trust-strip__signal ops-state-{{ $tone }}" aria-hidden="true"></span>
            <div>
                <strong>{{ $item['label'] ?? '' }}</strong>
                @if (array_key_exists('value', $item))
                    <span class="ops-trust-strip__value tnum">{{ $item['value'] }}</span>
                @endif
                <p>
                    {{ __('ops.custom_pages.seo_operations.states.'.$state.'.label') }}
                    @if (!empty($item['updated_at']))
                        · {{ __('ops.custom_pages.seo_operations.sources.updated_at', ['time' => $item['updated_at']]) }}
                    @endif
                </p>
                @if (!empty($item['detail']))
                    <small>{{ $item['detail'] }}</small>
                @endif
            </div>
        </div>
    @endforeach
</section>
