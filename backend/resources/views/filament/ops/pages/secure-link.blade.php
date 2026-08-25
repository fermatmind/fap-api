<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section>
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--2">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-secure-link-order-no">订单号</label>
                        <input
                            id="ops-secure-link-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="order_no"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-secure-link-ttl">有效期（分钟）</label>
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
                    <x-filament::button wire:click="generate">生成安全链接</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="生成结果"
        >
            @if ($statusMessage !== '' || $generatedLink !== '')
                <x-filament-ops::ops-result-card
                    title="最新状态"
                    :meta="$statusMessage !== '' ? $statusMessage : '安全链接已生成。'"
                >
                    @if ($generatedLink !== '')
                        <a href="{{ $generatedLink }}" target="_blank" class="text-primary-600 underline break-all">{{ $generatedLink }}</a>
                    @endif
                </x-filament-ops::ops-result-card>
            @else
                <x-filament-ops::ops-empty-state
                    eyebrow=""
                    icon="heroicon-o-key"
                    title="尚未生成安全链接"
                    description=""
                />
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
