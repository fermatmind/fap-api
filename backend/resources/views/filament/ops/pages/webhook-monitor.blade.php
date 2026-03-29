<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SRE controls"
            title="Webhook monitor"
            description="Inspect recent payment event failures inside the active organization context without leaving the Ops shell."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-toolbar-inline">
                    <div class="ops-control-stack ops-control-stack--compact">
                        <label class="ops-control-label" for="ops-webhook-limit">Recent event limit</label>
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
                    <x-filament::button wire:click="refresh">Refresh</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Failure snapshot"
            description="Current counts for signature failures and processing failures in the active organization."
        >
            <div class="ops-page-grid ops-page-grid--2">
                <x-filament-ops::ops-result-card
                    title="Signature failures"
                    meta="signature_ok = false"
                >
                    <x-slot name="badges">
                        <x-filament.ops.shared.status-pill
                            :state="$aggregates['signature_failed'] > 0 ? 'danger' : 'success'"
                            :label="$aggregates['signature_failed'] > 0 ? 'attention' : 'clear'"
                        />
                    </x-slot>

                    <p class="ops-metric-value">{{ $aggregates['signature_failed'] }}</p>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card
                    title="Processing failures"
                    meta="status or handle failure backlog"
                >
                    <x-slot name="badges">
                        <x-filament.ops.shared.status-pill
                            :state="$aggregates['processed_failed'] > 0 ? 'danger' : 'success'"
                            :label="$aggregates['processed_failed'] > 0 ? 'attention' : 'clear'"
                        />
                    </x-slot>

                    <p class="ops-metric-value">{{ $aggregates['processed_failed'] }}</p>
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Recent payment events"
            description="Latest-first event feed with signature state, webhook status, handling status, and error code."
        >
            @if ($events === [])
                <x-filament-ops::ops-empty-state
                    eyebrow="Webhook monitor"
                    icon="heroicon-o-bolt"
                    title="No payment events found"
                    description="The current organization has no recent payment events in the selected monitoring window."
                />
            @else
                <div class="ops-monitor-table-shell">
                    <table class="ops-monitor-table">
                        <thead>
                            <tr>
                                <th>Provider</th>
                                <th>Event ID</th>
                                <th>Order No</th>
                                <th>Sig</th>
                                <th>Status</th>
                                <th>Handle</th>
                                <th>Error</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                <tr>
                                    <td>{{ $event['provider'] }}</td>
                                    <td>{{ $event['provider_event_id'] }}</td>
                                    <td>{{ $event['order_no'] }}</td>
                                    <td class="ops-monitor-table__status">
                                        <x-filament.ops.shared.status-pill
                                            :state="$event['signature_ok'] ? 'success' : 'danger'"
                                            :label="$event['signature_ok'] ? 'OK' : 'FAIL'"
                                        />
                                    </td>
                                    <td class="ops-monitor-table__status">
                                        <x-filament.ops.shared.status-pill
                                            :state="(string) ($event['status'] ?? 'gray')"
                                            :label="(string) ($event['status'] ?? '-')"
                                        />
                                    </td>
                                    <td class="ops-monitor-table__status">
                                        <x-filament.ops.shared.status-pill
                                            :state="(string) ($event['handle_status'] ?? 'gray')"
                                            :label="(string) ($event['handle_status'] ?? '-')"
                                        />
                                    </td>
                                    <td>{{ $event['last_error_code'] !== '' ? $event['last_error_code'] : '-' }}</td>
                                    <td>{{ $event['created_at'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
