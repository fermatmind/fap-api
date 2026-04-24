<x-filament-panels::page>
    @php
        $hasQuery = filled(trim($orderNo)) || filled(trim($email)) || filled(trim($attemptId));
        $normalizeValue = static function (mixed $value): string {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_array($value)) {
                return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }

            if ($value === null || $value === '') {
                return '-';
            }

            return (string) $value;
        };
        $toFields = static function (array $payload) use ($normalizeValue): array {
            $fields = [];

            foreach ($payload as $key => $value) {
                $fields[] = [
                    'kind' => is_array($value) ? 'code' : 'text',
                    'label' => (string) $key,
                    'value' => $normalizeValue($value),
                ];
            }

            return $fields;
        };
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.order_lookup.eyebrow')"
            :title="__('ops.custom_pages.order_lookup.title')"
            :description="__('ops.custom_pages.order_lookup.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--3">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-order-no">{{ __('ops.custom_pages.order_lookup.order_number') }}</label>
                        <input
                            id="ops-order-lookup-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="order_no"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-email">{{ __('ops.custom_pages.order_lookup.email') }}</label>
                        <input
                            id="ops-order-lookup-email"
                            type="text"
                            wire:model.defer="email"
                            placeholder="email"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-attempt-id">{{ __('ops.custom_pages.order_lookup.target_attempt') }}</label>
                        <input
                            id="ops-order-lookup-attempt-id"
                            type="text"
                            wire:model.defer="attemptId"
                            placeholder="attempt_id"
                            class="ops-input"
                        />
                    </div>
                </div>

                <x-slot name="actions">
                    <x-filament::button wire:click="search">{{ __('ops.custom_pages.common.actions.search') }}</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        @if (! $hasQuery)
            <x-filament-ops::ops-section>
                <x-filament-ops::ops-empty-state
                    :eyebrow="__('ops.custom_pages.order_lookup.title')"
                    icon="heroicon-o-magnifying-glass"
                    :title="__('ops.custom_pages.order_lookup.search_title')"
                    :description="__('ops.custom_pages.order_lookup.search_desc')"
                />
            </x-filament-ops::ops-section>
        @elseif (! $order)
            <x-filament-ops::ops-section>
                <x-filament-ops::ops-empty-state
                    :eyebrow="__('ops.custom_pages.order_lookup.title')"
                    icon="heroicon-o-face-frown"
                    :title="__('ops.custom_pages.order_lookup.not_found_title')"
                    :description="__('ops.custom_pages.order_lookup.not_found_desc')"
                />
            </x-filament-ops::ops-section>
        @else
            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.order_lookup.order_title')"
                :description="__('ops.custom_pages.order_lookup.order_desc')"
            >
                <x-filament-ops::ops-field-grid
                    :fields="$toFields($order)"
                    :empty-description="__('ops.custom_pages.order_lookup.no_order_fields')"
                    :empty-eyebrow="__('ops.custom_pages.order_lookup.order_title')"
                    :empty-title="__('ops.custom_pages.order_lookup.no_order_details')"
                />
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.order_lookup.payment_events_title')"
                :description="__('ops.custom_pages.order_lookup.payment_events_desc')"
            >
                <div class="ops-card-list">
                    @forelse ($paymentEvents as $index => $event)
                        <x-filament-ops::ops-result-card
                            :title="(string) ($event['provider_event_id'] ?? __('ops.custom_pages.order_lookup.payment_event_fallback', ['number' => $index + 1]))"
                            :meta="__('ops.custom_pages.order_lookup.payment_meta', ['status' => (string) ($event['status'] ?? '-'), 'handle' => (string) ($event['handle_status'] ?? '-')])"
                        >
                            <x-slot name="badges">
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($event['status'] ?? 'gray')"
                                    :label="__('ops.custom_pages.order_lookup.status_label', ['status' => (string) ($event['status'] ?? '-')])"
                                />
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($event['handle_status'] ?? 'gray')"
                                    :label="__('ops.custom_pages.order_lookup.handle_label', ['status' => (string) ($event['handle_status'] ?? '-')])"
                                />
                            </x-slot>

                            <x-filament-ops::ops-field-grid
                                cardless
                                :fields="$toFields((array) $event)"
                                :empty-description="__('ops.custom_pages.order_lookup.no_event_fields')"
                                :empty-eyebrow="__('ops.custom_pages.order_lookup.payment_events_title')"
                                :empty-title="__('ops.custom_pages.order_lookup.no_event_details')"
                            />
                        </x-filament-ops::ops-result-card>
                    @empty
                        <x-filament-ops::ops-empty-state
                            :eyebrow="__('ops.custom_pages.order_lookup.payment_events_title')"
                            icon="heroicon-o-credit-card"
                            :title="__('ops.custom_pages.order_lookup.no_events_title')"
                            :description="__('ops.custom_pages.order_lookup.no_events_desc')"
                        />
                    @endforelse
                </div>
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.order_lookup.benefit_grants_title')"
                :description="__('ops.custom_pages.order_lookup.benefit_grants_desc')"
            >
                <div class="ops-card-list">
                    @forelse ($benefitGrants as $index => $grant)
                        <x-filament-ops::ops-result-card
                            :title="(string) ($grant['id'] ?? __('ops.custom_pages.order_lookup.grant_fallback', ['number' => $index + 1]))"
                            :meta="__('ops.custom_pages.order_lookup.grant_meta', ['benefit' => (string) ($grant['benefit_code'] ?? '-'), 'status' => (string) ($grant['status'] ?? '-')])"
                        >
                            <x-slot name="badges">
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($grant['status'] ?? 'gray')"
                                    :label="__('ops.custom_pages.order_lookup.status_label', ['status' => (string) ($grant['status'] ?? '-')])"
                                />
                            </x-slot>

                            <x-filament-ops::ops-field-grid
                                cardless
                                :fields="$toFields((array) $grant)"
                                :empty-description="__('ops.custom_pages.order_lookup.no_grant_fields')"
                                :empty-eyebrow="__('ops.custom_pages.order_lookup.benefit_grants_title')"
                                :empty-title="__('ops.custom_pages.order_lookup.no_grant_details')"
                            />
                        </x-filament-ops::ops-result-card>
                    @empty
                        <x-filament-ops::ops-empty-state
                            :eyebrow="__('ops.custom_pages.order_lookup.benefit_grants_title')"
                            icon="heroicon-o-key"
                            :title="__('ops.custom_pages.order_lookup.no_grants_title')"
                            :description="__('ops.custom_pages.order_lookup.no_grants_desc')"
                        />
                    @endforelse
                </div>
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.order_lookup.attempt_title')"
                :description="__('ops.custom_pages.order_lookup.attempt_desc')"
            >
                @if ($attempt)
                    <x-filament-ops::ops-field-grid
                        :fields="$toFields($attempt)"
                        :empty-description="__('ops.custom_pages.order_lookup.no_attempt_fields')"
                        :empty-eyebrow="__('ops.custom_pages.order_lookup.attempt_title')"
                        :empty-title="__('ops.custom_pages.order_lookup.no_attempt_details')"
                    />
                @else
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.order_lookup.attempt_title')"
                        icon="heroicon-o-clipboard-document-list"
                        :title="__('ops.custom_pages.order_lookup.no_attempt_title')"
                        :description="__('ops.custom_pages.order_lookup.no_attempt_desc')"
                    />
                @endif
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
