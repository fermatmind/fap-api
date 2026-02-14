<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <x-filament::button wire:click="refresh">Refresh</x-filament::button>
            @if ($statusMessage !== '')
                <span class="text-sm text-gray-600">{{ $statusMessage }}</span>
            @endif
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="min-w-full divide-y divide-gray-200 text-xs">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">ID</th>
                        <th class="px-3 py-2 text-left">Connection</th>
                        <th class="px-3 py-2 text-left">Queue</th>
                        <th class="px-3 py-2 text-left">Failed At</th>
                        <th class="px-3 py-2 text-left">Exception</th>
                        <th class="px-3 py-2 text-left">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @forelse ($failedJobs as $job)
                        <tr>
                            <td class="px-3 py-2">{{ $job['id'] }}</td>
                            <td class="px-3 py-2">{{ $job['connection'] }}</td>
                            <td class="px-3 py-2">{{ $job['queue'] }}</td>
                            <td class="px-3 py-2">{{ $job['failed_at'] }}</td>
                            <td class="px-3 py-2">{{ $job['exception'] }}</td>
                            <td class="px-3 py-2">
                                <x-filament::button size="xs" wire:click="retry({{ $job['id'] }})">Retry</x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-4 text-center text-gray-500">No failed jobs.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
