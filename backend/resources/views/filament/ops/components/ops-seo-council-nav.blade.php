@props(['workspaces', 'active'])

<nav {{ $attributes->class(['ops-seo-council-nav']) }} aria-label="{{ __('ops.custom_pages.seo_operations.council_nav_label') }}">
    @foreach ($workspaces as $workspace)
        <button
            type="button"
            wire:click="openDecisionWorkspace('{{ $workspace }}')"
            @class(['ops-seo-council-nav__item', 'ops-seo-council-nav__item--active' => $active === $workspace])
            aria-current="{{ $active === $workspace ? 'page' : 'false' }}"
            aria-controls="ops-seo-workspace-panel"
        >
            {{ __('ops.custom_pages.seo_operations.workspace.'.$workspace) }}
        </button>
    @endforeach
</nav>
