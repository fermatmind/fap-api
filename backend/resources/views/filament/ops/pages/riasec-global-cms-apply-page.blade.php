<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Exact package control"
            title="RIASEC Global CMS Apply Bridge"
            description="Owner-only org-0 bridge for FERMATMIND-EN-RIASEC-CMS-EXPERIMENT-01. It accepts only the frozen before snapshot and target package bytes; no free-form CMS edit is available."
        >
            <x-filament-ops::ops-field-grid :fields="[
                ['label' => 'Surface', 'value' => \App\Services\Ops\RiasecGlobalCmsApplyBridge::SURFACE_KEY],
                ['label' => 'Locale', 'value' => \App\Services\Ops\RiasecGlobalCmsApplyBridge::LOCALE],
                ['label' => 'Authority realm', 'value' => 'org_id=0'],
                ['label' => 'Before snapshot SHA-256', 'value' => \App\Services\Ops\RiasecGlobalCmsApplyBridge::BEFORE_SNAPSHOT_SHA256],
                ['label' => 'Target package SHA-256', 'value' => \App\Services\Ops\RiasecGlobalCmsApplyBridge::TARGET_PACKAGE_SHA256],
            ]" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Frozen evidence"
            description="Paste the exact raw JSON bytes from PR #3608. Whitespace and the final newline are part of each SHA-256 identity. Evidence bodies are never written to audit logs."
        >
            <div class="grid gap-6 lg:grid-cols-2">
                <label class="block">
                    <span class="ops-control-label">current_public_readback.json</span>
                    <textarea
                        class="mt-2 block min-h-80 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="20"
                        wire:model.defer="beforeSnapshotJson"
                        spellcheck="false"
                    ></textarea>
                    @error('beforeSnapshotJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="ops-control-label">target_internal_update.json</span>
                    <textarea
                        class="mt-2 block min-h-80 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="20"
                        wire:model.defer="targetPackageJson"
                        spellcheck="false"
                    ></textarea>
                    @error('targetPackageJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <x-filament::button color="gray" type="button" wire:click="preflightExactPackage" wire:loading.attr="disabled">
                    Run fail-closed preflight
                </x-filament::button>
                <x-filament::button color="primary" type="button" wire:click="applyExactPackage" wire:loading.attr="disabled">
                    Apply exact package
                </x-filament::button>
                <x-filament::button color="danger" type="button" wire:click="rollbackExactPackage" wire:loading.attr="disabled">
                    Roll back exact package
                </x-filament::button>
            </div>
        </x-filament-ops::ops-section>

        @if ($receipt !== [])
            <x-filament-ops::ops-section
                title="Sanitized receipt"
                description="This readback contains identities and state only. It does not expose CMS content bodies."
            >
                <x-filament-ops::ops-field-grid :fields="[
                    ['label' => 'Status', 'value' => data_get($receipt, 'status', 'unknown'), 'kind' => 'pill', 'state' => in_array(data_get($receipt, 'status'), ['applied', 'already_applied', 'ready_to_apply', 'rolled_back', 'already_rolled_back'], true) ? 'success' : 'warning'],
                    ['label' => 'Experiment', 'value' => data_get($receipt, 'experiment_id', '-')],
                    ['label' => 'Surface', 'value' => data_get($receipt, 'surface_key', '-')],
                    ['label' => 'Locale', 'value' => data_get($receipt, 'locale', '-')],
                    ['label' => 'Updated at', 'value' => data_get($receipt, 'updated_at', '-')],
                    ['label' => 'Changed paths', 'value' => implode(', ', (array) data_get($receipt, 'changed_paths', []))],
                    ['label' => 'Discoverability change', 'value' => data_get($receipt, 'discoverability_change_triggered') ? 'yes' : 'no'],
                    ['label' => 'Application deploy', 'value' => data_get($receipt, 'application_deploy_triggered') ? 'yes' : 'no'],
                ]" />
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
