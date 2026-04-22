<x-filament-panels::page>
    <div class="ops-shell-page ops-select-org-page space-y-6">
        @if (session()->has('ops_org_required_message'))
            <x-filament-ops::ops-section>
                <p class="ops-shell-callout ops-shell-callout--warning">
                    {{ (string) session('ops_org_required_message') }}
                </p>
            </x-filament-ops::ops-section>
        @endif

        <x-filament-ops::ops-section>
            <div class="ops-select-org-shell">
                <div class="ops-select-org-shell__hero">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">{{ __('ops.select_org.workspace_scope') }}</span>
                        <h2 class="ops-select-org-shell__title">{{ __('ops.select_org.choose_active') }}</h2>
                        <p class="ops-shell-inline-intro__meta">
                            {{ __('ops.select_org.shell_meta') }}
                        </p>
                    </div>

                    <div class="ops-select-org-shell__chips">
                        <div class="ops-topbar-chip ops-select-org-shell__chip">
                            <span class="ops-topbar-chip__icon">
                                <x-filament::icon icon="heroicon-m-building-office-2" class="h-4 w-4" />
                            </span>

                            <div class="ops-topbar-chip__stack">
                                <span class="ops-topbar-chip__label">{{ __('ops.topbar.current_org') }}</span>
                                <span class="ops-topbar-chip__value">
                                    {{ $currentOrgId > 0 ? $currentOrgName.' (#'.$currentOrgId.')' : __('ops.topbar.no_org_selected') }}
                                </span>
                            </div>
                        </div>

                        <div class="ops-topbar-chip ops-select-org-shell__chip">
                            <span class="ops-topbar-chip__icon">
                                <x-filament::icon icon="heroicon-m-globe-alt" class="h-4 w-4" />
                            </span>

                            <div class="ops-topbar-chip__stack">
                                <span class="ops-topbar-chip__label">{{ __('ops.select_org.visible_scope') }}</span>
                                <span class="ops-topbar-chip__value">{{ __('ops.select_org.visible_scope_meta') }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <x-filament-ops::ops-toolbar class="ops-select-org-toolbar">
                    <div class="md:col-span-2">
                        <div class="ops-control-stack">
                            <label class="ops-control-label" for="ops-org-search">{{ __('ops.select_org.search_label') }}</label>
                            <input
                                id="ops-org-search"
                                type="text"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('ops.select_org.search_placeholder') }}"
                                class="ops-input"
                            />
                            <p class="ops-control-hint">{{ __('ops.select_org.search_hint') }}</p>
                        </div>
                    </div>

                    <x-slot name="actions">
                        <div class="ops-select-org-actions">
                            @if ($this->canCreateOrganization())
                                <x-filament::button color="primary" wire:click="createOrganization">
                                    {{ __('ops.select_org.create_org') }}
                                </x-filament::button>
                            @endif
                            <x-filament::button color="gray" wire:click="goToImport">
                                {{ __('ops.select_org.import_orgs') }}
                            </x-filament::button>
                        </div>
                    </x-slot>
                </x-filament-ops::ops-toolbar>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.select_org.organizations')"
            :description="trans_choice('ops.select_org.visible_workspaces', $this->visibleOrganizationsCount(), ['count' => $this->visibleOrganizationsCount()])"
        >
            @if ($returnTo !== '')
                <x-slot name="actions">
                    <div class="ops-select-org-return">
                        <span class="ops-select-org-return__label">{{ __('ops.select_org.return_to') }}</span>
                        <span class="ops-select-org-return__value">{{ $returnTo }}</span>
                    </div>
                </x-slot>
            @endif

            @forelse ($organizations as $organization)
                <x-filament-ops::ops-result-card class="ops-select-org-card">
                    <x-slot name="actions">
                        <x-filament::button color="primary" wire:click="selectOrg({{ $organization['id'] }})">
                            {{ __('ops.select_org.select') }}
                        </x-filament::button>
                    </x-slot>

                    <p class="ops-result-card__title">{{ $organization['name'] }}</p>
                    <p class="ops-result-card__meta">{{ __('ops.select_org.org_id') }}: {{ $organization['id'] }}</p>

                    <div class="ops-select-org-card__facts">
                        <div>
                            <p class="ops-select-org-row__meta">{{ __('ops.select_org.status') }}</p>
                            <x-filament.ops.shared.status-pill
                                class="mt-2"
                                :state="$organization['status']"
                                :label="\App\Filament\Ops\Support\StatusBadge::label($organization['status'])"
                            />
                        </div>
                        <div>
                            <p class="ops-select-org-row__meta">{{ __('ops.select_org.domain') }}</p>
                            <p class="ops-select-org-row__value">{{ $organization['domain'] ?: '-' }}</p>
                        </div>
                        <div>
                            <p class="ops-select-org-row__meta">{{ __('ops.select_org.updated_at') }}</p>
                            <p class="ops-select-org-row__value">{{ $organization['updated_at'] !== '' ? $organization['updated_at'] : '-' }}</p>
                        </div>
                    </div>
                </x-filament-ops::ops-result-card>
            @empty
                <x-filament-ops::ops-empty-state
                    class="ops-select-org-empty"
                    :eyebrow="__('ops.select_org.organization_scope')"
                    icon="heroicon-o-building-office-2"
                    :title="__('ops.select_org.no_organizations')"
                    :description="$this->whyVisibleHint()"
                >
                    <x-slot name="actions">
                        @if ($this->canCreateOrganization())
                            <x-filament::button color="primary" wire:click="createOrganization">
                                {{ __('ops.select_org.create_org') }}
                            </x-filament::button>
                        @endif
                        <x-filament::button color="gray" wire:click="goToImport">
                            {{ __('ops.select_org.import_orgs') }}
                        </x-filament::button>
                    </x-slot>
                </x-filament-ops::ops-empty-state>
            @endforelse
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
