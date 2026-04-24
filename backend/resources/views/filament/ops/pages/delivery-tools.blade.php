<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.delivery_tools.eyebrow')"
            :title="__('ops.custom_pages.delivery_tools.title')"
            :description="__('ops.custom_pages.delivery_tools.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--2">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-delivery-order-no">{{ __('ops.custom_pages.delivery_tools.order_number') }}</label>
                        <input
                            id="ops-delivery-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="{{ __('ops.custom_pages.delivery_tools.order_number_placeholder') }}"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-delivery-tool">{{ __('ops.custom_pages.delivery_tools.tool') }}</label>
                        <select id="ops-delivery-tool" wire:model.defer="tool" class="ops-input">
                            <option value="regenerate_report">{{ __('ops.custom_pages.delivery_tools.tools.regenerate_report') }}</option>
                            <option value="resend_delivery">{{ __('ops.custom_pages.delivery_tools.tools.resend_delivery') }}</option>
                        </select>
                    </div>
                </div>

                <div class="ops-control-stack">
                    <label class="ops-control-label" for="ops-delivery-reason">{{ __('ops.custom_pages.delivery_tools.reason') }}</label>
                    <textarea
                        id="ops-delivery-reason"
                        wire:model.defer="reason"
                        rows="4"
                        placeholder="{{ __('ops.custom_pages.delivery_tools.reason_placeholder') }}"
                        class="ops-input"
                    ></textarea>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.delivery_tools.reason_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="requestAction">{{ __('ops.custom_pages.delivery_tools.request') }}</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.delivery_tools.status_title')"
            :description="__('ops.custom_pages.delivery_tools.status_desc')"
        >
            @if ($statusMessage !== '')
                <x-filament-ops::ops-result-card
                    :title="__('ops.custom_pages.delivery_tools.latest_status')"
                    :meta="$statusMessage"
                />
            @else
                <x-filament-ops::ops-empty-state
                    :eyebrow="__('ops.custom_pages.delivery_tools.title')"
                    icon="heroicon-o-truck"
                    :title="__('ops.custom_pages.delivery_tools.empty_title')"
                    :description="__('ops.custom_pages.delivery_tools.empty_desc')"
                />
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
