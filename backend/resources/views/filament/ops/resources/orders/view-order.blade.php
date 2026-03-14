<x-filament-panels::page>
    @php
        $record = $this->getRecord();
    @endphp

    <div class="ops-shell-page">
        <x-filament::section>
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split">
                <div class="ops-workbench-toolbar__main">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">Support diagnostic</span>
                        <p class="ops-shell-inline-intro__meta">
                            Rooted on <code>{{ (string) ($record->order_no ?? $record->getKey()) }}</code>. This page keeps order, payment, unlock, report, PDF, and share breadcrumbs in one read-only surface.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['order']['state'] ?? 'gray')"
                            :label="'order: '.(string) ($headline['order']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['payment']['state'] ?? 'gray')"
                            :label="'payment: '.(string) ($headline['payment']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['unlock']['state'] ?? 'gray')"
                            :label="'unlock: '.(string) ($headline['unlock']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['snapshot']['state'] ?? 'gray')"
                            :label="'snapshot: '.(string) ($headline['snapshot']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['pdf']['state'] ?? 'gray')"
                            :label="'pdf: '.(string) ($headline['pdf']['label'] ?? '-')"
                        />
                    </div>
                </div>

                <div class="ops-workbench-toolbar__actions">
                    @foreach ($links as $link)
                        <x-filament::button
                            color="{{ ($link['kind'] ?? 'frontend') === 'ops' ? 'gray' : 'primary' }}"
                            size="sm"
                            tag="a"
                            href="{{ (string) ($link['url'] ?? '#') }}"
                            target="{{ ($link['kind'] ?? 'frontend') === 'ops' ? '_self' : '_blank' }}"
                        >
                            {{ (string) ($link['label'] ?? 'Open') }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Order Summary</h3>
                    <p class="ops-results-header__meta">Order root, payment posture, SKU, benefit key, org scope, and contact presence.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $orderSummary['fields'] ?? [],
                    'notes' => $orderSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Payment Events</h3>
                    <p class="ops-results-header__meta">Latest-first payment timeline with compact status, signature, handling, and error summaries only.</p>
                </div>
            </div>

            <div class="ops-card-list mt-4">
                @forelse ($paymentEvents as $event)
                    <div class="ops-result-card">
                        <div class="ops-result-card__header">
                            <div>
                                <p class="ops-result-card__title">{{ (string) ($event['provider_event_id'] ?? '-') }}</p>
                                <p class="ops-result-card__meta">
                                    processed={{ (string) ($event['processed_at'] ?? '-') }}
                                    | handled={{ (string) ($event['handled_at'] ?? '-') }}
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-filament.ops.shared.status-pill
                                :state="(string) ($event['status']['state'] ?? 'gray')"
                                :label="'status: '.(string) ($event['status']['label'] ?? '-')"
                            />
                            <x-filament.ops.shared.status-pill
                                :state="(string) ($event['handle_status']['state'] ?? 'gray')"
                                :label="'handle: '.(string) ($event['handle_status']['label'] ?? '-')"
                            />
                            <x-filament.ops.shared.status-pill
                                :state="(string) ($event['signature']['state'] ?? 'gray')"
                                :label="'signature: '.(string) ($event['signature']['label'] ?? '-')"
                            />
                        </div>

                        <div class="mt-4 space-y-2">
                            <p class="ops-control-hint">reason={{ (string) ($event['reason'] ?? '-') }}</p>
                            <p class="ops-control-hint">error={{ (string) ($event['error'] ?? '-') }}</p>
                        </div>
                    </div>
                @empty
                    <x-filament.ops.shared.empty-state
                        eyebrow="Payment events"
                        icon="heroicon-o-credit-card"
                        title="No payment events found"
                        description="Webhook payloads and headers stay hidden by default even when payment diagnostics exist."
                    />
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Benefit / Unlock</h3>
                    <p class="ops-results-header__meta">Grant-backed unlock fact, benefit scope, expiry, and audit breadcrumbs.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $benefitSummary['fields'] ?? [],
                    'notes' => $benefitSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Report / PDF Delivery</h3>
                    <p class="ops-results-header__meta">Snapshot status, delivery clues, claim eligibility, resend posture, and lightweight report job summary.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $reportSummary['fields'] ?? [],
                    'notes' => $reportSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Attempt Linkage</h3>
                    <p class="ops-results-header__meta">Attempt, result, locale, region, and share attribution breadcrumbs for drill-through diagnostics.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $attemptSummary['fields'] ?? [],
                    'notes' => $attemptSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Exception Diagnostics</h3>
                    <p class="ops-results-header__meta">Explicit v1 exception rules for support and ops, without expanding into a full BI or remediation console.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $exceptionSummary['fields'] ?? [],
                    'notes' => $exceptionSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
