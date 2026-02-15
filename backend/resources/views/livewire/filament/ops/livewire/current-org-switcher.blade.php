<div class="flex items-center gap-x-2">
    <span class="text-sm text-gray-600 dark:text-gray-300">
        {{ __('ops.topbar.current_org') }}:
        <span class="font-medium text-gray-900 dark:text-gray-100">
            {{ $orgName }}
        </span>
    </span>

    <x-filament::button
        type="button"
        size="sm"
        color="gray"
        wire:click="goSelectOrg"
    >
        {{ $orgId ? __('ops.topbar.switch_org') : __('ops.topbar.select_org') }}
    </x-filament::button>
</div>
