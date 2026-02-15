<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-2">
            <input
                type="text"
                wire:model.defer="orderNo"
                placeholder="order_no"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
            <input
                type="number"
                min="1"
                max="120"
                wire:model.defer="ttlMinutes"
                placeholder="ttl_minutes"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
        </div>

        <x-filament::button wire:click="generate">Generate Secure Link</x-filament::button>

        @if ($statusMessage !== '')
            <div class="rounded-lg border border-gray-200 bg-white p-3 text-sm">
                {{ $statusMessage }}
            </div>
        @endif

        @if ($generatedLink !== '')
            <div class="rounded-lg border p-3 text-sm break-all">
                <a href="{{ $generatedLink }}" target="_blank" class="text-primary-600 underline">{{ $generatedLink }}</a>
            </div>
        @endif
    </div>
</x-filament-panels::page>
