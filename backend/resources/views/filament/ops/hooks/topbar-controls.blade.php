<div class="ops-topbar-controls">
    <div class="ops-topbar-controls__item ops-topbar-controls__item--environment">
        <x-filament-ops::ops-environment-badge />
    </div>
    @include('filament.ops.livewire.current-org-switcher-hook')
    @include('filament.ops.livewire.locale-switcher-hook')
    <div class="ops-topbar-controls__item ops-topbar-controls__item--theme" aria-label="{{ __('filament-panels::theme-switcher.light.label') }} / {{ __('filament-panels::theme-switcher.dark.label') }}">
        <x-filament-panels::theme-switcher />
    </div>
</div>
