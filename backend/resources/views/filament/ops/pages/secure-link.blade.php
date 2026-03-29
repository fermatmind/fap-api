<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Support tools"
            title="Secure link"
            description="Generate a short-lived claim URL for a single order inside the active organization context."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--2">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-secure-link-order-no">Order number</label>
                        <input
                            id="ops-secure-link-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="order_no"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-secure-link-ttl">TTL minutes</label>
                        <input
                            id="ops-secure-link-ttl"
                            type="number"
                            min="1"
                            max="120"
                            wire:model.defer="ttlMinutes"
                            placeholder="ttl_minutes"
                            class="ops-input"
                        />
                    </div>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="generate">Generate Secure Link</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Generation result"
            description="The latest secure-link outcome and the generated URL when available."
        >
            @if ($statusMessage !== '' || $generatedLink !== '')
                <x-filament-ops::ops-result-card
                    title="Latest status"
                    :meta="$statusMessage !== '' ? $statusMessage : 'Secure link generated.'"
                >
                    @if ($generatedLink !== '')
                        <a href="{{ $generatedLink }}" target="_blank" class="text-primary-600 underline break-all">{{ $generatedLink }}</a>
                    @endif
                </x-filament-ops::ops-result-card>
            @else
                <x-filament-ops::ops-empty-state
                    eyebrow="Secure link"
                    icon="heroicon-o-key"
                    title="No secure link generated yet"
                    description="Provide an order number and TTL to mint a short-lived claim URL."
                />
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
