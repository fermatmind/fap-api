<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Support tools"
            title="Delivery tools"
            description="Request an approval-backed recovery action for a single order inside the active organization context."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--2">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-delivery-order-no">Order number</label>
                        <input
                            id="ops-delivery-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="order_no"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-delivery-tool">Tool</label>
                        <select id="ops-delivery-tool" wire:model.defer="tool" class="ops-input">
                            <option value="regenerate_report">Regenerate Report</option>
                            <option value="resend_delivery">Resend Delivery</option>
                        </select>
                    </div>
                </div>

                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-delivery-reason">Reason</label>
                    <textarea
                        id="ops-delivery-reason"
                        wire:model.defer="reason"
                        rows="4"
                        placeholder="reason"
                        class="ops-input"
                    ></textarea>
                    <p class="ops-control-hint">The request creates an approval record, it does not directly execute the action.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="requestAction">Request</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Request status"
            description="Current submission outcome for the last action attempt."
        >
            @if ($statusMessage !== '')
                <x-filament-ops::ops-result-card
                    title="Latest status"
                    :meta="$statusMessage"
                />
            @else
                <x-filament-ops::ops-empty-state
                    eyebrow="Delivery tools"
                    icon="heroicon-o-truck"
                    title="No request submitted yet"
                    description="Fill in the order number, choose a tool, and provide a reason to create an approval request."
                />
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
