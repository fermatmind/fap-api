<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-2">
            <input
                type="text"
                wire:model.defer="orderNo"
                placeholder="order_no"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
            <select wire:model.defer="tool" class="block w-full rounded-lg border-gray-300 shadow-sm">
                <option value="regenerate_report">Regenerate Report</option>
                <option value="resend_delivery">Resend Delivery</option>
            </select>
        </div>

        <textarea
            wire:model.defer="reason"
            rows="4"
            placeholder="reason"
            class="block w-full rounded-lg border-gray-300 shadow-sm"
        ></textarea>

        <x-filament::button wire:click="requestAction">Request</x-filament::button>

        @if ($statusMessage !== '')
            <div class="rounded-lg border border-gray-200 bg-white p-3 text-sm">
                {{ $statusMessage }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
