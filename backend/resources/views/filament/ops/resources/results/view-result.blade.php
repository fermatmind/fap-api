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
                            Rooted on <code>{{ (string) $record->getKey() }}</code>. This page keeps result, versions, report, attempt, unlock, and share linkage in one read-only view.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['result']['state'] ?? 'gray')"
                            :label="'result: '.(string) ($headline['result']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['diagnostic']['state'] ?? 'gray')"
                            :label="'diagnostic: '.(string) ($headline['diagnostic']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['snapshot']['state'] ?? 'gray')"
                            :label="'snapshot: '.(string) ($headline['snapshot']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['unlock']['state'] ?? 'gray')"
                            :label="'unlock: '.(string) ($headline['unlock']['label'] ?? '-')"
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
                    <h3 class="ops-results-header__title">Result Summary</h3>
                    <p class="ops-results-header__meta">Canonical result identity, validity, computed timing, and orphan visibility.</p>
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
                    <h3 class="ops-results-header__title">Score / Axis Summary</h3>
                    <p class="ops-results-header__meta">Compact summaries only. Raw scores_pct, axis_states, and result payloads stay hidden.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $scoreAxisSummary['fields'] ?? [],
                    'notes' => $scoreAxisSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Version / Diagnostics</h3>
                    <p class="ops-results-header__meta">Version breadcrumbs and first-pass diagnostic status without turning v1 into aggregate analytics.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $versionSummary['fields'] ?? [],
                    'notes' => $versionSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Report Linkage</h3>
                    <p class="ops-results-header__meta">Snapshot status, variant/access clues, and lightweight report job breadcrumbs only.</p>
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
                    <p class="ops-results-header__meta">Attempt presence, submit status, locale, region, and identity breadcrumbs with orphan-safe degradation.</p>
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
                    <h3 class="ops-results-header__title">Commerce / Unlock Linkage</h3>
                    <p class="ops-results-header__meta">Order, payment, benefit grant, unlock fact, and delivery/PDF clues rooted on the result attempt.</p>
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
                    <h3 class="ops-results-header__title">Share / Engagement</h3>
                    <p class="ops-results-header__meta">Share breadcrumbs and recent event summaries only. Raw event payloads stay hidden.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $shareSummary['fields'] ?? [],
                    'notes' => $shareSummary['notes'] ?? [],
                ])
            </div>

            <div class="ops-card-list mt-4">
                @forelse (($shareSummary['events'] ?? []) as $event)
                    <div class="ops-result-card">
                        <div class="ops-result-card__header">
                            <div>
                                <p class="ops-result-card__title">{{ (string) ($event['title'] ?? '-') }}</p>
                                <p class="ops-result-card__meta">
                                    {{ (string) ($event['occurred_at'] ?? '-') }}
                                    | channel={{ (string) ($event['channel'] ?? '-') }}
                                    | share_id={{ (string) ($event['share_id'] ?? '-') }}
                                </p>
                            </div>
                        </div>

                        @if (($event['meta'] ?? []) !== [])
                            <div class="flex flex-wrap gap-2">
                                @foreach (($event['meta'] ?? []) as $meta)
                                    <x-filament.ops.shared.status-pill state="gray" :label="(string) $meta" />
                                @endforeach
                            </div>
                        @else
                            <p class="ops-control-hint">No compact meta summary for this event.</p>
                        @endif
                    </div>
                @empty
                    <x-filament.ops.shared.empty-state
                        eyebrow="Share engagement"
                        icon="heroicon-o-share"
                        title="No share engagement events yet"
                        description="Share-linked events will appear here once the analytics trail exists."
                    />
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
