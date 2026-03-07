<x-filament-panels::page>
    <div class="ops-shell-page ops-select-org-page space-y-6">
        @if (session()->has('ops_org_required_message'))
            <x-filament::section>
                <p class="ops-shell-callout ops-shell-callout--warning">
                    {{ (string) session('ops_org_required_message') }}
                </p>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="ops-select-org-toolbar grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Workspace scope</span>
                        <p class="ops-shell-inline-intro__meta">
                            Choose the organization context before editing content, reviewing commerce, or running runtime workflows.
                        </p>
                    </div>

                    <label class="block text-sm font-medium text-gray-700" for="ops-org-search">Search organizations</label>
                    <input
                        id="ops-org-search"
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search by org name or org id"
                        class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>
                <div class="ops-select-org-actions flex items-end gap-2">
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
                    <div class="ops-select-org-row grid gap-3 md:grid-cols-6 md:items-center">
                        <div class="ops-select-org-row__primary md:col-span-2">
                            <p class="ops-select-org-row__title">{{ $organization['name'] }}</p>
                            <p class="ops-select-org-row__meta">org id: {{ $organization['id'] }}</p>
                        </div>
                        <div>
                            <p class="ops-select-org-row__meta">status</p>
                            <p class="ops-select-org-row__value">{{ $organization['status'] }}</p>
                        </div>
                        <div>
                            <p class="ops-select-org-row__meta">domain</p>
                            <p class="ops-select-org-row__value">{{ $organization['domain'] ?: '-' }}</p>
                        </div>
                        <div class="ops-select-org-row__actions md:col-span-2 flex items-center justify-between gap-2">
                            <div>
                                <p class="ops-select-org-row__meta">updated_at</p>
                                <p class="ops-select-org-row__value">{{ $organization['updated_at'] !== '' ? $organization['updated_at'] : '-' }}</p>
                            </div>

                            <x-filament::button color="primary" wire:click="selectOrg({{ $organization['id'] }})">
                                Select
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <div class="ops-select-org-empty space-y-4">
                        <div>
                            <p class="ops-select-org-row__title">No organizations found</p>
                            <p class="ops-shell-inline-intro__meta">{{ $this->whyVisibleHint() }}</p>
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
