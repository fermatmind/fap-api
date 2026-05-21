@php
    $recentIssues = $this->recentIssueRows();
    $recentQueueRows = $this->recentQueueRows();
    $eventTypeSummary = $this->eventTypeSummary();
@endphp

<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SEO Intelligence"
            title="SEO Dash access"
            description="Native read-only SEO Engine observability dashboard. CMS/backend URL Truth remains the authority; this page does not embed, proxy, or call Metabase."
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">SEO Intelligence MVP - URL Truth &amp; Issue Queue</span>
                    <p class="ops-control-hint">Verified cards are rendered from the seo_intel read model. No write controls, scheduler controls, or search submission controls are available here.</p>
                </div>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        @unless ($this->dashboardAvailable())
            <x-filament-ops::ops-section
                title="seo_intel read status"
                description="The dashboard shell is available, but the seo_intel read model could not be loaded in this environment."
            >
                <x-filament-ops::ops-result-card
                    title="Read model unavailable"
                    meta="Counts are shown as zero until the seo_intel connection and dashboard tables are readable."
                />
            </x-filament-ops::ops-section>
        @endunless

        <x-filament-ops::ops-section
            title="Overview heartbeat"
            description="Live read-only counts from approved seo_intel tables. These replace the old static MVP closeout counts."
        >
            <x-filament-ops::ops-field-grid :fields="$this->overviewCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Safety heartbeat"
            description="These counters should remain zero. A non-zero value means search distribution should stop for review."
        >
            <x-filament-ops::ops-field-grid :fields="$this->safetyCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="URL Truth distribution"
            description="Aggregate view of URL Truth by allowed authority, locale, page type, and indexability fields."
        >
            <div class="ops-card-list">
                @foreach ($this->urlTruthDistributionCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="count($card['rows']).' buckets'"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">No aggregate rows available.</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Issue Queue overview"
            description="Read-only issue aggregates and recent safe rows. Raw evidence, payloads, JSON, and PII stay hidden."
        >
            <div class="ops-card-list">
                @foreach ($this->issueQueueAggregateCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="count($card['rows']).' buckets'"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">No aggregate rows available.</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>

            <x-filament-ops::ops-table
                class="mt-4"
                :has-rows="$recentIssues !== []"
                empty-title="No recent SEO issues"
                empty-description="The issue queue has no safe rows to display."
            >
                <x-slot name="head">
                    <tr>
                        <th>canonical path</th>
                        <th>page_entity_type</th>
                        <th>issue_type</th>
                        <th>severity</th>
                        <th>status</th>
                        <th>detected_at</th>
                        <th>updated_at</th>
                    </tr>
                </x-slot>

                @foreach ($recentIssues as $issue)
                    <tr>
                        <td>{{ $issue['canonical_path'] ?? '-' }}</td>
                        <td>{{ $issue['page_entity_type'] ?? '-' }}</td>
                        <td>{{ $issue['issue_type'] ?? '-' }}</td>
                        <td>{{ $issue['severity'] ?? '-' }}</td>
                        <td>{{ $issue['status'] ?? '-' }}</td>
                        <td>{{ $issue['detected_at'] ?? '-' }}</td>
                        <td>{{ $issue['updated_at'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Search Channel Queue overview"
            description="Read-only channel state. This page has no approve, retry, submit, scheduler, collector, or live API controls."
        >
            <div class="ops-card-list">
                @foreach ($this->searchChannelQueueAggregateCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="count($card['rows']).' buckets'"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">No aggregate rows available.</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>

            <x-filament-ops::ops-table
                class="mt-4"
                :has-rows="$recentQueueRows !== []"
                empty-title="No recent queue rows"
                empty-description="The Search Channel Queue has no safe rows to display."
            >
                <x-slot name="head">
                    <tr>
                        <th>canonical path</th>
                        <th>channel</th>
                        <th>approval_state</th>
                        <th>execution_state</th>
                        <th>created_at</th>
                        <th>updated_at</th>
                    </tr>
                </x-slot>

                @foreach ($recentQueueRows as $row)
                    <tr>
                        <td>{{ $row['canonical_path'] ?? '-' }}</td>
                        <td>{{ $row['channel'] ?? '-' }}</td>
                        <td>{{ $row['approval_state'] ?? '-' }}</td>
                        <td>{{ $row['execution_state'] ?? '-' }}</td>
                        <td>{{ $row['created_at'] ?? '-' }}</td>
                        <td>{{ $row['updated_at'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>

            <div class="ops-card-list mt-4">
                <x-filament-ops::ops-result-card
                    title="event_type summary"
                    :meta="count($eventTypeSummary).' buckets'"
                >
                    @if ($eventTypeSummary !== [])
                        <ul class="ops-list">
                            @foreach ($eventTypeSummary as $event)
                                <li>{{ $event['event_type'] }}: {{ $event['count'] }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="ops-control-hint">No event aggregate rows available.</p>
                    @endif
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Access boundary"
            description="Metabase remains private. This route is an authenticated Ops read surface, not a public dashboard endpoint."
        >
            <x-filament-ops::ops-field-grid :fields="$this->boundaryCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Private access runbook"
            description="Use approved private access only. Do not create public links, anonymous links, public embeds, reverse proxies, or iframe exposure."
        >
            <div class="ops-card-list">
                @foreach ($this->accessSteps() as $step)
                    <x-filament-ops::ops-result-card
                        :title="$step['title']"
                        :meta="$step['body']"
                    />
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Hard stops"
            description="Stop if any forbidden source, write path, live search operation, or public exposure appears."
        >
            <div class="ops-card-list">
                <x-filament-ops::ops-result-card
                    title="Forbidden exposure"
                    meta="No public Metabase, no iframe, no reverse proxy, no public URL, no 0.0.0.0 binding, no public port, no security group change, and no DNS/CDN/OpenResty/Nginx change."
                />
                <x-filament-ops::ops-result-card
                    title="Forbidden sources"
                    meta="No business DB, Tencent RDS, Node2 local DB, CMS write tables, raw orders, raw payments, raw events, raw email, raw reports, raw crawler logs, raw payloads, or raw JSON blobs."
                />
                <x-filament-ops::ops-result-card
                    title="Forbidden operator access"
                    meta="No unrestricted SQL, no datasource management, no default exports, no public sharing, no anonymous links, no public embeds, no approve/retry/submit buttons, and no scheduler or collector controls."
                />
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
