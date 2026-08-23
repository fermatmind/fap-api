<x-filament-panels::page>
    @php
        $typeFilterOptions = [
            'all' => __('ops.custom_pages.seo_operations.filters.all_visible'),
            'article' => __('ops.custom_pages.common.filters.articles'),
            'guide' => __('ops.custom_pages.common.filters.career_guides'),
            'job' => __('ops.custom_pages.common.filters.career_jobs'),
        ];

        $issueFilterOptions = [
            'all' => __('ops.custom_pages.seo_operations.filters.all_issues'),
            'metadata' => __('ops.custom_pages.seo_operations.filters.metadata'),
            'canonical' => __('ops.custom_pages.seo_operations.filters.canonical'),
            'robots' => __('ops.custom_pages.seo_operations.filters.robots'),
            'indexability' => __('ops.custom_pages.seo_operations.filters.indexability'),
            'social' => __('ops.custom_pages.seo_operations.filters.social'),
            'growth' => __('ops.custom_pages.seo_operations.filters.growth'),
        ];

        $bulkActionOptions = [
            'fill_metadata' => __('ops.custom_pages.seo_operations.filters.fill_metadata'),
            'sync_canonical' => __('ops.custom_pages.seo_operations.filters.sync_canonical'),
            'sync_robots' => __('ops.custom_pages.seo_operations.filters.sync_robots'),
            'mark_indexable' => __('ops.custom_pages.seo_operations.filters.mark_indexable'),
            'mark_noindex' => __('ops.custom_pages.seo_operations.filters.mark_noindex'),
        ];
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.seo_operations.eyebrow')"
            :title="__('ops.custom_pages.seo_operations.title')"
            :description="__('ops.custom_pages.seo_operations.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-toolbar-grid">
                    <fieldset class="ops-control-stack ops-segmented-field" aria-labelledby="ops-seo-type-filter-label">
                        <legend id="ops-seo-type-filter-label" class="ops-control-label">{{ __('ops.custom_pages.seo_operations.content_type') }}</legend>
                        <div id="ops-seo-type-filter" class="ops-segmented-control" role="group" aria-labelledby="ops-seo-type-filter-label">
                            @foreach ($typeFilterOptions as $value => $label)
                                <button
                                    type="button"
                                    wire:click="$set('typeFilter', '{{ $value }}')"
                                    @class([
                                        'ops-segmented-control__item',
                                        'ops-segmented-control__item--active' => $typeFilter === $value,
                                    ])
                                    aria-pressed="{{ $typeFilter === $value ? 'true' : 'false' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset class="ops-control-stack ops-segmented-field ops-segmented-field--wide" aria-labelledby="ops-seo-issue-filter-label">
                        <legend id="ops-seo-issue-filter-label" class="ops-control-label">{{ __('ops.custom_pages.seo_operations.issue_focus') }}</legend>
                        <div id="ops-seo-issue-filter" class="ops-segmented-control" role="group" aria-labelledby="ops-seo-issue-filter-label">
                            @foreach ($issueFilterOptions as $value => $label)
                                <button
                                    type="button"
                                    wire:click="$set('issueFilter', '{{ $value }}')"
                                    @class([
                                        'ops-segmented-control__item',
                                        'ops-segmented-control__item--active' => $issueFilter === $value,
                                    ])
                                    aria-pressed="{{ $issueFilter === $value ? 'true' : 'false' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset class="ops-control-stack ops-segmented-field ops-segmented-field--wide" aria-labelledby="ops-seo-bulk-action-label">
                        <legend id="ops-seo-bulk-action-label" class="ops-control-label">{{ __('ops.custom_pages.seo_operations.bulk_action') }}</legend>
                        <div id="ops-seo-bulk-action" class="ops-segmented-control" role="group" aria-labelledby="ops-seo-bulk-action-label">
                            @foreach ($bulkActionOptions as $value => $label)
                                <button
                                    type="button"
                                    wire:click="$set('bulkAction', '{{ $value }}')"
                                    @class([
                                        'ops-segmented-control__item',
                                        'ops-segmented-control__item--active' => $bulkAction === $value,
                                    ])
                                    aria-pressed="{{ $bulkAction === $value ? 'true' : 'false' }}"
                                >
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="ops-control-stack">
                        <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.contract_label') }}</span>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.contract_hint') }}</p>
                    </div>
                </div>

                <x-slot name="actions">
                    <x-filament::button
                        color="gray"
                        tag="button"
                        type="button"
                        wire:click="exportReport"
                    >
                        {{ __('ops.custom_pages.seo_operations.export_report') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_metrics') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.growth_attribution') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_search') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_ops') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.editorial_review') }}
                        </x-filament::button>
                    @endif
                    @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                        <x-filament::button color="primary" type="button" wire:click="applyBulkAction">
                            {{ __('ops.custom_pages.seo_operations.apply_action') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <div class="ops-segmented-control" role="tablist" aria-label="{{ __('ops.custom_pages.seo_operations.title') }}">
            @foreach (['overview', 'performance', 'technical', 'opportunities', 'ai', 'execution'] as $workspace)
                <button
                    type="button"
                    wire:click="$set('activeWorkspace', '{{ $workspace }}')"
                    @class(['ops-segmented-control__item', 'ops-segmented-control__item--active' => $activeWorkspace === $workspace])
                    role="tab"
                    aria-selected="{{ $activeWorkspace === $workspace ? 'true' : 'false' }}"
                >
                    {{ __('ops.custom_pages.seo_operations.workspace.'.$workspace) }}
                </button>
            @endforeach
        </div>

        <div class="ops-article-saved-views" role="group" aria-label="{{ __('ops.custom_pages.seo_operations.saved_views.label') }}">
            @foreach (['all', 'high_impressions_low_ctr', 'current_org_blockers', 'global_career_gaps'] as $view)
                <button type="button" wire:click="applySavedView('{{ $view }}')" class="ops-article-chip{{ $savedView === $view ? ' ops-article-chip--active' : '' }}" aria-pressed="{{ $savedView === $view ? 'true' : 'false' }}">
                    <span class="ops-article-chip__label">{{ __('ops.custom_pages.seo_operations.saved_views.'.$view) }}</span>
                </button>
            @endforeach
        </div>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.scopes.title')"
            :description="__('ops.custom_pages.seo_operations.health.current_snapshot')"
        >
            <div class="ops-data-strip">
                @foreach ($scopeSummary as $scope)
                    <button type="button" wire:click="$set('scopeFilter', '{{ $scope['key'] }}')" class="ops-metric" aria-pressed="{{ $scopeFilter === $scope['key'] ? 'true' : 'false' }}">
                        <span class="ops-metric__label">{{ $scope['label'] }}</span>
                        <span class="ops-metric__value tnum">{{ $scope['count'] }}</span>
                    </button>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.sources.title')">
            <div class="ops-card-list">
                @foreach ($dataSources as $source)
                    <x-filament-ops::ops-result-card
                        :title="$source['label']"
                        :meta="!empty($source['connected']) ? __('ops.custom_pages.seo_operations.sources.connected') : __('ops.custom_pages.seo_operations.phase_two')"
                    >
                        <p class="ops-control-hint">
                            @if (!empty($source['connected']))
                                {{ $source['source'] ?? '' }} · {{ __('ops.custom_pages.seo_operations.sources.updated_at', ['time' => $source['updated_at'] ?? '-']) }}
                            @else
                                {{ __('ops.custom_pages.seo_operations.sources.not_connected') }}
                            @endif
                        </p>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        @if ($activeWorkspace === 'performance')
            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.seo_operations.performance.title')"
                :description="__('ops.custom_pages.seo_operations.performance.description')"
            >
                <x-filament-ops::ops-toolbar>
                    <div class="ops-toolbar-inline">
                        <select wire:model.live="gscDays" aria-label="Date range">
                            <option value="7">7 days</option>
                            <option value="28">28 days</option>
                            <option value="90">90 days</option>
                        </select>
                        <input wire:model.live.debounce.400ms="gscDevice" placeholder="device / all" aria-label="Device" />
                        <input wire:model.live.debounce.400ms="gscCountry" placeholder="country / all" aria-label="Country" />
                        <input wire:model.live.debounce.400ms="gscLocale" placeholder="locale / all" aria-label="Locale" />
                    </div>
                </x-filament-ops::ops-toolbar>

                @if (!empty($searchPerformance['connected']))
                    <div class="ops-data-strip">
                        @foreach ([
                            ['label' => __('ops.custom_pages.seo_operations.performance.clicks'), 'value' => data_get($searchPerformance, 'totals.clicks', 0)],
                            ['label' => __('ops.custom_pages.seo_operations.performance.impressions'), 'value' => data_get($searchPerformance, 'totals.impressions', 0)],
                            ['label' => __('ops.custom_pages.seo_operations.performance.ctr'), 'value' => data_get($searchPerformance, 'totals.ctr_percent', '-').' %'],
                            ['label' => __('ops.custom_pages.seo_operations.performance.position'), 'value' => data_get($searchPerformance, 'totals.average_position', '-')],
                        ] as $metric)
                            <div class="ops-metric"><span class="ops-metric__label">{{ $metric['label'] }}</span><span class="ops-metric__value tnum">{{ $metric['value'] }}</span></div>
                        @endforeach
                    </div>

                    <h3>{{ __('ops.custom_pages.seo_operations.performance.query_page') }}</h3>
                    <div class="ops-table-shell">
                        <table class="ops-table">
                            <thead><tr><th>Query</th><th>Page</th><th>Scope</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead>
                            <tbody>
                                @foreach (data_get($searchPerformance, 'query_page_rows', []) as $row)
                                    <tr><td>{{ $row['query'] }}</td><td>{{ $row['canonical_path'] ?? '-' }}</td><td>{{ $row['locale'] ?? '-' }} / {{ $row['device'] ?? '-' }} / {{ $row['country'] ?? '-' }}</td><td>{{ $row['clicks'] }}</td><td>{{ $row['impressions'] }}</td><td>{{ $row['ctr_percent'] ?? '-' }}%</td><td>{{ $row['average_position'] ?? '-' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($deploymentEvents !== [])
                        <h3>Deployment / content event annotations</h3>
                        <div class="ops-tag-list">
                            @foreach ($deploymentEvents as $event)
                                <span class="ops-tag">{{ $event['occurred_at'] ?? '-' }} · {{ $event['environment'] }} · {{ $event['status'] }} · {{ \Illuminate\Support\Str::limit($event['revision'], 12, '') }}</span>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.performance.not_connected') }}</p>
                @endif
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'opportunities')
            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.opportunities.title')" :description="__('ops.custom_pages.seo_operations.opportunities.description')">
                <div class="ops-table-shell"><table class="ops-table"><thead><tr><th>Query</th><th>Page</th><th>Scope</th><th>Impressions</th><th>CTR</th><th>Position</th><th>Impact / Effort / Confidence</th><th>Action</th></tr></thead><tbody>
                    @forelse ($opportunityQueue as $row)
                        <tr><td>{{ $row['query_display_masked'] ?? '-' }}</td><td>{{ $row['canonical_path'] ?? '-' }}</td><td>{{ $row['locale'] ?? '-' }}</td><td>{{ data_get($row, 'metrics.impressions', 0) }}</td><td>{{ data_get($row, 'metrics.ctr_ppm') === null ? '-' : round(data_get($row, 'metrics.ctr_ppm') / 10000, 2).'%' }}</td><td>{{ data_get($row, 'metrics.average_position_milli') === null ? '-' : round(data_get($row, 'metrics.average_position_milli') / 1000, 2) }}</td><td>{{ data_get($row, 'priority.impact', 0) }} / {{ data_get($row, 'priority.effort', '-') }} / {{ data_get($row, 'priority.confidence', '-') }}</td><td>{{ $row['recommended_next_step'] ?? '-' }}</td></tr>
                    @empty
                        <tr><td colspan="8">{{ __('ops.custom_pages.seo_operations.opportunities.empty') }}</td></tr>
                    @endforelse
                </tbody></table></div>
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'ai')
            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.workspace.ai')" :description="__('ops.custom_pages.seo_operations.phase_two')">
                <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.sources.not_connected') }}</p>
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'overview')
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.health.title')"
            :description="__('ops.custom_pages.seo_operations.health.current_snapshot')"
        >
            <div class="ops-data-strip">
                @foreach ($healthBand as $metric)
                    <div class="ops-metric">
                        <span class="ops-metric__label">{{ $metric['label'] }}</span>
                        <span class="ops-metric__value tnum">{{ $metric['value'] }}<small>{{ $metric['suffix'] ?? '' }}</small></span>
                    </div>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.readiness_title')"
            :description="__('ops.custom_pages.seo_operations.readiness_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'technical')
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.issue_breakdown_title')"
            :description="__('ops.custom_pages.seo_operations.issue_breakdown_desc')"
        >
            @if ($issueBreakdown === [])
                <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.no_issue_breakdown') }}</p>
            @else
                <div class="ops-breakdown-stack">
                    @foreach ($issueBreakdown as $row)
                        <button type="button" class="ops-breakdown-row" wire:click="focusIssue('{{ $row['code'] }}')">
                            <span class="ops-breakdown-row__label">{{ $row['label'] }}</span>
                            <div class="ops-breakdown-row__track">
                                <div class="ops-breakdown-row__bar" style="width: {{ $row['pct'] }}%"></div>
                            </div>
                            <span class="ops-breakdown-row__value tnum">{{ $row['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.coverage_title')"
            :description="__('ops.custom_pages.seo_operations.coverage_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$coverageFields" />
        </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'overview')
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.growth_title')"
            :description="__('ops.custom_pages.seo_operations.growth_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$growthFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.attention_title')"
            :description="__('ops.custom_pages.seo_operations.attention_desc')"
        >
            <div class="ops-card-list">
                @foreach ($attentionCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.latest_record', ['title' => $card['latest_title']]) }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status'].' | '.$card['value']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'execution')
        <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.execution.title')" :description="__('ops.custom_pages.seo_operations.execution.description')">
            @if (!$seoIntelAvailable)
                <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.execution.unavailable') }}</p>
            @else
                @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                    <x-filament-ops::ops-toolbar>
                        <div class="ops-toolbar-inline">
                            <select wire:model="selectedIssueUid" aria-label="Issue"><option value="">Issue</option>@foreach ($executionQueue as $issue)<option value="{{ $issue['issue_id'] }}">{{ $issue['issue_type'] }} · {{ $issue['canonical_path'] ?? '-' }}</option>@endforeach</select>
                            <select wire:model="workflowAction" aria-label="Action"><option value="assign">Assign to me</option><option value="fixed">Mark fixed</option><option value="verify">Verify & close</option><option value="ignore">Ignore</option><option value="reopen">Reopen</option></select>
                            <input wire:model="ignoreReason" placeholder="{{ __('ops.custom_pages.seo_operations.execution.reason') }}" />
                            <input wire:model="ignoredUntil" type="date" aria-label="{{ __('ops.custom_pages.seo_operations.execution.until') }}" />
                            <x-filament::button type="button" wire:click="applyIssueWorkflow">{{ __('ops.custom_pages.seo_operations.execution.apply') }}</x-filament::button>
                        </div>
                    </x-filament-ops::ops-toolbar>
                @endif
                <div class="ops-table-shell"><table class="ops-table"><thead><tr><th>Issue / Page</th><th>Scope</th><th>Impact</th><th>Status</th><th>{{ __('ops.custom_pages.seo_operations.execution.owner') }}</th><th>{{ __('ops.custom_pages.seo_operations.execution.first_seen') }}</th><th>{{ __('ops.custom_pages.seo_operations.execution.last_seen') }}</th><th>{{ __('ops.custom_pages.seo_operations.execution.sla') }}</th><th>{{ __('ops.custom_pages.seo_operations.execution.verification') }}</th></tr></thead><tbody>
                    @forelse ($executionQueue as $issue)
                        <tr><td><strong>{{ $issue['issue_type'] }}</strong><br><span class="ops-control-hint">{{ $issue['canonical_path'] ?? '-' }} · {{ $issue['summary'] ?? '-' }}</span><details><summary>{{ __('ops.custom_pages.common.actions.inspect') }}</summary><p class="ops-control-hint">{{ $issue['recommendation'] ?? '-' }}</p></details></td><td>Global SEO Intel · {{ $issue['locale'] ?? '-' }}</td><td>{{ data_get($issue, 'impact.affected_urls', 0) }} URL / {{ data_get($issue, 'impact.clicks', 0) }} clicks / {{ data_get($issue, 'impact.impressions', 0) }} impressions</td><td>{{ $issue['status'] }} / {{ $issue['lifecycle_state'] }}</td><td>{{ data_get($issue, 'workflow.owner', '-') }}</td><td>{{ $issue['first_detected_at'] ?? '-' }}</td><td>{{ $issue['detected_at'] ?? '-' }}</td><td>{{ data_get($issue, 'workflow.sla_due_at', '-') }}</td><td>{{ data_get($issue, 'workflow.verification_result', '-') }}</td></tr>
                    @empty
                        <tr><td colspan="9">{{ __('ops.custom_pages.seo_operations.no_issues') }}</td></tr>
                    @endforelse
                </tbody></table></div>
            @endif
        </x-filament-ops::ops-section>

        @endif

        @if (in_array($activeWorkspace, ['overview', 'execution'], true))
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.issue_queue_title')"
            :description="__('ops.custom_pages.seo_operations.issue_queue_desc')"
        >
            <div class="ops-control-stack">
                <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.query_latency') }}</span>
                <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.query_latency_desc', ['ms' => $issueQueueElapsedMs]) }}</p>
            </div>

            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                                <th>{{ __('ops.custom_pages.common.table.select') }}</th>
                            @endif
                            <th>{{ __('ops.custom_pages.common.table.record') }}</th>
                            <th>{{ __('ops.custom_pages.common.table.scope') }}</th>
                            <th>{{ __('ops.custom_pages.seo_operations.headers.issues') }}</th>
                            <th>{{ __('ops.custom_pages.seo_operations.headers.growth_signal') }}</th>
                            <th>{{ __('ops.custom_pages.common.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($issueQueue as $item)
                            <tr>
                                @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                                    <td>
                                        <input
                                            type="checkbox"
                                            wire:model="selectedTargets"
                                            value="{{ (string) ($item['selection_key'] ?? '') }}"
                                        />
                                    </td>
                                @endif
                                <td>
                                    <div class="ops-control-stack">
                                        <strong>{{ $item['title'] }}</strong>
                                        <span class="ops-control-hint">
                                            {{ strtoupper((string) ($item['type'] ?? 'content')) }}
                                            |
                                            {{ (string) ($item['status'] ?? 'draft') }}
                                            |
                                            {{ !empty($item['is_public']) ? 'public' : 'private' }}
                                            |
                                            {{ !empty($item['is_indexable']) ? 'indexable' : 'noindex' }}
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $item['scope'] }}</td>
                                <td>
                                    <div class="ops-tag-list">
                                        @foreach (($item['issue_labels'] ?? []) as $label)
                                            <span class="ops-tag">{{ $label }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td>{{ $item['growth_signal'] }}</td>
                                <td>
                                    <div class="ops-toolbar-inline">
                                        <x-filament::button
                                            size="xs"
                                            color="gray"
                                            tag="a"
                                            href="{{ (string) ($item['edit_url'] ?? '#') }}"
                                        >
                                            {{ __('ops.custom_pages.common.actions.open') }}
                                        </x-filament::button>
                                        @if (!empty($item['autofix_actions']))
                                            <span class="ops-control-hint">{{ implode(', ', $item['autofix_actions']) }}</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ \App\Filament\Ops\Support\ContentAccess::canWrite() ? '6' : '5' }}">
                                    <span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.no_issues') }}</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
