<x-filament-panels::page>
    <div class="space-y-6">
        @if (session()->has('ops_org_required_message'))
            <x-filament::section>
                <p class="text-sm text-warning-700">
                    {{ (string) session('ops_org_required_message') }}
                </p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700" for="ops-org-search">Search organizations</label>
                    <input
                        id="ops-org-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by org name or org id"
                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>
                <div class="flex items-end gap-2">
                    @if ($this->canCreateOrganization())
                        <x-filament::button color="primary" wire:click="createOrganization">
                            Create Organization
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" wire:click="goToImport">
                        Import/Sync Organizations
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <div class="space-y-3">
            @forelse ($organizations as $organization)
                <x-filament::section>
                    <div class="grid gap-3 md:grid-cols-6 md:items-center">
                        <div class="md:col-span-2">
                            <p class="text-sm font-semibold text-gray-900">{{ $organization['name'] }}</p>
                            <p class="text-xs text-gray-500">org id: {{ $organization['id'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">status</p>
                            <p class="text-sm font-medium">{{ $organization['status'] }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">domain</p>
                            <p class="text-sm font-medium">{{ $organization['domain'] ?: '-' }}</p>
                        </div>
                        <div class="md:col-span-2 flex items-center justify-between gap-2">
                            <div>
                                <p class="text-xs text-gray-500">updated_at</p>
                                <p class="text-sm font-medium">{{ $organization['updated_at'] !== '' ? $organization['updated_at'] : '-' }}</p>
                            </div>

                            <x-filament::button color="primary" wire:click="selectOrg({{ $organization['id'] }})">
                                Select
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <div class="space-y-4">
                        <div>
                            <p class="text-base font-semibold text-gray-900">No organizations found</p>
                            <p class="text-sm text-gray-600">{{ $this->whyVisibleHint() }}</p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($this->canCreateOrganization())
                                <x-filament::button color="primary" wire:click="createOrganization">
                                    Create Organization
                                </x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="goToImport">
                                Import/Sync Organizations
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
