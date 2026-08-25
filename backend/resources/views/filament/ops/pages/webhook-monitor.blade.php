<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar class="ops-toolbar--center-actions">
                <div class="ops-toolbar-inline">
                    <div class="ops-control-stack ops-control-stack--compact">
                        <label class="ops-control-label" for="ops-webhook-limit">近期事件数量</label>
                        <input
                            id="ops-webhook-limit"
                            type="number"
                            min="10"
                            max="200"
                            wire:model.defer="limit"
                            class="ops-input ops-input--compact"
                        />
                    </div>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="refresh">刷新</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="失败快照"
        >
            <div class="ops-page-grid ops-page-grid--2">
                <x-filament-ops::ops-result-card
                    title="签名失败"
                >
                    <x-slot name="badges">
                        <x-filament.ops.shared.status-pill
                            :state="$aggregates['signature_failed'] > 0 ? 'danger' : 'success'"
                            :label="$aggregates['signature_failed'] > 0 ? '需处理' : '正常'"
                        />
                    </x-slot>

                    <p class="ops-metric-value">{{ $aggregates['signature_failed'] }}</p>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card
                    title="处理失败"
                >
                    <x-slot name="badges">
                        <x-filament.ops.shared.status-pill
                            :state="$aggregates['processed_failed'] > 0 ? 'danger' : 'success'"
                            :label="$aggregates['processed_failed'] > 0 ? '需处理' : '正常'"
                        />
                    </x-slot>

                    <p class="ops-metric-value">{{ $aggregates['processed_failed'] }}</p>
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="近期支付事件"
        >
            <x-filament-ops::ops-table
                :has-rows="$events !== []"
                empty-description=""
                empty-eyebrow=""
                empty-icon="heroicon-o-bolt"
                empty-title="未找到支付事件"
            >
                <x-slot name="head">
                    <tr>
                        <th>支付渠道</th>
                        <th>Event ID</th>
                        <th>订单号</th>
                        <th>签名</th>
                        <th>状态</th>
                        <th>处理状态</th>
                        <th>错误</th>
                        <th>创建时间</th>
                    </tr>
                </x-slot>

                @foreach ($events as $event)
                    <tr>
                        <td>{{ $event['provider'] }}</td>
                        <td>{{ $event['provider_event_id'] }}</td>
                        <td>{{ $event['order_no'] }}</td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="$event['signature_ok'] ? 'success' : 'danger'"
                                :label="$event['signature_ok'] ? 'OK' : 'FAIL'"
                            />
                        </td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="(string) ($event['status'] ?? 'gray')"
                                :label="(string) ($event['status'] ?? '-')"
                            />
                        </td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="(string) ($event['handle_status'] ?? 'gray')"
                                :label="(string) ($event['handle_status'] ?? '-')"
                            />
                        </td>
                        <td>{{ $event['last_error_code'] !== '' ? $event['last_error_code'] : '-' }}</td>
                        <td>{{ $event['created_at'] }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
