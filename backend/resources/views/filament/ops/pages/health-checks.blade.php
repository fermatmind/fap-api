<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar :split="false" class="ops-toolbar--center-actions">
                <x-slot name="actions">
                    <x-filament::button wire:click="refreshChecks">刷新</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="依赖卡片"
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
                                :label="($check['ok'] ?? false) ? '正常' : '异常'"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
