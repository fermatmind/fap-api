<div class="ops-topbar-chip">
    <div class="ops-topbar-chip__text">
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
        wire:click="goSelectOrg"
    >
        {{ $orgId ? __('ops.topbar.switch_org') : __('ops.topbar.select_org') }}
    </x-filament::button>
</div>
