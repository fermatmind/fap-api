<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Org bootstrap"
            title="Import/Sync Organizations"
            description="This is the v1 placeholder for organization import and sync. Use the current runbook-driven flow until automation is enabled."
        >
            <x-filament-ops::ops-toolbar>
                <x-filament-ops::ops-empty-state
                    eyebrow="Organization sync"
                    icon="heroicon-o-arrow-down-tray"
                    title="Runbook-driven import only"
                    description="Automation is not enabled yet, but the action surface stays inside the same Ops shell with clear next steps."
                />

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button color="primary" tag="a" href="/ops/select-org">
                            Back to Select Org
                        </x-filament::button>
                        <x-filament::button color="gray" tag="a" href="/docs/04-ops/ops-bootstrap.md" target="_blank">
                            Open Runbook
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
