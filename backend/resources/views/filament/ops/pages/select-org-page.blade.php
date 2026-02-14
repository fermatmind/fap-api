<x-filament-panels::page>
    <div class="space-y-4">
        <div class="max-w-xl">
            <label class="block text-sm font-medium text-gray-700" for="ops-org-search">Search organizations</label>
            <input
                id="ops-org-search"
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by org name or id"
                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
            />
        </div>

        <div class="space-y-3">
            @forelse ($organizations as $organization)
                <div class="flex items-center justify-between rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ $organization['name'] }}</p>
                        <p class="text-xs text-gray-500">org_id={{ $organization['id'] }}</p>
                    </div>

                    <x-filament::button
                        color="primary"
                        wire:click="selectOrg({{ $organization['id'] }})"
                    >
                        Select
                    </x-filament::button>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500">
                    No organizations found.
                </div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
