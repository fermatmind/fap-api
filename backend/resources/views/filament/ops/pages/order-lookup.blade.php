<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid gap-3 md:grid-cols-3">
            <input
                type="text"
                wire:model.defer="orderNo"
                placeholder="order_no"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
            <input
                type="text"
                wire:model.defer="email"
                placeholder="email"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
            <input
                type="text"
                wire:model.defer="attemptId"
                placeholder="attempt_id"
                class="block w-full rounded-lg border-gray-300 shadow-sm"
            />
        </div>

        <x-filament::button wire:click="search">Search</x-filament::button>

        @if ($order)
            <div class="rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Order</h3>
                <pre class="mt-2 whitespace-pre-wrap text-xs">{{ json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>

            <div class="rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Payment Events</h3>
                <pre class="mt-2 whitespace-pre-wrap text-xs">{{ json_encode($paymentEvents, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>

            <div class="rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Benefit Grants</h3>
                <pre class="mt-2 whitespace-pre-wrap text-xs">{{ json_encode($benefitGrants, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>

            <div class="rounded-lg border p-4">
                <h3 class="text-sm font-semibold">Attempt</h3>
                <pre class="mt-2 whitespace-pre-wrap text-xs">{{ json_encode($attempt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @endif
    </div>
</x-filament-panels::page>
