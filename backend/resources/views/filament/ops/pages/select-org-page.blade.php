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
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split ops-select-org-toolbar">
                <div class="ops-workbench-toolbar__main md:col-span-2">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Workspace scope</span>
                        <p class="ops-shell-inline-intro__meta">
                            Choose the organization context before editing content, reviewing commerce, or running runtime workflows.
                        </p>
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-org-search">Search organizations</label>
                        <input
                            id="ops-org-search"
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Search by org name or org id"
                            class="ops-input"
                        />
                        <p class="ops-control-hint">Search the current admin-visible organization list by name or exact org id.</p>
                    </div>
                </div>
                <div class="ops-workbench-toolbar__actions ops-select-org-actions">
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
                            <x-filament.ops.shared.status-pill
                                class="mt-2"
                                :state="$organization['status']"
                                :label="$organization['status']"
                            />
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
                    <x-filament.ops.shared.empty-state
                        class="ops-select-org-empty"
                        eyebrow="Organization scope"
                        icon="heroicon-o-building-office-2"
                        title="No organizations found"
                        :description="$this->whyVisibleHint()"
                    >
                        <x-slot name="actions">
                            @if ($this->canCreateOrganization())
                                <x-filament::button color="primary" wire:click="createOrganization">
                                    Create Organization
                                </x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="goToImport">
                                Import/Sync Organizations
                            </x-filament::button>
                        </x-slot>
                    </x-filament.ops.shared.empty-state>
                </x-filament::section>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
