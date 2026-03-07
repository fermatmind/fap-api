<div class="ops-topbar-chip ops-topbar-chip--org">
    <span class="ops-topbar-chip__icon">
        <x-filament::icon
            icon="heroicon-m-building-office-2"
            class="h-4 w-4"
        />
    </span>

    <div class="ops-topbar-chip__stack">
        <span class="ops-topbar-chip__label">
            {{ __('ops.topbar.current_org') }}
        </span>
        <span class="ops-topbar-chip__value">
            {{ $orgName }}
        </span>
    </div>

    <x-filament::button
        type="button"
        size="sm"
        color="gray"
        class="ops-topbar-chip__action"
        wire:click="goSelectOrg"
    >
        {{ $orgId ? __('ops.topbar.switch_org') : __('ops.topbar.select_org') }}
    </x-filament::button>
</div>
