@props(['sections', 'active'])

<nav class="ops-automation-nav" aria-label="{{ __('ops.custom_pages.seo_operations.automation_nav.label') }}">
    @foreach ($sections as $section)
        <button
            type="button"
            @class(['ops-automation-nav__item', 'ops-automation-nav__item--active' => $active === $section])
            wire:click="openAutomationSection('{{ $section }}')"
            aria-pressed="{{ $active === $section ? 'true' : 'false' }}"
        >
            {{ __('ops.custom_pages.seo_operations.automation_nav.'.$section) }}
        </button>
    @endforeach
</nav>
