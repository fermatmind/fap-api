<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SRE controls"
            title="Queue monitor"
            description="Inspect failed jobs and retry individual records without leaving the Ops shell."
        >
            <x-filament-ops::ops-toolbar :split="false">
                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button wire:click="refresh">Refresh</x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Failed jobs"
            description="Latest failed queue jobs across the current runtime with direct retry controls."
        >
            @if ($statusMessage !== '')
                <x-slot name="actions">
                    <span class="ops-results-header__meta">{{ $statusMessage }}</span>
                </x-slot>
            @endif

            <x-filament-ops::ops-table
                :has-rows="$failedJobs !== []"
                empty-description="There are currently no failed jobs in the queue backlog."
                empty-eyebrow="Queue monitor"
                empty-icon="heroicon-o-queue-list"
                empty-title="No failed jobs"
            >
                <x-slot name="head">
                    <tr>
                        <th>ID</th>
                        <th>Connection</th>
                        <th>Queue</th>
                        <th>Failed At</th>
                        <th>Exception</th>
                        <th>Action</th>
                    </tr>
                </x-slot>

                @foreach ($failedJobs as $job)
                    <tr>
                        <td>{{ $job['id'] }}</td>
                        <td>{{ $job['connection'] }}</td>
                        <td>{{ $job['queue'] }}</td>
                        <td>{{ $job['failed_at'] }}</td>
                        <td>{{ $job['exception'] }}</td>
                        <td>
                            <x-filament::button size="xs" wire:click="retry({{ $job['id'] }})">Retry</x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
