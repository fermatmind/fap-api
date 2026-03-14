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
                            Rooted on <code>{{ (string) $record->getKey() }}</code>. This page keeps submit, result, report, payment, and share state in one read-only view.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['submitted']['state'] ?? 'gray')"
                            :label="'submitted: '.(string) ($headline['submitted']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['result']['state'] ?? 'gray')"
                            :label="'result: '.(string) ($headline['result']['label'] ?? '-')"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['report']['state'] ?? 'gray')"
                            :label="'report: '.(string) ($headline['report']['label'] ?? '-')"
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
                    <h3 class="ops-results-header__title">Attempt Summary</h3>
                    <p class="ops-results-header__meta">Attempt root fields, identity, and version dimensions.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $attemptSummary['fields'] ?? [],
                    'notes' => [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Answers Summary</h3>
                    <p class="ops-results-header__meta">Presence, hash, row count, and storage mode only. Raw answers stay hidden.</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $answersSummary['fields'] ?? [],
                    'notes' => $answersSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">Result</h3>
                    <p class="ops-results-header__meta">Result availability, computed timing, and version breadcrumbs.</p>
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
                    <h3 class="ops-results-header__title">Report Snapshot</h3>
                    <p class="ops-results-header__meta">Snapshot status, gate clues, and lightweight report job breadcrumbs.</p>
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
                    <h3 class="ops-results-header__title">Commerce / Unlock Linkage</h3>
                    <p class="ops-results-header__meta">Order, payment, benefit, entitlement, delivery, and claim clues.</p>
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
                    <h3 class="ops-results-header__title">Event Timeline</h3>
                    <p class="ops-results-header__meta">Latest attempt and share events with compact meta summaries only.</p>
                </div>
            </div>

            <div class="ops-card-list mt-4">
                @forelse ($eventTimeline as $event)
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
                        eyebrow="Event timeline"
                        icon="heroicon-o-clock"
                        title="No event timeline yet"
                        description="Attempt-linked events will appear here once the analytics trail exists."
                    />
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
