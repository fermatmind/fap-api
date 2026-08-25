<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar>
                <x-filament-ops::ops-empty-state
                    eyebrow=""
                    icon="heroicon-o-arrow-down-tray"
                    :title="__('ops.select_org.import_page.empty_title')"
                    description=""
                />

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button color="primary" tag="a" href="/ops/select-org">
                            {{ __('ops.select_org.import_page.back_to_select') }}
                        </x-filament::button>
                        <x-filament::button color="gray" tag="a" href="/docs/04-ops/ops-bootstrap.md" target="_blank">
                            {{ __('ops.select_org.import_page.open_runbook') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
