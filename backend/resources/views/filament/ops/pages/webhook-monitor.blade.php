<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <input
                type="number"
                min="10"
                max="200"
                wire:model.defer="limit"
                class="w-28 rounded-lg border-gray-300 shadow-sm"
            />
            <x-filament::button wire:click="refresh">Refresh</x-filament::button>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <div class="rounded-lg border p-4">
                <div class="text-xs text-gray-500">signature_ok = false</div>
                <div class="text-xl font-semibold">{{ $aggregates['signature_failed'] }}</div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-xs text-gray-500">processed failed</div>
                <div class="text-xl font-semibold">{{ $aggregates['processed_failed'] }}</div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">Provider</th>
                        <th class="px-3 py-2 text-left">Event ID</th>
                        <th class="px-3 py-2 text-left">Order No</th>
                        <th class="px-3 py-2 text-left">Sig</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Handle</th>
                        <th class="px-3 py-2 text-left">Error</th>
                        <th class="px-3 py-2 text-left">Created At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($events as $event)
                        <tr>
                            <td class="px-3 py-2">{{ $event['provider'] }}</td>
                            <td class="px-3 py-2">{{ $event['provider_event_id'] }}</td>
                            <td class="px-3 py-2">{{ $event['order_no'] }}</td>
                            <td class="px-3 py-2">{{ $event['signature_ok'] ? 'OK' : 'FAIL' }}</td>
                            <td class="px-3 py-2">{{ $event['status'] }}</td>
                            <td class="px-3 py-2">{{ $event['handle_status'] }}</td>
                            <td class="px-3 py-2">{{ $event['last_error_code'] }}</td>
                            <td class="px-3 py-2">{{ $event['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-4 text-center text-gray-500">No payment events.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
