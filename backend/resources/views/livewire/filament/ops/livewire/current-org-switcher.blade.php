<div class="flex items-center gap-x-2">
    <span class="text-sm text-gray-600 dark:text-gray-300">
        当前企业：
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
        {{ $orgId ? '切换企业' : '选择企业' }}
    </x-filament::button>
</div>
