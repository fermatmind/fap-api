<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::button wire:click="refreshChecks">Refresh</x-filament::button>

        <div class="grid gap-3 md:grid-cols-3">
            @foreach ($checks as $name => $check)
                <div class="rounded-lg border p-4">
                    <div class="text-sm font-semibold">{{ strtoupper($name) }}</div>
                    <div class="mt-1 text-xs {{ ($check['ok'] ?? false) ? 'text-green-600' : 'text-red-600' }}">
                        {{ ($check['ok'] ?? false) ? 'OK' : 'FAIL' }}
                    </div>
                    <div class="mt-2 text-xs text-gray-600 break-all">{{ $check['message'] ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
