@php
    $copy = 'ops.custom_pages.seo_intelligence';
    $recentIssues = $this->recentIssueRows();
    $recentQueueRows = $this->recentQueueRows();
    $recentCrawlerRows = $this->recentCrawlerRows();
    $eventTypeSummary = $this->eventTypeSummary();
@endphp

<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__($copy.'.eyebrow')"
            :title="__($copy.'.dash_access_title')"
            :description="__($copy.'.dash_access_desc')"
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __($copy.'.mvp_label') }}</span>
                    <p class="ops-control-hint">{{ __($copy.'.mvp_hint') }}</p>
                </div>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        @unless ($this->dashboardAvailable())
            <x-filament-ops::ops-section
                :title="__($copy.'.read_status_title')"
                :description="__($copy.'.read_status_desc')"
            >
                <x-filament-ops::ops-result-card
                    :title="__($copy.'.read_unavailable_title')"
                    :meta="__($copy.'.read_unavailable_meta')"
                />
            </x-filament-ops::ops-section>
        @endunless

        <x-filament-ops::ops-section
            :title="__($copy.'.overview_title')"
            :description="__($copy.'.overview_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$this->overviewCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.safety_title')"
            :description="__($copy.'.safety_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$this->safetyCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.url_truth_distribution_title')"
            :description="__($copy.'.url_truth_distribution_desc')"
        >
            <div class="ops-card-list">
                @foreach ($this->urlTruthDistributionCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="__($copy.'.bucket_count', ['count' => count($card['rows'])])"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">{{ __($copy.'.no_aggregate_rows') }}</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.issue_queue_overview_title')"
            :description="__($copy.'.issue_queue_overview_desc')"
        >
            <div class="ops-card-list">
                @foreach ($this->issueQueueAggregateCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="__($copy.'.bucket_count', ['count' => count($card['rows'])])"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">{{ __($copy.'.no_aggregate_rows') }}</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>

            <x-filament-ops::ops-table
                class="mt-4"
                :has-rows="$recentIssues !== []"
                :empty-title="__($copy.'.empty_recent_issues_title')"
                :empty-description="__($copy.'.empty_recent_issues_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __($copy.'.table.canonical_path') }}</th>
                        <th>{{ __($copy.'.table.locale') }}</th>
                        <th>{{ __($copy.'.table.page_entity_type') }}</th>
                        <th>{{ __($copy.'.table.issue_type') }}</th>
                        <th>{{ __($copy.'.table.severity') }}</th>
                        <th>{{ __($copy.'.table.source_system') }}</th>
                        <th>{{ __($copy.'.table.source_engine') }}</th>
                        <th>{{ __($copy.'.table.status') }}</th>
                        <th>{{ __($copy.'.table.lifecycle_state') }}</th>
                        <th>{{ __($copy.'.table.detected_at') }}</th>
                        <th>{{ __($copy.'.table.updated_at') }}</th>
                    </tr>
                </x-slot>

                @foreach ($recentIssues as $issue)
                    <tr>
                        <td>{{ $issue['canonical_path'] ?? '-' }}</td>
                        <td>{{ $issue['locale'] ?? '-' }}</td>
                        <td>{{ $issue['page_entity_type'] ?? '-' }}</td>
                        <td>{{ $issue['issue_type'] ?? '-' }}</td>
                        <td>{{ $issue['severity'] ?? '-' }}</td>
                        <td>{{ $issue['source_system'] ?? '-' }}</td>
                        <td>{{ $issue['source_engine'] ?? '-' }}</td>
                        <td>{{ $issue['status'] ?? '-' }}</td>
                        <td>{{ $issue['lifecycle_state'] ?? '-' }}</td>
                        <td>{{ $issue['detected_at'] ?? '-' }}</td>
                        <td>{{ $issue['updated_at'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.search_channel_queue_title')"
            :description="__($copy.'.search_channel_queue_desc')"
        >
            <div class="ops-card-list">
                @foreach ($this->searchChannelQueueAggregateCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="__($copy.'.bucket_count', ['count' => count($card['rows'])])"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">{{ __($copy.'.no_aggregate_rows') }}</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>

            <x-filament-ops::ops-table
                class="mt-4"
                :has-rows="$recentQueueRows !== []"
                :empty-title="__($copy.'.empty_recent_queue_title')"
                :empty-description="__($copy.'.empty_recent_queue_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __($copy.'.table.canonical_path') }}</th>
                        <th>{{ __($copy.'.table.locale') }}</th>
                        <th>{{ __($copy.'.table.page_entity_type') }}</th>
                        <th>{{ __($copy.'.table.source_authority') }}</th>
                        <th>{{ __($copy.'.table.channel') }}</th>
                        <th>{{ __($copy.'.table.eligibility_state') }}</th>
                        <th>{{ __($copy.'.table.approval_state') }}</th>
                        <th>{{ __($copy.'.table.execution_state') }}</th>
                        <th>{{ __($copy.'.table.indexability_state') }}</th>
                        <th>{{ __($copy.'.table.claim_boundary_state') }}</th>
                        <th>{{ __($copy.'.table.private_flow') }}</th>
                        <th>{{ __($copy.'.table.created_at') }}</th>
                        <th>{{ __($copy.'.table.updated_at') }}</th>
                    </tr>
                </x-slot>

                @foreach ($recentQueueRows as $row)
                    <tr>
                        <td>{{ $row['canonical_path'] ?? '-' }}</td>
                        <td>{{ $row['locale'] ?? '-' }}</td>
                        <td>{{ $row['page_entity_type'] ?? '-' }}</td>
                        <td>{{ $row['source_authority'] ?? '-' }}</td>
                        <td>{{ $row['channel'] ?? '-' }}</td>
                        <td>{{ $row['eligibility_state'] ?? '-' }}</td>
                        <td>{{ $row['approval_state'] ?? '-' }}</td>
                        <td>{{ $row['execution_state'] ?? '-' }}</td>
                        <td>{{ $row['indexability_state'] ?? '-' }}</td>
                        <td>{{ $row['claim_boundary_state'] ?? '-' }}</td>
                        <td>{{ ($row['private_flow'] ?? false) ? __($copy.'.boolean.true') : __($copy.'.boolean.false') }}</td>
                        <td>{{ $row['created_at'] ?? '-' }}</td>
                        <td>{{ $row['updated_at'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>

            <div class="ops-card-list mt-4">
                <x-filament-ops::ops-result-card
                    :title="__($copy.'.event_type_summary_title')"
                    :meta="__($copy.'.bucket_count', ['count' => count($eventTypeSummary)])"
                >
                    @if ($eventTypeSummary !== [])
                        <ul class="ops-list">
                            @foreach ($eventTypeSummary as $event)
                                <li>{{ $event['event_type'] }}: {{ $event['count'] }}</li>
                            @endforeach
                        </ul>
                    @else
                        <p class="ops-control-hint">{{ __($copy.'.no_event_aggregate_rows') }}</p>
                    @endif
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.crawler_overview_title')"
            :description="__($copy.'.crawler_overview_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$this->crawlerSafetyCards()" />

            <div class="ops-card-list mt-4">
                @foreach ($this->crawlerObservationAggregateCards() as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="__($copy.'.bucket_count', ['count' => count($card['rows'])])"
                    >
                        @if ($card['rows'] !== [])
                            <ul class="ops-list">
                                @foreach ($card['rows'] as $row)
                                    <li>{{ $row['label'] }}: {{ $row['count'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="ops-control-hint">{{ __($copy.'.no_aggregate_rows') }}</p>
                        @endif
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>

            <x-filament-ops::ops-table
                class="mt-4"
                :has-rows="$recentCrawlerRows !== []"
                :empty-title="__($copy.'.empty_crawler_title')"
                :empty-description="__($copy.'.empty_crawler_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __($copy.'.table.log_date') }}</th>
                        <th>{{ __($copy.'.table.host') }}</th>
                        <th>{{ __($copy.'.table.surface_family') }}</th>
                        <th>{{ __($copy.'.table.bot_family') }}</th>
                        <th>{{ __($copy.'.table.bot_variant') }}</th>
                        <th>{{ __($copy.'.table.route_family') }}</th>
                        <th>{{ __($copy.'.table.page_entity_type') }}</th>
                        <th>{{ __($copy.'.table.canonical_path') }}</th>
                        <th>{{ __($copy.'.table.http_status') }}</th>
                        <th>{{ __($copy.'.table.method_bucket') }}</th>
                        <th>{{ __($copy.'.table.query_present') }}</th>
                        <th>{{ __($copy.'.table.query_risk_state') }}</th>
                        <th>{{ __($copy.'.table.private_path_blocked') }}</th>
                        <th>{{ __($copy.'.table.hit_count') }}</th>
                        <th>{{ __($copy.'.table.last_seen_at') }}</th>
                    </tr>
                </x-slot>

                @foreach ($recentCrawlerRows as $row)
                    <tr>
                        <td>{{ $row['log_date'] ?? '-' }}</td>
                        <td>{{ $row['host'] ?? '-' }}</td>
                        <td>{{ $row['surface_family'] ?? '-' }}</td>
                        <td>{{ $row['bot_family'] ?? '-' }}</td>
                        <td>{{ $row['bot_variant'] ?? '-' }}</td>
                        <td>{{ $row['route_family'] ?? '-' }}</td>
                        <td>{{ $row['page_entity_type'] ?? '-' }}</td>
                        <td>{{ $row['canonical_path'] ?? '-' }}</td>
                        <td>{{ $row['http_status'] ?? '-' }}</td>
                        <td>{{ $row['method_bucket'] ?? '-' }}</td>
                        <td>{{ ($row['query_present'] ?? false) ? __($copy.'.boolean.true') : __($copy.'.boolean.false') }}</td>
                        <td>{{ $row['query_risk_state'] ?? '-' }}</td>
                        <td>{{ ($row['private_path_blocked'] ?? false) ? __($copy.'.boolean.true') : __($copy.'.boolean.false') }}</td>
                        <td>{{ $row['hit_count'] ?? '0' }}</td>
                        <td>{{ $row['last_seen_at'] ?? '-' }}</td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.access_boundary_title')"
            :description="__($copy.'.access_boundary_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$this->boundaryCards()" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__($copy.'.private_access_runbook_title')"
            :description="__($copy.'.private_access_runbook_desc')"
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
            :title="__($copy.'.hard_stops_title')"
            :description="__($copy.'.hard_stops_desc')"
        >
            <div class="ops-card-list">
                <x-filament-ops::ops-result-card
                    :title="__($copy.'.hard_stops.forbidden_exposure.title')"
                    :meta="__($copy.'.hard_stops.forbidden_exposure.meta')"
                />
                <x-filament-ops::ops-result-card
                    :title="__($copy.'.hard_stops.forbidden_sources.title')"
                    :meta="__($copy.'.hard_stops.forbidden_sources.meta')"
                />
                <x-filament-ops::ops-result-card
                    :title="__($copy.'.hard_stops.forbidden_operator_access.title')"
                    :meta="__($copy.'.hard_stops.forbidden_operator_access.meta')"
                />
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
