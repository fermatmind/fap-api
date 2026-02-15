<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::section>
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-semibold text-gray-900">Gate Status:</span>
                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ (($gate['status'] ?? 'STOP-SHIP') === 'PASS') ? 'bg-success-100 text-success-700' : 'bg-danger-100 text-danger-700' }}">
                    {{ (string) ($gate['status'] ?? 'STOP-SHIP') }}
                </span>
                <x-filament::button size="sm" color="gray" wire:click="refreshChecks">Refresh</x-filament::button>
                <x-filament::button size="sm" color="primary" wire:click="runChecks">Run Checks</x-filament::button>
            </div>
            <p class="mt-2 text-xs text-gray-500">Generated: {{ (string) ($gate['generated_at'] ?? '-') }}</p>
        </x-filament::section>

        @foreach (($gate['groups'] ?? []) as $groupKey => $group)
            <x-filament::section>
                <h3 class="text-sm font-semibold text-gray-900">{{ (string) ($group['label'] ?? $groupKey) }}</h3>
                <div class="mt-3 space-y-2">
                    @foreach (($group['checks'] ?? []) as $check)
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-900">{{ (string) ($check['key'] ?? '-') }}</p>
                                <span class="rounded-full px-2 py-1 text-xs font-semibold {{ (($check['passed'] ?? false) ? 'bg-success-100 text-success-700' : 'bg-danger-100 text-danger-700') }}">
                                    {{ (($check['passed'] ?? false) ? 'PASS' : 'STOP-SHIP') }}
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ (string) ($check['message'] ?? '') }}</p>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
