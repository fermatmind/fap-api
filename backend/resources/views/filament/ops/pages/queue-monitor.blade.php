<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar :split="false" class="ops-toolbar--center-actions">
                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <x-filament::button wire:click="refresh">刷新</x-filament::button>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="失败任务"
        >
            @if ($statusMessage !== '')
                <x-slot name="actions">
                    <span class="ops-results-header__meta">{{ $statusMessage }}</span>
                </x-slot>
            @endif

            <x-filament-ops::ops-table
                :has-rows="$failedJobs !== []"
                empty-description=""
                empty-eyebrow=""
                empty-icon="heroicon-o-queue-list"
                empty-title="暂无失败任务"
            >
                <x-slot name="head">
                    <tr>
                        <th>ID</th>
                        <th>连接</th>
                        <th>队列</th>
                        <th>失败时间</th>
                        <th>异常</th>
                        <th>操作</th>
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
                            <x-filament::button size="xs" wire:click="retry({{ $job['id'] }})">重试</x-filament::button>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
