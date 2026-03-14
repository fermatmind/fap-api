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
                            Rooted on <code>{{ (string) $record->getKey() }}</code>. This page keeps snapshot, PDF, delivery, attempt, result, unlock, share, and claim/resend clues in one read-only surface.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['snapshot']['state'] ?? 'gray')"
                            :label="'snapshot: '.(string) ($headline['snapshot']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['unlock']['state'] ?? 'gray')"
                            :label="'unlock: '.(string) ($headline['unlock']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['pdf']['state'] ?? 'gray')"
                            :label="'pdf: '.(string) ($headline['pdf']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['delivery']['state'] ?? 'gray')"
                            :label="'delivery: '.(string) ($headline['delivery']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['job']['state'] ?? 'gray')"
                            :label="'report_job: '.(string) ($headline['job']['label'] ?? '-')"
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
                    <h3 class="ops-results-header__title">Snapshot Summary</h3>
                    <p class="ops-results-header__meta">Delivery-rooted snapshot identity, status, version, access clues, and lightweight error summary.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $snapshotSummary['fields'] ?? [],
                    'notes' => $snapshotSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">PDF / Delivery Status</h3>
                    <p class="ops-results-header__meta">PDF readiness, report.pdf endpoint clue, contact presence, claim/resend eligibility, and delivery timing.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $pdfDeliverySummary['fields'] ?? [],
                    'notes' => $pdfDeliverySummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Report Job / Generation Status</h3>
                    <p class="ops-results-header__meta">Auxiliary generation breadcrumbs only. Snapshot rows remain the delivery truth object for this view.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $reportJobSummary['fields'] ?? [],
                    'notes' => $reportJobSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Attempt Linkage</h3>
                    <p class="ops-results-header__meta">Attempt root, submit state, user/anon/ticket breadcrumbs, and attempt drill-through context.</p>
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
                    <h3 class="ops-results-header__title">Result Linkage</h3>
                    <p class="ops-results-header__meta">Result presence, type, computed timing, and result drill-through without exposing raw result payload JSON.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $resultSummary['fields'] ?? [],
                    'notes' => $resultSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Commerce / Unlock Linkage</h3>
                    <p class="ops-results-header__meta">Order, payment, benefit grant, unlock truth, and order lookup breadcrumbs.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $commerceSummary['fields'] ?? [],
                    'notes' => $commerceSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Share / Access Linkage + Exception Diagnostics</h3>
                    <p class="ops-results-header__meta">Share/access breadcrumbs first, then explicit v1 exception rules for support and ops.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $shareAccessSummary['fields'] ?? [],
                    'notes' => $shareAccessSummary['notes'] ?? [],
                ])
            </div>

            <div class="mt-6">
                <h4 class="ops-results-header__title">Exception Diagnostics</h4>
                <p class="ops-results-header__meta mt-1">Focused exception flags only. Raw email, payment, and storage payloads remain hidden.</p>
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
