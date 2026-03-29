<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SRE status"
            title="Health checks"
            description="Read-only connectivity and service posture. Mailer details remain summary-only; credentials are never displayed."
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-toolbar-inline">
                    <p class="ops-control-hint">Refresh the latest database, redis, queue, and mailer health summary.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="refreshChecks">Refresh</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Dependency cards"
            description="Unified health cards for the currently configured runtime dependencies."
        >
            <div class="ops-page-grid ops-page-grid--4">
                @foreach ($checks as $name => $check)
                    <x-filament-ops::ops-result-card
                        :title="strtoupper((string) $name)"
                        :meta="(string) ($check['message'] ?? '')"
                    >
                        <x-slot name="badges">
                            <x-filament.ops.shared.status-pill
                                :state="($check['ok'] ?? false) ? 'success' : 'danger'"
                                :label="($check['ok'] ?? false) ? 'OK' : 'FAIL'"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
