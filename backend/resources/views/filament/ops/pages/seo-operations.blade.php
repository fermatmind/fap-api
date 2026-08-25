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

    <div
        class="ops-shell-page ops-seo-workspace"
        data-query-budget="{{ \App\Filament\Ops\Pages\SeoOperationsPage::MAX_INITIAL_QUERY_COUNT }}"
        data-response-budget-ms="{{ \App\Filament\Ops\Pages\SeoOperationsPage::MAX_INITIAL_RESPONSE_MS }}"
        data-dom-row-budget="{{ \App\Filament\Ops\Pages\SeoOperationsPage::MAX_RENDERED_TABLE_ROWS }}"
        data-display-preset="{{ $displayPreset }}"
    >
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

        <x-filament-ops::ops-seo-council-nav
            :workspaces="\App\Filament\Ops\Pages\SeoOperationsPage::workspaceKeys()"
            :active="$activeWorkspace"
        />

        @if ($activeWorkspace === 'overview')
            <section class="ops-seo-decision-strip" aria-labelledby="ops-seo-decision-title">
                <div class="ops-seo-section-heading">
                    <div>
                        <h2 id="ops-seo-decision-title">{{ __('ops.custom_pages.seo_operations.decision.title') }}</h2>
                        <p>{{ __('ops.custom_pages.seo_operations.decision.description') }}</p>
                    </div>
                </div>
                <div class="ops-data-strip" data-signal-count="{{ count($decisionSignals) }}">
                    @foreach ($decisionSignals as $signal)
                        <button type="button" class="ops-metric" wire:click="openDecisionWorkspace('{{ $signal['workspace'] }}')">
                            <span class="ops-metric__label">{{ $signal['label'] }}</span>
                            <span class="ops-metric__value tnum">{{ $signal['value'] }}</span>
                            <small>{{ $signal['hint'] }}</small>
                        </button>
                    @endforeach
                </div>
            </section>
        @endif

        <div class="ops-seo-commandbar" role="toolbar" aria-label="{{ __('ops.custom_pages.seo_operations.toolbar') }}">
            <label class="ops-seo-commandbar__field" for="ops-seo-scope-filter">
                <span>{{ __('ops.custom_pages.seo_operations.scopes.title') }}</span>
                <select id="ops-seo-scope-filter" wire:model.live="scopeFilter">
                    @foreach ([
                        \App\Services\Ops\SeoContentScopeViewModel::SCOPE_COMBINED => __('ops.custom_pages.seo_operations.scopes.combined'),
                        \App\Services\Ops\SeoContentScopeViewModel::SCOPE_GLOBAL_ARTICLES => __('ops.custom_pages.seo_operations.scopes.global_articles'),
                        \App\Services\Ops\SeoContentScopeViewModel::SCOPE_GLOBAL_CAREER => __('ops.custom_pages.seo_operations.scopes.global_career'),
                    ] as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
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
            <label class="ops-seo-commandbar__field" for="ops-seo-sort">
                <span>{{ __('ops.custom_pages.seo_operations.sort.label') }}</span>
                <select id="ops-seo-sort" wire:model.live="sortBy">
                    <option value="priority">{{ __('ops.custom_pages.seo_operations.sort.priority') }}</option>
                    <option value="impact">{{ __('ops.custom_pages.seo_operations.sort.impact') }}</option>
                    <option value="affected_urls">{{ __('ops.custom_pages.seo_operations.sort.affected_urls') }}</option>
                    <option value="newest">{{ __('ops.custom_pages.seo_operations.sort.newest') }}</option>
                </select>
            </label>
            <label class="ops-seo-commandbar__field" for="ops-seo-display">
                <span>{{ __('ops.custom_pages.seo_operations.display.label') }}</span>
                <select id="ops-seo-display" wire:model.live="displayPreset">
                    <option value="decision">{{ __('ops.custom_pages.seo_operations.display.decision') }}</option>
                    <option value="evidence">{{ __('ops.custom_pages.seo_operations.display.evidence') }}</option>
                    <option value="workflow">{{ __('ops.custom_pages.seo_operations.display.workflow') }}</option>
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

        <x-filament-ops::ops-trust-strip
            :label="__('ops.custom_pages.seo_operations.sources.title')"
            :items="collect($dataSources)->map(fn (array $source): array => [
                ...$source,
                'state' => !empty($source['connected']) ? 'production_healthy' : 'external_not_connected',
            ])->all()"
        />

        @if ($activeWorkspace === 'url-truth')
            @php
                $platformOverview = (array) data_get($platformReadModels, 'overview', []);
                $familyPolicy = (array) data_get($platformOverview, 'url_truth.page_family_policy', []);
                $platformMetrics = [
                    ['label' => __('ops.custom_pages.seo_operations.platform.public_authority'), 'value' => data_get($familyPolicy, 'coverage.current_public_authority_total')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.url_truth'), 'value' => data_get($platformOverview, 'url_truth.total_count')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.url_truth_gaps'), 'value' => data_get($familyPolicy, 'url_truth_missing_handoff.current_count')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.issues'), 'value' => data_get($platformOverview, 'issues.total_count')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.opportunities'), 'value' => data_get($platformReadModels, 'opportunities.total_count')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.private_leaks'), 'value' => data_get($platformOverview, 'url_truth.safety_counts.private_flow_count')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.search_submission'), 'value' => data_get($platformOverview, 'search_submission.state')],
                    ['label' => __('ops.custom_pages.seo_operations.platform.scheduler'), 'value' => data_get($platformOverview, 'scheduler.state')],
                ];
            @endphp
            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.seo_operations.platform.title')"
                :description="__('ops.custom_pages.seo_operations.platform.description')"
            >
                <p class="ops-control-hint">
                    {{ __('ops.custom_pages.seo_operations.platform.source_contract', [
                        'state' => $platformOverview['state'] ?? 'unavailable',
                        'source' => $platformOverview['source'] ?? 'seo_intel',
                        'observed' => $platformOverview['observed_at'] ?? '-',
                    ]) }}
                </p>
                <div class="ops-data-strip">
                    @foreach ($platformMetrics as $metric)
                        <div class="ops-metric">
                            <span class="ops-metric__label">{{ $metric['label'] }}</span>
                            <span class="ops-metric__value tnum">{{ $metric['value'] === null ? __('ops.custom_pages.seo_operations.platform.not_available') : $metric['value'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="ops-tag-list">
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.page_family') }} · {{ $familyPolicy['policy_version'] ?? __('ops.custom_pages.seo_operations.platform.not_available') }}</span>
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.search_queue') }} · {{ data_get($platformOverview, 'search_channel.total_counts.items') ?? __('ops.custom_pages.seo_operations.platform.not_available') }}</span>
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.crawler') }} · {{ data_get($platformOverview, 'crawler.total_count') ?? __('ops.custom_pages.seo_operations.platform.not_available') }}</span>
                </div>
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace !== 'overview')
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
        @endif

        <div id="ops-seo-workspace-panel" class="ops-seo-workspace-panel" data-workspace="{{ $activeWorkspace }}">

        @if ($activeWorkspace === 'automation')
            <x-filament-ops::ops-automation-nav
                :sections="\App\Filament\Ops\Pages\SeoOperationsPage::automationSectionKeys()"
                :active="$activeAutomationSection"
            />
        @endif

        @if ($activeWorkspace === 'automation' && $activeAutomationSection === 'experiments')
            <x-filament-ops::ops-experiment-ledger-workspace />
        @endif

        @if ($activeWorkspace === 'performance')
            <x-filament-ops::ops-section
                :title="__('ops.custom_pages.seo_operations.performance.title')"
                :description="__('ops.custom_pages.seo_operations.performance.description')"
            >
                <x-filament-ops::ops-toolbar>
                    <div class="ops-toolbar-inline">
                        <select wire:model.live="gscDays" aria-label="{{ __('ops.custom_pages.seo_operations.performance.date_range') }}">
                            <option value="7">{{ __('ops.custom_pages.seo_operations.performance.days', ['count' => 7]) }}</option>
                            <option value="28">{{ __('ops.custom_pages.seo_operations.performance.days', ['count' => 28]) }}</option>
                            <option value="90">{{ __('ops.custom_pages.seo_operations.performance.days', ['count' => 90]) }}</option>
                        </select>
                        <input wire:model.live.debounce.400ms="gscDevice" placeholder="{{ __('ops.custom_pages.seo_operations.performance.device_placeholder') }}" aria-label="{{ __('ops.custom_pages.seo_operations.performance.device') }}" />
                        <input wire:model.live.debounce.400ms="gscCountry" placeholder="{{ __('ops.custom_pages.seo_operations.performance.country_placeholder') }}" aria-label="{{ __('ops.custom_pages.seo_operations.performance.country') }}" />
                        <input wire:model.live.debounce.400ms="gscLocale" placeholder="{{ __('ops.custom_pages.seo_operations.performance.locale_placeholder') }}" aria-label="{{ __('ops.custom_pages.seo_operations.filters.locale') }}" />
                        <select wire:model.live="gscSearchType" aria-label="{{ __('ops.custom_pages.seo_operations.performance.search_type') }}">
                            <option value="all">{{ __('ops.custom_pages.seo_operations.performance.search_type_all') }}</option>
                            @foreach (['web', 'image', 'video', 'news'] as $searchType)
                                <option value="{{ $searchType }}">{{ strtoupper($searchType) }}</option>
                            @endforeach
                        </select>
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
                            <thead><tr><th>{{ __('ops.custom_pages.seo_operations.table.query') }}</th><th>{{ __('ops.custom_pages.seo_operations.table.page') }}</th><th>{{ __('ops.custom_pages.common.table.scope') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.clicks') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.impressions') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.ctr') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.position') }}</th></tr></thead>
                            <tbody>
                                @foreach (data_get($searchPerformance, 'query_page_rows', []) as $row)
                                    <tr><td>{{ $row['query'] }}</td><td>{{ $row['canonical_path'] ?? '-' }}</td><td>{{ $row['locale'] ?? '-' }} / {{ $row['device'] ?? '-' }} / {{ $row['country'] ?? '-' }}</td><td>{{ $row['clicks'] }}</td><td>{{ $row['impressions'] }}</td><td>{{ $row['ctr_percent'] ?? '-' }}%</td><td>{{ $row['average_position'] ?? '-' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($deploymentEvents !== [])
                        <h3>{{ __('ops.custom_pages.seo_operations.performance.event_annotations') }}</h3>
                        <div class="ops-tag-list">
                            @foreach ($deploymentEvents as $event)
                                <span class="ops-tag">{{ $event['occurred_at'] ?? '-' }} · {{ $event['environment'] }} · {{ $event['status'] }} · {{ \Illuminate\Support\Str::limit($event['revision'], 12, '') }}</span>
                            @endforeach
                        </div>
                    @endif
                    @php($publicFunnel = (array) data_get($platformReadModels, 'performance.public_funnel', []))
                    <h3>{{ __('ops.custom_pages.seo_operations.platform.public_funnel') }}</h3>
                    @if ((array) ($publicFunnel['warnings'] ?? []) === [])
                        <div class="ops-data-strip">
                            @foreach ([
                                ['label' => __('ops.custom_pages.seo_operations.platform.landing_views'), 'value' => data_get($publicFunnel, 'totals.landing_pv_count')],
                                ['label' => __('ops.custom_pages.seo_operations.platform.test_starts'), 'value' => data_get($publicFunnel, 'totals.start_test_count')],
                                ['label' => __('ops.custom_pages.seo_operations.platform.result_views'), 'value' => data_get($publicFunnel, 'totals.view_result_count')],
                            ] as $metric)
                                <div class="ops-metric"><span class="ops-metric__label">{{ $metric['label'] }}</span><span class="ops-metric__value tnum">{{ $metric['value'] ?? __('ops.custom_pages.seo_operations.platform.not_available') }}</span></div>
                            @endforeach
                        </div>
                    @else
                        <p class="ops-control-hint">measurement_hold · {{ collect($publicFunnel['warnings'])->join(' · ') }}</p>
                    @endif
                @else
                    <x-filament-ops::ops-not-connected
                        :title="__('ops.custom_pages.seo_operations.performance.source_name')"
                        :description="__('ops.custom_pages.seo_operations.performance.states.'.($searchPerformance['state'] ?? 'disconnected'))"
                    />
                    @if (!empty($searchPerformance['failure_code']))
                        <p class="ops-muted">{{ __('ops.custom_pages.seo_operations.performance.failure_code') }}: {{ $searchPerformance['failure_code'] }}</p>
                    @endif
                    @if (!empty($searchPerformance['last_success_at']))
                        <p class="ops-muted">{{ __('ops.custom_pages.seo_operations.performance.last_success') }}: {{ $searchPerformance['last_success_at'] }}</p>
                    @endif
                @endif
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'performance')
            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.opportunities.title')" :description="__('ops.custom_pages.seo_operations.opportunities.description')">
                @if (($opportunityReadModel['state'] ?? 'unavailable') !== 'connected' && ($opportunityReadModel['state'] ?? '') !== 'empty')
                    <x-filament-ops::ops-not-connected
                        :title="__('ops.custom_pages.seo_operations.opportunities.title')"
                        :description="__('ops.custom_pages.seo_operations.opportunities.states.'.($opportunityReadModel['state'] ?? 'unavailable'))"
                    />
                @else
                <div class="ops-table-shell"><table class="ops-table"><thead><tr><th>{{ __('ops.custom_pages.seo_operations.table.query') }}</th><th>{{ __('ops.custom_pages.seo_operations.table.page') }}</th><th>{{ __('ops.custom_pages.common.table.scope') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.impressions') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.ctr') }}</th><th>{{ __('ops.custom_pages.seo_operations.performance.position') }}</th><th>{{ __('ops.custom_pages.seo_operations.opportunities.priority_factors') }}</th><th>{{ __('ops.custom_pages.common.table.actions') }}</th></tr></thead><tbody>
                    @forelse ($opportunityQueue as $row)
                        <tr><td>{{ $row['query_display_masked'] ?? '-' }}<br><span class="ops-control-hint">{{ collect($row['opportunity_types'] ?? ['unknown'])->map(fn ($type) => __('ops.custom_pages.seo_operations.opportunities.types.'.$type))->join(' · ') }}</span></td><td>{{ $row['canonical_path'] ?? __('ops.custom_pages.seo_operations.opportunities.unmapped') }}</td><td>{{ $row['locale'] ?? '-' }}</td><td>{{ data_get($row, 'metrics.impressions') ?? '-' }}</td><td>{{ data_get($row, 'metrics.ctr_ppm') === null ? '-' : round(data_get($row, 'metrics.ctr_ppm') / 10000, 2).'%' }}</td><td>{{ data_get($row, 'metrics.average_position_milli') === null ? '-' : round(data_get($row, 'metrics.average_position_milli') / 1000, 2) }}</td><td>{{ data_get($row, 'priority.impact', '-') }} / {{ data_get($row, 'priority.effort', '-') }} / {{ data_get($row, 'priority.confidence', '-') }}</td><td>{{ collect($row['recommended_actions'] ?? ['human_review'])->map(fn ($action) => __('ops.custom_pages.seo_operations.opportunities.actions.'.$action))->join(' · ') }}<br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.opportunities.human_review') }}</span></td></tr>
                    @empty
                        <tr><td colspan="8">{{ __('ops.custom_pages.seo_operations.opportunities.empty') }}</td></tr>
                    @endforelse
                </tbody></table></div>
                @endif
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'automation' && $activeAutomationSection === 'operations')
            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.workspace.ai')" :description="__('ops.custom_pages.seo_operations.platform.not_implemented')">
                <x-filament-ops::ops-not-connected :title="__('ops.custom_pages.seo_operations.workspace.ai')" :description="data_get($platformReadModels, 'ai.unavailable_reason', 'not_implemented')" />
                <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.platform.source_contract', [
                    'state' => data_get($platformReadModels, 'ai.state', 'not_implemented'),
                    'source' => data_get($platformReadModels, 'ai.source', 'seo_agent_runtime'),
                    'observed' => data_get($platformReadModels, 'ai.observed_at', '-'),
                ]) }}</p>
                <div class="ops-tag-list">
                    @foreach ((array) data_get($platformReadModels, 'ai.risk_caps', []) as $family => $cap)
                        <span class="ops-tag">{{ $this->operatorLabel($family) }} · {{ $cap }}</span>
                    @endforeach
                </div>
            </x-filament-ops::ops-section>
        @endif

        @if ($activeWorkspace === 'overview')
            @if ($criticalAnomalies !== [])
                <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.decision.critical_anomalies')">
                    <div class="ops-card-list">
                        @foreach ($criticalAnomalies as $cluster)
                            <x-filament-ops::ops-result-card
                                :title="$cluster['issue_type']"
                                :meta="$cluster['severity'].' · '.__('ops.custom_pages.seo_operations.decision.affected_url_count', ['count' => $cluster['affected_url_count']])"
                            >
                                <p class="ops-control-hint">{{ $cluster['summary'] ?? $cluster['root_cause'] }}</p>
                                <x-slot name="actions">
                                    <x-filament::button size="xs" color="gray" type="button" wire:click="openClusterExecution('{{ $cluster['cluster_uid'] }}')">
                                        {{ __('ops.custom_pages.seo_operations.decision.review_action') }}
                                    </x-filament::button>
                                </x-slot>
                            </x-filament-ops::ops-result-card>
                        @endforeach
                    </div>
                </x-filament-ops::ops-section>
            @endif

            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.decision.priority_clusters')">
                <div class="ops-table-shell">
                    <table class="ops-table">
                        <thead><tr><th>{{ __('ops.custom_pages.seo_operations.clusters.cluster') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.priority') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.affected_urls') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.recommendation') }}</th></tr></thead>
                        <tbody>
                            @forelse ($overviewPriorityClusters as $cluster)
                                <tr><td>{{ $cluster['issue_type'] }}</td><td class="tnum">{{ data_get($cluster, 'priority.score', '-') }}</td><td class="tnum">{{ $cluster['affected_url_count'] }}</td><td>{{ $cluster['recommendation'] ?? '-' }}</td></tr>
                            @empty
                                <tr><td colspan="4">{{ __('ops.custom_pages.seo_operations.decision.no_priority_clusters') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament-ops::ops-section>

            <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.decision.today_actions')">
                <div class="ops-card-list">
                    @forelse ($todayActions as $action)
                        <x-filament-ops::ops-result-card
                            :title="$action['title']"
                            :meta="__('ops.custom_pages.seo_operations.decision.action_meta', ['score' => $action['score'], 'count' => $action['impact']])"
                        >
                            <p>{{ $action['action'] }}</p>
                            <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.decision.why') }}: {{ $action['reason'] }}</p>
                            <x-slot name="actions">
                                @if (!empty($action['cluster_uid']))
                                    <x-filament::button size="xs" type="button" wire:click="openClusterExecution('{{ $action['cluster_uid'] }}')">
                                        {{ __('ops.custom_pages.seo_operations.decision.start_action') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="xs" tag="a" href="{{ $action['edit_url'] }}">
                                        {{ __('ops.custom_pages.seo_operations.decision.start_action') }}
                                    </x-filament::button>
                                @endif
                            </x-slot>
                        </x-filament-ops::ops-result-card>
                    @empty
                        <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.decision.no_today_actions') }}</p>
                    @endforelse
                </div>
            </x-filament-ops::ops-section>

        @endif

        @if ($activeWorkspace === 'technical')
            <x-filament-ops::ops-technical-health-workspace />
        @endif

        @if ($activeWorkspace === 'automation' && $activeAutomationSection === 'operations')
        <x-filament-ops::ops-section :title="__('ops.custom_pages.seo_operations.execution.title')" :description="__('ops.custom_pages.seo_operations.execution.description')">
            @if (!$seoIntelAvailable)
                <x-filament-ops::ops-not-connected :title="__('ops.custom_pages.seo_operations.workspace.execution')" :description="__('ops.custom_pages.seo_operations.execution.unavailable')" />
            @else
                <h3>{{ __('ops.custom_pages.seo_operations.platform.execution_boundaries') }}</h3>
                <div class="ops-tag-list">
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.search_queue') }} · {{ data_get($platformReadModels, 'execution.search_channel.state', 'unavailable') }} · {{ data_get($platformReadModels, 'execution.search_channel.total_counts.items') ?? __('ops.custom_pages.seo_operations.platform.not_available') }}</span>
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.search_submission') }} · {{ data_get($platformReadModels, 'execution.boundaries.search_submission_allowed', false) ? 'enabled' : 'measurement_hold' }}</span>
                    <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.platform.canary') }} · {{ collect((array) data_get($platformReadModels, 'execution.boundaries.career_canary_sequence', []))->join(' → ') }}</span>
                </div>
                @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                    <x-filament-ops::ops-toolbar>
                        <div class="ops-toolbar-inline">
                            <select wire:model="selectedIssueUid" aria-label="{{ __('ops.custom_pages.seo_operations.execution.issue') }}"><option value="">{{ __('ops.custom_pages.seo_operations.execution.inspect_to_select') }}</option>@foreach ($clusterUrls as $issue)<option value="{{ $issue['issue_uid'] }}">{{ $issue['canonical_path'] ?? __('ops.custom_pages.seo_operations.execution.unmapped_url') }}</option>@endforeach</select>
                            <select wire:model="workflowAction" aria-label="{{ __('ops.custom_pages.common.table.actions') }}"><option value="assign">{{ __('ops.custom_pages.seo_operations.execution.assign_me') }}</option><option value="fixed">{{ __('ops.custom_pages.seo_operations.execution.mark_fixed') }}</option><option value="verify">{{ __('ops.custom_pages.seo_operations.execution.verify_close') }}</option><option value="ignore">{{ __('ops.custom_pages.seo_operations.execution.ignore') }}</option><option value="reopen">{{ __('ops.custom_pages.seo_operations.execution.reopen') }}</option></select>
                            <input wire:model="operatorNote" placeholder="{{ __('ops.custom_pages.seo_operations.execution.operator_note') }}" />
                            <input wire:model="ignoreReason" placeholder="{{ __('ops.custom_pages.seo_operations.execution.reason') }}" />
                            <input wire:model="ignoredUntil" type="date" aria-label="{{ __('ops.custom_pages.seo_operations.execution.until') }}" />
                            <input wire:model="verificationNote" placeholder="{{ __('ops.custom_pages.seo_operations.execution.verification_note') }}" />
                            <x-filament::button type="button" wire:click="applyIssueWorkflow">{{ __('ops.custom_pages.seo_operations.execution.apply') }}</x-filament::button>
                        </div>
                    </x-filament-ops::ops-toolbar>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.execution.verification_hint') }}</p>
                @endif
                <div class="ops-table-shell"><table class="ops-table"><thead><tr><th>{{ __('ops.custom_pages.seo_operations.clusters.cluster') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.priority') }}</th><th class="ops-seo-field-scope">{{ __('ops.custom_pages.seo_operations.clusters.root_cause_scope') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.severity') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.affected_urls') }}</th><th class="ops-seo-field-evidence">{{ __('ops.custom_pages.seo_operations.clusters.evidence') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.status') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.recommendation') }}</th><th>{{ __('ops.custom_pages.common.table.actions') }}</th></tr></thead><tbody>
                    @forelse ($issueClusters as $cluster)
                        <tr>
                            <td><strong>{{ $this->operatorLabel($cluster['issue_type']) }}</strong></td>
                            <td>
                                <strong class="tnum">{{ data_get($cluster, 'priority.score', '-') }}</strong>
                                <br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.clusters.priority_formula', [
                                    'impact' => data_get($cluster, 'priority.impact.total', '-'),
                                    'confidence' => data_get($cluster, 'priority.confidence.value', '-'),
                                    'effort' => data_get($cluster, 'priority.effort.value', '-'),
                                ]) }}</span>
                                <br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.clusters.priority_basis', [
                                    'confidence' => __('ops.custom_pages.seo_operations.clusters.confidence_basis.'.data_get($cluster, 'priority.confidence.basis', 'unknown')),
                                    'effort' => __('ops.custom_pages.seo_operations.clusters.effort_basis.'.data_get($cluster, 'priority.effort.basis', 'unknown')),
                                ]) }}</span>
                                @if (data_get($cluster, 'priority.impact.gsc.included', false))
                                    <br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.clusters.gsc_observed', [
                                        'clicks' => data_get($cluster, 'priority.impact.gsc.clicks', 0),
                                        'impressions' => data_get($cluster, 'priority.impact.gsc.impressions', 0),
                                    ]) }}</span>
                                @else
                                    <br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.clusters.no_gsc_impact') }}</span>
                                @endif
                            </td>
                            <td class="ops-seo-field-scope">{{ $this->operatorLabel($cluster['root_cause']) }}<br><span class="ops-control-hint">{{ $this->operatorLabel($cluster['content_type']) }} · {{ $this->operatorLabel($cluster['template']) }} · {{ $this->operatorLabel($cluster['field']) }}</span></td>
                            <td><x-filament.ops.shared.status-pill :state="$cluster['severity']" :label="$cluster['severity']" /></td>
                            <td><span class="tnum">{{ $cluster['affected_url_count'] }}</span><br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.clusters.issue_rows', ['count' => $cluster['issue_count']]) }}</span></td>
                            <td class="ops-seo-field-evidence"><span class="tnum">{{ $cluster['evidence_count'] }}</span><br><span class="ops-control-hint">{{ $cluster['summary'] ?? '-' }}</span></td>
                            <td>{{ $cluster['status'] }}<br><span class="ops-control-hint">{{ $cluster['first_detected_at'] ?? '-' }} → {{ $cluster['last_detected_at'] ?? '-' }}</span></td>
                            <td>{{ $cluster['recommendation'] ?? '-' }}</td>
                            <td><x-filament::button size="xs" color="gray" type="button" wire:click="inspectIssueCluster('{{ $cluster['cluster_uid'] }}')">{{ __('ops.custom_pages.common.actions.inspect') }}</x-filament::button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9">{{ __('ops.custom_pages.seo_operations.no_issues') }}</td></tr>
                    @endforelse
                </tbody></table></div>
                @if ($issueClusterLastPage > 1)
                    <nav class="ops-server-pagination" aria-label="{{ __('ops.custom_pages.seo_operations.clusters.list') }}">
                        <button type="button" wire:click="previousIssueClusterPage" @disabled($issueClusterPage <= 1)>{{ __('pagination.previous') }}</button>
                        <span class="tnum">{{ $issueClusterPage }} / {{ $issueClusterLastPage }}</span>
                        <button type="button" wire:click="nextIssueClusterPage" @disabled($issueClusterPage >= $issueClusterLastPage)>{{ __('pagination.next') }}</button>
                    </nav>
                @endif

                @if ($selectedClusterUid !== '')
                    <aside class="ops-seo-inspector" aria-label="{{ __('ops.custom_pages.seo_operations.clusters.inspector') }}">
                    <div class="ops-seo-section-heading"><div><h3>{{ __('ops.custom_pages.seo_operations.clusters.inspector') }}</h3><p>{{ __('ops.custom_pages.seo_operations.clusters.issue_rows', ['count' => $clusterUrlTotal]) }}</p></div></div>
                    @if ($pageInspector !== [])
                        <div class="ops-card-list" data-page-inspector-state="{{ $pageInspector['state'] ?? 'unavailable' }}">
                            <x-filament-ops::ops-result-card
                                :title="$pageInspector['canonical_path'] ?? __('ops.custom_pages.seo_operations.platform.not_available')"
                                :meta="collect([$pageInspector['family'] ?? 'unclassified', $pageInspector['locale'] ?? '-', $pageInspector['entity_type'] ?? '-'])->join(' · ')"
                            >
                                <div class="ops-tag-list">
                                    @foreach ([
                                        'authority' => $pageInspector['authority'] ?? null,
                                        'publication' => $pageInspector['publication_state'] ?? null,
                                        'indexability' => $pageInspector['indexability_state'] ?? null,
                                        'canonical' => $pageInspector['canonical_state'] ?? null,
                                        'hreflang' => $pageInspector['hreflang_state'] ?? null,
                                        'schema' => $pageInspector['schema_state'] ?? null,
                                        'sitemap' => $pageInspector['sitemap_eligible'] ?? null,
                                        'llms' => $pageInspector['llms_eligible'] ?? null,
                                        'cms revision' => $pageInspector['cms_revision'] ?? null,
                                        'family risk cap' => $pageInspector['family_risk_cap'] ?? null,
                                        'rollback' => $pageInspector['revert_state'] ?? null,
                                    ] as $label => $value)
                                        <span class="ops-tag">{{ $this->operatorLabel($label) }} · {{ $value === null ? 'unavailable' : (is_bool($value) ? ($value ? 'eligible' : 'ineligible') : $value) }}</span>
                                    @endforeach
                                </div>
                                <p class="ops-control-hint">GSC · {{ data_get($pageInspector, 'gsc.state', 'unavailable') }} · {{ data_get($pageInspector, 'gsc.clicks') ?? 'unavailable' }} clicks · {{ data_get($pageInspector, 'gsc.impressions') ?? 'unavailable' }} impressions</p>
                                <p>{{ data_get($pageInspector, 'issue.summary') ?? '-' }}</p>
                                <p class="ops-control-hint">{{ data_get($pageInspector, 'issue.recommendation') ?? '-' }}</p>
                                <x-slot name="actions">
                                    @if (!empty($pageInspector['cms_edit_url']))
                                        <x-filament::button size="xs" tag="a" href="{{ $pageInspector['cms_edit_url'] }}">CMS edit</x-filament::button>
                                    @endif
                                    @if (!empty($pageInspector['preview_url']))
                                        <x-filament::button size="xs" color="gray" tag="a" href="{{ $pageInspector['preview_url'] }}">Preview</x-filament::button>
                                    @endif
                                </x-slot>
                            </x-filament-ops::ops-result-card>
                        </div>
                    @endif
                    <div class="ops-table-shell"><table class="ops-table"><thead><tr><th>{{ __('ops.custom_pages.seo_operations.table.page') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.type_locale') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.severity') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.status') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.evidence') }}</th><th>{{ __('ops.custom_pages.seo_operations.clusters.recommendation') }}</th></tr></thead><tbody>
                        @forelse ($clusterUrls as $url)
                            <tr><td>{{ $url['canonical_path'] ?? '-' }}<br><x-filament::button size="xs" color="gray" type="button" wire:click="inspectPage('{{ $url['issue_uid'] }}')">{{ __('ops.custom_pages.common.actions.inspect') }}</x-filament::button></td><td>{{ $this->operatorLabel($url['page_entity_type'] ?? null) }} · {{ $url['locale'] ?? '-' }}</td><td>{{ $this->operatorLabel($url['severity']) }}</td><td>{{ $this->operatorLabel($url['status']) }} / {{ $this->operatorLabel($url['lifecycle_state']) }}<br><span class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.execution.owner') }}: {{ empty($url['owner_admin_user_id']) ? __('ops.custom_pages.seo_operations.execution.unassigned') : __('ops.custom_pages.seo_operations.execution.assigned') }} · {{ __('ops.custom_pages.seo_operations.execution.sla') }} {{ $url['sla_due_at'] ?? '-' }}</span><br><span class="ops-control-hint">{{ $url['ignore_reason'] ?? $url['verification_note'] ?? $url['operator_note'] ?? '-' }}</span></td><td><details><summary>{{ __('ops.custom_pages.seo_operations.technical.evidence_available') }}</summary><span class="ops-control-hint">{{ $url['summary'] ?? '-' }}</span></details></td><td>{{ $url['recommendation'] ?? '-' }}</td></tr>
                        @empty
                            <tr><td colspan="6">{{ __('ops.custom_pages.seo_operations.no_issues') }}</td></tr>
                        @endforelse
                    </tbody></table></div>
                    @if ($clusterUrlLastPage > 1)
                        <nav class="ops-server-pagination" aria-label="{{ __('ops.custom_pages.seo_operations.clusters.urls') }}">
                            <button type="button" wire:click="previousClusterUrlPage" @disabled($clusterUrlPage <= 1)>{{ __('pagination.previous') }}</button>
                            <span class="tnum">{{ $clusterUrlPage }} / {{ $clusterUrlLastPage }}</span>
                            <button type="button" wire:click="nextClusterUrlPage" @disabled($clusterUrlPage >= $clusterUrlLastPage)>{{ __('pagination.next') }}</button>
                        </nav>
                    @endif
                    </aside>
                @endif
            @endif
        </x-filament-ops::ops-section>

        @endif

        @if ($activeWorkspace === 'automation' && $activeAutomationSection === 'operations')
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
                                            <span class="ops-control-hint">{{ collect($item['autofix_actions'])->map(fn ($action) => $this->operatorLabel($action))->join(' · ') }}</span>
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

        @if ($activeWorkspace === 'content')
            <x-filament-ops::ops-state-message
                state="production_unproven"
                :title="__('ops.custom_pages.seo_operations.workspace.content')"
                :description="__('ops.custom_pages.seo_operations.content_unproven')"
            />
        @endif
        </div>
    </div>
</x-filament-panels::page>
