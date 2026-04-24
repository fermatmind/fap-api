@php
    $guard = (string) config('admin.guard', 'admin');
    $roleLabel = auth($guard)->user()?->roles?->pluck('name')->filter()->first() ?? 'admin';
@endphp

<x-filament::dropdown placement="bottom-end">
    <x-slot name="trigger">
        <button
            type="button"
            class="ops-org-switcher"
            aria-label="{{ __('ops.topbar.current_org') }}"
        >
            <span class="ops-org-switcher__icon">
                <x-filament::icon
                    icon="heroicon-m-building-office-2"
                    class="h-4 w-4"
                />
            </span>
            <span class="ops-org-switcher__body">
                <span class="ops-org-switcher__label">{{ __('ops.topbar.org_prefix') }}</span>
                <span class="ops-org-switcher__value">{{ $orgName }}</span>
            </span>
            <x-filament::icon
                icon="heroicon-m-chevron-down"
                class="ops-org-switcher__chevron h-4 w-4"
            />
        </button>
    </x-slot>

    <x-filament::dropdown.header>
        <div class="ops-org-switcher-menu">
            <p class="ops-org-switcher-menu__name">{{ $orgName }}</p>
            <dl class="ops-org-switcher-menu__facts">
                <div>
                    <dt>{{ __('ops.topbar.org_id') }}</dt>
                    <dd>{{ $orgId ?? '—' }}</dd>
                </div>
                <div>
                    <dt>{{ __('ops.topbar.role') }}</dt>
                    <dd>{{ $roleLabel }}</dd>
                </div>
            </dl>
        </div>
    </x-filament::dropdown.header>

    <x-filament::dropdown.list>
        <x-filament::dropdown.list.item
            icon="heroicon-m-arrows-right-left"
            wire:click="goSelectOrg"
        >
            {{ $orgId ? __('ops.topbar.switch_org') : __('ops.topbar.select_org') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</x-filament::dropdown>
