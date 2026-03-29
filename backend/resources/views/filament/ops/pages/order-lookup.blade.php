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
            eyebrow="Support search"
            title="Order lookup"
            description="Search by order number, email, or target attempt id, then inspect the order root, payment event timeline, grants, and linked attempt in one shell."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-page-grid ops-page-grid--3">
                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-order-no">Order number</label>
                        <input
                            id="ops-order-lookup-order-no"
                            type="text"
                            wire:model.defer="orderNo"
                            placeholder="order_no"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-email">Email</label>
                        <input
                            id="ops-order-lookup-email"
                            type="text"
                            wire:model.defer="email"
                            placeholder="email"
                            class="ops-input"
                        />
                    </div>

                    <div class="ops-control-stack">
                        <label class="ops-control-label" for="ops-order-lookup-attempt-id">Target attempt</label>
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
                    <x-filament::button wire:click="search">Search</x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        @if (! $hasQuery)
            <x-filament-ops::ops-section>
                <x-filament-ops::ops-empty-state
                    eyebrow="Order lookup"
                    icon="heroicon-o-magnifying-glass"
                    title="Search for an order"
                    description="Use at least one identifier to inspect the current order, payment events, grants, and linked attempt."
                />
            </x-filament-ops::ops-section>
        @elseif (! $order)
            <x-filament-ops::ops-section>
                <x-filament-ops::ops-empty-state
                    eyebrow="Order lookup"
                    icon="heroicon-o-face-frown"
                    title="No matching order found"
                    description="No order matched the current search input inside the active organization context."
                />
            </x-filament-ops::ops-section>
        @else
            <x-filament-ops::ops-section
                title="Order"
                description="The order root record and current payment, unlock, and lifecycle posture."
            >
                <x-filament-ops::ops-field-grid
                    :fields="$toFields($order)"
                    empty-description="This order has no visible fields."
                    empty-eyebrow="Order"
                    empty-title="No order details"
                />
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                title="Payment events"
                description="Latest-first payment event records currently linked to the resolved order number."
            >
                <div class="ops-card-list">
                    @forelse ($paymentEvents as $index => $event)
                        <x-filament-ops::ops-result-card
                            :title="(string) ($event['provider_event_id'] ?? ('payment event #'.($index + 1)))"
                            :meta="'status='.(string) ($event['status'] ?? '-').' | handle='.(string) ($event['handle_status'] ?? '-')"
                        >
                            <x-slot name="badges">
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($event['status'] ?? 'gray')"
                                    :label="'status: '.(string) ($event['status'] ?? '-')"
                                />
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($event['handle_status'] ?? 'gray')"
                                    :label="'handle: '.(string) ($event['handle_status'] ?? '-')"
                                />
                            </x-slot>

                            <x-filament-ops::ops-field-grid
                                cardless
                                :fields="$toFields((array) $event)"
                                empty-description="This event has no visible fields."
                                empty-eyebrow="Payment events"
                                empty-title="No payment event details"
                            />
                        </x-filament-ops::ops-result-card>
                    @empty
                        <x-filament-ops::ops-empty-state
                            eyebrow="Payment events"
                            icon="heroicon-o-credit-card"
                            title="No payment events found"
                            description="The resolved order does not currently expose any linked payment events."
                        />
                    @endforelse
                </div>
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                title="Benefit grants"
                description="Benefit and unlock records currently linked to the order number or target attempt."
            >
                <div class="ops-card-list">
                    @forelse ($benefitGrants as $index => $grant)
                        <x-filament-ops::ops-result-card
                            :title="(string) ($grant['id'] ?? ('grant #'.($index + 1)))"
                            :meta="'benefit='.(string) ($grant['benefit_code'] ?? '-').' | status='.(string) ($grant['status'] ?? '-')"
                        >
                            <x-slot name="badges">
                                <x-filament.ops.shared.status-pill
                                    :state="(string) ($grant['status'] ?? 'gray')"
                                    :label="'status: '.(string) ($grant['status'] ?? '-')"
                                />
                            </x-slot>

                            <x-filament-ops::ops-field-grid
                                cardless
                                :fields="$toFields((array) $grant)"
                                empty-description="This grant has no visible fields."
                                empty-eyebrow="Benefit grants"
                                empty-title="No grant details"
                            />
                        </x-filament-ops::ops-result-card>
                    @empty
                        <x-filament-ops::ops-empty-state
                            eyebrow="Benefit grants"
                            icon="heroicon-o-key"
                            title="No benefit grants found"
                            description="The resolved order does not currently expose linked benefit grant records."
                        />
                    @endforelse
                </div>
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section
                title="Attempt"
                description="The linked target attempt record when the order is attached to one."
            >
                @if ($attempt)
                    <x-filament-ops::ops-field-grid
                        :fields="$toFields($attempt)"
                        empty-description="This attempt has no visible fields."
                        empty-eyebrow="Attempt"
                        empty-title="No attempt details"
                    />
                @else
                    <x-filament-ops::ops-empty-state
                        eyebrow="Attempt"
                        icon="heroicon-o-clipboard-document-list"
                        title="No linked attempt found"
                        description="This order does not currently resolve to a linked target attempt inside the active organization context."
                    />
                @endif
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
