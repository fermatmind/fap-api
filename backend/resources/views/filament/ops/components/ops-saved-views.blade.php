@props(['views' => [], 'active' => null, 'label' => null, 'action' => 'applySavedView'])

<div {{ $attributes->class(['ops-saved-views']) }} role="group" @if($label) aria-label="{{ $label }}" @endif>
    @foreach ($views as $key => $view)
        @php($viewLabel = is_array($view) ? ($view['label'] ?? $key) : $view)
        @php($count = is_array($view) ? ($view['count'] ?? null) : null)
        <button
            type="button"
            wire:click="{{ $action }}('{{ $key }}')"
            @class(['ops-saved-view', 'ops-saved-view--active' => $active === $key])
            aria-pressed="{{ $active === $key ? 'true' : 'false' }}"
        >
            <span>{{ $viewLabel }}</span>
            @if ($count !== null)<span class="ops-saved-view__count tnum">{{ $count }}</span>@endif
        </button>
    @endforeach
</div>
