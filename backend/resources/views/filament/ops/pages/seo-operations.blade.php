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

        $localeFilterOptions = [
            'all' => __('ops.custom_pages.seo_operations.filters.all_locales'),
            'en' => 'English',
            'zh-CN' => '简体中文',
        ];

        $statusFilterOptions = [
            'all' => __('ops.custom_pages.seo_operations.filters.all_statuses'),
            'draft' => __('ops.status.draft'),
            'scheduled' => __('ops.status.scheduled'),
            'published' => __('ops.status.published'),
        ];

        $bulkActionOptions = [
            'fill_metadata' => __('ops.custom_pages.seo_operations.filters.fill_metadata'),
            'sync_canonical' => __('ops.custom_pages.seo_operations.filters.sync_canonical'),
            'sync_robots' => __('ops.custom_pages.seo_operations.filters.sync_robots'),
            'mark_indexable' => __('ops.custom_pages.seo_operations.filters.mark_indexable'),
            'mark_noindex' => __('ops.custom_pages.seo_operations.filters.mark_noindex'),
        ];
    @endphp

    <div class="ops-shell-page ops-seo-workspace">
        <header class="ops-seo-page-header">
            <div class="ops-seo-page-header__copy">
                <span class="ops-shell-eyebrow">{{ __('ops.custom_pages.seo_operations.eyebrow') }}</span>
                <h1>{{ __('ops.custom_pages.seo_operations.title') }}</h1>
                <p>{{ __('ops.custom_pages.seo_operations.description') }}</p>
            </div>
            <div class="ops-seo-page-header__actions">
                <x-filament::button color="gray" type="button" wire:click="exportReport">
                    {{ __('ops.custom_pages.seo_operations.export_report') }}
                </x-filament::button>
                @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                    <x-filament::button color="primary" type="button" wire:click="applyBulkAction">
                        {{ __('ops.custom_pages.seo_operations.apply_action') }}
                    </x-filament::button>
                @endif
            </div>
        </header>

        <div class="ops-seo-workspace-tabs" role="tablist" aria-label="{{ __('ops.custom_pages.seo_operations.title') }}">
            @foreach (['overview', 'performance', 'technical', 'opportunities', 'ai', 'execution'] as $workspace)
                <button
                    type="button"
                    wire:click="$set('activeWorkspace', '{{ $workspace }}')"
                    @class(['ops-seo-workspace-tab', 'ops-seo-workspace-tab--active' => $activeWorkspace === $workspace])
                    role="tab"
                    aria-selected="{{ $activeWorkspace === $workspace ? 'true' : 'false' }}"
                >
                    {{ __('ops.custom_pages.seo_operations.workspace.'.$workspace) }}
                </button>
            @endforeach
        </div>

        <div class="ops-seo-commandbar">
            <label class="ops-seo-commandbar__field" for="ops-seo-type-filter">
                <span>{{ __('ops.custom_pages.seo_operations.content_type') }}</span>
                <select id="ops-seo-type-filter" wire:model.live="typeFilter">
                    @foreach ($typeFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ops-seo-commandbar__field" for="ops-seo-issue-filter">
                <span>{{ __('ops.custom_pages.seo_operations.issue_focus') }}</span>
                <select id="ops-seo-issue-filter" wire:model.live="issueFilter">
                    @foreach ($issueFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ops-seo-commandbar__field" for="ops-seo-locale-filter">
                <span>{{ __('ops.custom_pages.seo_operations.filters.locale') }}</span>
                <select id="ops-seo-locale-filter" wire:model.live="localeFilter">
                    @foreach ($localeFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="ops-seo-commandbar__field" for="ops-seo-status-filter">
                <span>{{ __('ops.custom_pages.seo_operations.filters.status') }}</span>
                <select id="ops-seo-status-filter" wire:model.live="statusFilter">
                    @foreach ($statusFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                <label class="ops-seo-commandbar__field" for="ops-seo-bulk-action">
                    <span>{{ __('ops.custom_pages.seo_operations.bulk_action') }}</span>
                    <select id="ops-seo-bulk-action" wire:model="bulkAction">
                        @foreach ($bulkActionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="ops-seo-commandbar__views">
                <span>{{ __('ops.custom_pages.seo_operations.saved_views.label') }}</span>
                <x-filament-ops::ops-saved-views
                    :views="collect(['all', 'high_impressions_low_ctr', 'global_article_blockers', 'global_career_gaps'])->mapWithKeys(fn (string $view): array => [$view => __('ops.custom_pages.seo_operations.saved_views.'.$view)])->all()"
                    :active="$savedView"
                    :label="__('ops.custom_pages.seo_operations.saved_views.label')"
                />
            </div>
            <div class="ops-seo-commandbar__contract">
                <strong>{{ __('ops.custom_pages.seo_operations.contract_label') }}</strong>
                <span>{{ __('ops.custom_pages.seo_operations.contract_hint') }}</span>
            </div>
        </div>

        <nav class="ops-seo-related-links" aria-label="{{ __('ops.custom_pages.seo_operations.title') }}">
            <a href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.overview') }}</a>
            <a href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.content_metrics') }}</a>
            <a href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.growth_attribution') }}</a>
            <a href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.content_search') }}</a>
            <a href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.editorial_ops') }}</a>
            @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                <a href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">{{ __('ops.custom_pages.common.nav.editorial_review') }}</a>
            @endif
        </nav>

        <section class="ops-seo-snapshot" aria-labelledby="ops-seo-snapshot-title">
            <div class="ops-seo-section-heading">
                <div><h2 id="ops-seo-snapshot-title">{{ __('ops.custom_pages.seo_operations.scopes.title') }}</h2><p>{{ __('ops.custom_pages.seo_operations.health.current_snapshot') }}</p></div>
                <span class="ops-seo-section-heading__meta">{{ __('ops.custom_pages.seo_operations.sources.title') }}</span>
            </div>
            <div class="ops-data-strip ops-seo-scope-strip">
                @foreach ($scopeSummary as $scope)
                    <button type="button" wire:click="$set('scopeFilter', '{{ $scope['key'] }}')" class="ops-metric" aria-pressed="{{ $scopeFilter === $scope['key'] ? 'true' : 'false' }}">
                        <span class="ops-metric__label">{{ $scope['label'] }}</span>
                        <span class="ops-metric__value tnum">{{ $scope['count'] }}</span>
                        <small>{{ $scope['source'] }} · {{ $scope['freshness'] }} · {{ $scope['collected_at'] }}</small>
                    </button>
                @endforeach
            </div>
            <div class="ops-seo-source-strip">
                @foreach ($dataSources as $source)
                    <div @class(['ops-seo-source', 'ops-seo-source--connected' => !empty($source['connected'])])>
                        <span class="ops-seo-source__signal" aria-hidden="true"></span>
                        <div>
                            <strong>{{ $source['label'] }}</strong>
                            <p>
                                @if (!empty($source['connected']))
                                    {{ $source['source'] ?? '' }} · {{ __('ops.custom_pages.seo_operations.sources.updated_at', ['time' => $source['updated_at'] ?? '-']) }}
                                @else
                                    {{ __('ops.custom_pages.seo_operations.sources.not_connected') }} · {{ $source['phase'] ?? __('ops.custom_pages.seo_operations.phase_two') }}
                                @endif
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="ops-seo-workspace-panel" data-workspace="{{ $activeWorkspace }}">

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
                    <x-filament-ops::ops-not-connected title="Google Search Console" :description="__('ops.custom_pages.seo_operations.performance.not_connected')" />
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
                <x-filament-ops::ops-not-connected title="AI Visibility" :description="__('ops.custom_pages.seo_operations.sources.not_connected')" />
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
                <x-filament-ops::ops-not-connected :title="__('ops.custom_pages.seo_operations.workspace.execution')" :description="__('ops.custom_pages.seo_operations.execution.unavailable')" />
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
                    <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.execution.verification_hint') }}</p>
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
                        @forelse ($this->visibleIssueQueue() as $item)
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
            @if (count($issueQueue) > \App\Filament\Ops\Pages\SeoOperationsPage::ISSUE_QUEUE_PER_PAGE)
                <nav class="ops-server-pagination" aria-label="{{ __('ops.custom_pages.seo_operations.issue_queue_title') }}">
                    <button type="button" wire:click="previousIssueQueuePage" @disabled($issueQueuePage <= 1)>{{ __('pagination.previous') }}</button>
                    <span class="tnum">{{ $issueQueuePage }} / {{ max(1, (int) ceil(count($issueQueue) / \App\Filament\Ops\Pages\SeoOperationsPage::ISSUE_QUEUE_PER_PAGE)) }}</span>
                    <button type="button" wire:click="nextIssueQueuePage" @disabled($issueQueuePage >= (int) ceil(count($issueQueue) / \App\Filament\Ops\Pages\SeoOperationsPage::ISSUE_QUEUE_PER_PAGE))>{{ __('pagination.next') }}</button>
                </nav>
            @endif
        </x-filament-ops::ops-section>
        @endif
        </div>
    </div>
</x-filament-panels::page>
