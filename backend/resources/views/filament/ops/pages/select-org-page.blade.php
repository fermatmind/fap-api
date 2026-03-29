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
            <div class="ops-select-org-shell">
                <div class="ops-select-org-shell__hero">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Workspace scope</span>
                        <h2 class="ops-select-org-shell__title">Choose the active organization</h2>
                        <p class="ops-shell-inline-intro__meta">
                            Keep the existing ops URL and workspace chrome, then switch the active organization context from inside the same Fermat Ops shell.
                        </p>
                    </div>

                    <div class="ops-select-org-shell__chips">
                        <div class="ops-topbar-chip ops-select-org-shell__chip">
                            <span class="ops-topbar-chip__icon">
                                <x-filament::icon icon="heroicon-m-building-office-2" class="h-4 w-4" />
                            </span>

                            <div class="ops-topbar-chip__stack">
                                <span class="ops-topbar-chip__label">Current organization</span>
                                <span class="ops-topbar-chip__value">
                                    {{ $currentOrgId > 0 ? $currentOrgName.' (#'.$currentOrgId.')' : 'No organization selected' }}
                                </span>
                            </div>
                        </div>

                        <div class="ops-topbar-chip ops-select-org-shell__chip">
                            <span class="ops-topbar-chip__icon">
                                <x-filament::icon icon="heroicon-m-globe-alt" class="h-4 w-4" />
                            </span>

                            <div class="ops-topbar-chip__stack">
                                <span class="ops-topbar-chip__label">Visible scope</span>
                                <span class="ops-topbar-chip__value">Admin-visible workspaces in the current ops shell</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ops-workbench-toolbar ops-workbench-toolbar--split ops-select-org-toolbar">
                    <div class="ops-workbench-toolbar__main md:col-span-2">
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
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Organizations</h3>
                    <p class="ops-results-header__meta">
                        {{ $this->visibleOrganizationsCount() }} workspace{{ $this->visibleOrganizationsCount() === 1 ? '' : 's' }} visible.
                        Select one to continue inside the current Fermat Ops shell.
                    </p>
                </div>

                @if ($returnTo !== '')
                    <div class="ops-select-org-return">
                        <span class="ops-select-org-return__label">Return to</span>
                        <span class="ops-select-org-return__value">{{ $returnTo }}</span>
                    </div>
                @endif
            </div>

            @forelse ($organizations as $organization)
                <div class="ops-result-card ops-select-org-card">
                    <div class="ops-select-org-card__header">
                        <div class="ops-select-org-row__primary">
                            <p class="ops-result-card__title">{{ $organization['name'] }}</p>
                            <p class="ops-result-card__meta">org id: {{ $organization['id'] }}</p>
                        </div>

                        <x-filament::button color="primary" wire:click="selectOrg({{ $organization['id'] }})">
                            Select
                        </x-filament::button>
                    </div>

                    <div class="ops-select-org-card__facts">
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
                        <div>
                            <p class="ops-select-org-row__meta">updated_at</p>
                            <p class="ops-select-org-row__value">{{ $organization['updated_at'] !== '' ? $organization['updated_at'] : '-' }}</p>
                        </div>
                    </div>
                </div>
            @empty
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
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
