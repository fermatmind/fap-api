<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament::section>
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split">
                <div class="ops-workbench-toolbar__main">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Governance checkpoint</span>
                        <p class="ops-shell-inline-intro__meta">
                            Review the current go-live signals before releasing content or escalating operational changes.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <span class="ops-control-label">Gate status</span>
                        <x-filament.ops.shared.status-pill
                            :state="(($gate['status'] ?? 'STOP-SHIP') === 'PASS') ? 'success' : 'failed'"
                            :label="(string) ($gate['status'] ?? 'STOP-SHIP')"
                        />
                        <span class="ops-control-hint">Generated: {{ (string) ($gate['generated_at'] ?? '-') }}</span>
                    </div>
                </div>

                <div class="ops-workbench-toolbar__actions">
                    <x-filament::button size="sm" color="gray" wire:click="refreshChecks">Refresh</x-filament::button>
                    <x-filament::button size="sm" color="primary" wire:click="runChecks">Run Checks</x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @foreach (($gate['groups'] ?? []) as $groupKey => $group)
            <x-filament::section>
                <div class="ops-results-header">
                    <div>
                        <h3 class="ops-results-header__title">{{ (string) ($group['label'] ?? $groupKey) }}</h3>
                        <p class="ops-results-header__meta">Each check shares the same status language as the rest of Ops.</p>
                    </div>
                </div>

                <div class="ops-card-list mt-4">
                    @foreach (($group['checks'] ?? []) as $check)
                        <div class="ops-result-card">
                            <div class="ops-result-card__header">
                                <p class="ops-result-card__title">{{ (string) ($check['key'] ?? '-') }}</p>
                                <x-filament.ops.shared.status-pill
                                    :state="($check['passed'] ?? false) ? 'success' : 'failed'"
                                    :label="(($check['passed'] ?? false) ? 'PASS' : 'STOP-SHIP')"
                                />
                            </div>
                            <p class="ops-result-card__meta">{{ (string) ($check['message'] ?? '') }}</p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
