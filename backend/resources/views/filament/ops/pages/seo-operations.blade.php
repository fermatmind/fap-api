<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.seo_operations.eyebrow')"
            :title="__('ops.custom_pages.seo_operations.title')"
            :description="__('ops.custom_pages.seo_operations.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-toolbar-grid">
                    <label class="ops-control-stack" for="ops-seo-type-filter">
                        <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.content_type') }}</span>
                        <select id="ops-seo-type-filter" wire:model.live="typeFilter" class="ops-input">
                            <option value="all">{{ __('ops.custom_pages.seo_operations.filters.all_visible') }}</option>
                            <option value="article">{{ __('ops.custom_pages.common.filters.articles') }}</option>
                            <option value="guide">{{ __('ops.custom_pages.common.filters.career_guides') }}</option>
                            <option value="job">{{ __('ops.custom_pages.common.filters.career_jobs') }}</option>
                        </select>
                    </label>

                    <label class="ops-control-stack" for="ops-seo-issue-filter">
                        <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.issue_focus') }}</span>
                        <select id="ops-seo-issue-filter" wire:model.live="issueFilter" class="ops-input">
                            <option value="all">{{ __('ops.custom_pages.seo_operations.filters.all_issues') }}</option>
                            <option value="metadata">{{ __('ops.custom_pages.seo_operations.filters.metadata') }}</option>
                            <option value="canonical">{{ __('ops.custom_pages.seo_operations.filters.canonical') }}</option>
                            <option value="robots">{{ __('ops.custom_pages.seo_operations.filters.robots') }}</option>
                            <option value="indexability">{{ __('ops.custom_pages.seo_operations.filters.indexability') }}</option>
                            <option value="social">{{ __('ops.custom_pages.seo_operations.filters.social') }}</option>
                            <option value="growth">{{ __('ops.custom_pages.seo_operations.filters.growth') }}</option>
                        </select>
                    </label>

                    <label class="ops-control-stack" for="ops-seo-bulk-action">
                        <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.bulk_action') }}</span>
                        <select id="ops-seo-bulk-action" wire:model="bulkAction" class="ops-input">
                            <option value="fill_metadata">{{ __('ops.custom_pages.seo_operations.filters.fill_metadata') }}</option>
                            <option value="sync_canonical">{{ __('ops.custom_pages.seo_operations.filters.sync_canonical') }}</option>
                            <option value="sync_robots">{{ __('ops.custom_pages.seo_operations.filters.sync_robots') }}</option>
                            <option value="mark_indexable">{{ __('ops.custom_pages.seo_operations.filters.mark_indexable') }}</option>
                            <option value="mark_noindex">{{ __('ops.custom_pages.seo_operations.filters.mark_noindex') }}</option>
                        </select>
                    </label>

                    <div class="ops-control-stack">
                        <span class="ops-control-label">{{ __('ops.custom_pages.seo_operations.contract_label') }}</span>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.seo_operations.contract_hint') }}</p>
                    </div>
                </div>

                <x-slot name="actions">
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

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.readiness_title')"
            :description="__('ops.custom_pages.seo_operations.readiness_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.seo_operations.coverage_title')"
            :description="__('ops.custom_pages.seo_operations.coverage_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$coverageFields" />
        </x-filament-ops::ops-section>

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
    </div>
</x-filament-panels::page>
