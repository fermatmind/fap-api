<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Exact package control"
            title="RIASEC Global CMS Apply Bridge"
            description="Owner-only org-0 bridge for FERMATMIND-EN-RIASEC-CMS-EXPERIMENT-01. It accepts only the frozen package through a fresh 15-minute runtime-bound preflight authorization; no free-form CMS edit is available."
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
            description="Bind the active backend identity, paste the exact package JSON from PR #3608, and paste a production baseline receipt captured within the last two hours plus its three exact source reports. Whitespace and the final newline are part of every SHA-256 identity. Evidence bodies are never written to audit logs."
        >
            <div class="mb-6 grid gap-6 lg:grid-cols-2">
                <label class="block">
                    <span class="ops-control-label">Active backend REVISION</span>
                    <input
                        class="mt-2 block w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        type="text"
                        wire:model.defer="expectedDeployedSha"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    @error('expectedDeployedSha')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="ops-control-label">Active release id</span>
                    <input
                        class="mt-2 block w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        type="text"
                        wire:model.defer="expectedReleaseId"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    @error('expectedReleaseId')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>
            </div>

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

            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <label class="block">
                    <span class="ops-control-label">backend-production-riasec-product-baseline-receipt.json</span>
                    <textarea
                        class="mt-2 block min-h-64 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="16"
                        wire:model.defer="baselineReceiptJson"
                        spellcheck="false"
                    ></textarea>
                    @error('baselineReceiptJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="ops-control-label">landing-and-product-funnel.json</span>
                    <textarea
                        class="mt-2 block min-h-64 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="16"
                        wire:model.defer="landingAndProductFunnelJson"
                        spellcheck="false"
                    ></textarea>
                    @error('landingAndProductFunnelJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="ops-control-label">attempt-result-funnel.json</span>
                    <textarea
                        class="mt-2 block min-h-64 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="16"
                        wire:model.defer="attemptResultFunnelJson"
                        spellcheck="false"
                    ></textarea>
                    @error('attemptResultFunnelJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>

                <label class="block">
                    <span class="ops-control-label">failure-cohorts.json</span>
                    <textarea
                        class="mt-2 block min-h-64 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                        rows="16"
                        wire:model.defer="failureCohortsJson"
                        spellcheck="false"
                    ></textarea>
                    @error('failureCohortsJson')
                        <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <label class="mt-6 block">
                <span class="ops-control-label">Fresh exact operator approval phrase</span>
                <textarea
                    class="mt-2 block min-h-28 w-full rounded-lg border-gray-300 bg-white font-mono text-xs shadow-sm dark:border-white/10 dark:bg-white/5"
                    rows="4"
                    wire:model.defer="operatorApprovalPhrase"
                    autocomplete="off"
                    spellcheck="false"
                ></textarea>
                @error('operatorApprovalPhrase')
                    <span class="mt-1 block text-sm text-danger-600">{{ $message }}</span>
                @enderror
            </label>

            <div class="mt-6 flex flex-wrap gap-3">
                <x-filament::button color="gray" type="button" wire:click="preflightExactPackage" wire:loading.attr="disabled">
                    Run apply preflight
                </x-filament::button>
                <x-filament::button color="primary" type="button" wire:click="applyExactPackage" wire:loading.attr="disabled">
                    Apply exact package
                </x-filament::button>
                <x-filament::button color="gray" type="button" wire:click="preflightExactRollback" wire:loading.attr="disabled">
                    Run rollback preflight
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
                    ['label' => 'Status', 'value' => data_get($receipt, 'status', 'unknown'), 'kind' => 'pill', 'state' => in_array(data_get($receipt, 'status'), ['applied', 'already_applied', 'ready_to_apply', 'ready_to_rollback', 'rolled_back', 'already_rolled_back'], true) ? 'success' : 'warning'],
                    ['label' => 'Experiment', 'value' => data_get($receipt, 'experiment_id', '-')],
                    ['label' => 'Surface', 'value' => data_get($receipt, 'surface_key', '-')],
                    ['label' => 'Locale', 'value' => data_get($receipt, 'locale', '-')],
                    ['label' => 'Deployed SHA', 'value' => data_get($receipt, 'deployed_sha', '-')],
                    ['label' => 'Release id', 'value' => data_get($receipt, 'release_id', '-')],
                    ['label' => 'Preflight fingerprint', 'value' => data_get($receipt, 'preflight_fingerprint', '-')],
                    ['label' => 'Preflight expires', 'value' => data_get($receipt, 'preflight_expires_at', '-')],
                    ['label' => 'Required approval phrase', 'value' => data_get($receipt, 'operator_approval_phrase', '-')],
                    ['label' => 'Baseline receipt SHA-256', 'value' => data_get($receipt, 'production_baseline.receipt_sha256', '-')],
                    ['label' => 'Baseline control SHA', 'value' => data_get($receipt, 'production_baseline.control_plane_sha', '-')],
                    ['label' => 'Baseline checked at', 'value' => data_get($receipt, 'production_baseline.checked_at', '-')],
                    ['label' => 'Updated at', 'value' => data_get($receipt, 'updated_at', '-')],
                    ['label' => 'Changed paths', 'value' => implode(', ', (array) data_get($receipt, 'changed_paths', []))],
                    ['label' => 'Discoverability change', 'value' => data_get($receipt, 'discoverability_change_triggered') ? 'yes' : 'no'],
                    ['label' => 'Application deploy', 'value' => data_get($receipt, 'application_deploy_triggered') ? 'yes' : 'no'],
                ]" />
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
