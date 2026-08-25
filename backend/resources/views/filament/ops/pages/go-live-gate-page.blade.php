<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament::section>
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split">
                <div class="ops-workbench-toolbar__main">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="ops-control-label">{{ __('ops.custom_pages.go_live_gate.status_label') }}</span>
                        <x-filament.ops.shared.status-pill
                            :state="(($gate['status'] ?? 'STOP-SHIP') === 'PASS') ? 'success' : 'failed'"
                            :label="(($gate['status'] ?? 'STOP-SHIP') === 'PASS') ? '通过' : '禁止上线'"
                        />
                        <span class="ops-control-hint">{{ __('ops.custom_pages.go_live_gate.generated_at', ['time' => (string) ($gate['generated_at'] ?? '-')]) }}</span>
                    </div>
                </div>

                <div class="ops-workbench-toolbar__actions">
                    <x-filament::button size="sm" color="gray" wire:click="refreshChecks">{{ __('ops.custom_pages.go_live_gate.actions.refresh') }}</x-filament::button>
                    <x-filament::button size="sm" color="primary" wire:click="runChecks">{{ __('ops.custom_pages.go_live_gate.actions.run_checks') }}</x-filament::button>
                </div>
            </div>
        </x-filament::section>

        @foreach (($gate['groups'] ?? []) as $groupKey => $group)
            <x-filament::section>
                <div class="ops-results-header">
                    <div>
                        <h3 class="ops-results-header__title">{{ $this->groupLabel((string) $groupKey, (array) $group) }}</h3>
                    </div>
                </div>

                <div class="ops-card-list mt-4">
                    @foreach (($group['checks'] ?? []) as $check)
                        <div class="ops-result-card">
                            <div class="ops-result-card__header">
                                <p class="ops-result-card__title">{{ $this->checkLabel((array) $check) }}</p>
                                <x-filament.ops.shared.status-pill
                                    :state="($check['passed'] ?? false) ? 'success' : 'failed'"
                                    :label="(($check['passed'] ?? false) ? '通过' : '禁止上线')"
                                />
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
