<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="SEO operations"
            title="SEO operations"
            description="Operate the visible CMS SEO footprint across current-org articles and global career content without mixing in support search or content-pack control plane work."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-toolbar-grid">
                    <label class="ops-control-stack" for="ops-seo-type-filter">
                        <span class="ops-control-label">Content type</span>
                        <select id="ops-seo-type-filter" wire:model.live="typeFilter" class="ops-input">
                            <option value="all">All visible content</option>
                            <option value="article">Articles</option>
                            <option value="guide">Career guides</option>
                            <option value="job">Career jobs</option>
                            <option value="method">Methods</option>
                            <option value="data">Data pages</option>
                            <option value="personality">Personality</option>
                            <option value="topic">Topics</option>
                        </select>
                    </label>

                    <label class="ops-control-stack" for="ops-seo-issue-filter">
                        <span class="ops-control-label">Issue focus</span>
                        <select id="ops-seo-issue-filter" wire:model.live="issueFilter" class="ops-input">
                            <option value="all">All issues</option>
                            <option value="metadata">Metadata completeness</option>
                            <option value="canonical">Canonical</option>
                            <option value="robots">Robots</option>
                            <option value="indexability">Indexability</option>
                            <option value="social">Social previews</option>
                            <option value="schema">Schema consistency</option>
                            <option value="sitemap">Sitemap eligibility</option>
                            <option value="growth">Growth blockers</option>
                        </select>
                    </label>

                    <label class="ops-control-stack" for="ops-seo-bulk-action">
                        <span class="ops-control-label">Bulk action</span>
                        <select id="ops-seo-bulk-action" wire:model="bulkAction" class="ops-input">
                            <option value="fill_metadata">Fill metadata gaps</option>
                            <option value="sync_canonical">Sync canonical</option>
                            <option value="sync_robots">Sync robots</option>
                            <option value="mark_indexable">Mark indexable</option>
                            <option value="mark_noindex">Mark noindex</option>
                        </select>
                    </label>

                    <div class="ops-control-stack">
                        <span class="ops-control-label">SEO contract</span>
                        <p class="ops-control-hint">This page operates only on metadata fields that already exist in the visible CMS models and authoring workspaces.</p>
                    </div>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        Content Metrics
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">
                        Growth Attribution
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        Content Search
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        Editorial Ops
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            Editorial Review
                        </x-filament::button>
                    @endif
                    @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                        <x-filament::button color="gray" type="button" wire:click="runMonthlyPatrol">
                            Run Monthly Patrol
                        </x-filament::button>
                        <x-filament::button color="primary" type="button" wire:click="applyBulkAction">
                            Apply SEO Action
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="SEO readiness"
            description="Headline coverage across selected-org articles and global career content."
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Coverage details"
            description="Canonical, social, and robots coverage using the current SEO metadata tables."
        >
            <x-filament-ops::ops-field-grid :fields="$coverageFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Growth diagnostics"
            description="Discovery readiness and growth blockers derived from the current public content contract."
        >
            <x-filament-ops::ops-field-grid :fields="$growthFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Monthly patrol"
            description="Track the latest cannibalization report, data citation QA backlog, and sitemap / canonical / schema consistency patrol."
        >
            <x-filament-ops::ops-field-grid :fields="$monthlyPatrolFields" />

            @if (count($monthlyPatrolFindings) > 0)
                <div class="ops-card-list">
                    @foreach ($monthlyPatrolFindings as $finding)
                        <x-filament-ops::ops-result-card
                            :title="(string) ($finding['title'] ?? $finding['primary_query'] ?? $finding['kind'] ?? 'Finding')"
                            :meta="strtoupper((string) ($finding['kind'] ?? 'patrol'))"
                        >
                            <p class="ops-control-hint">
                                {{ (string) ($finding['summary'] ?? $finding['message'] ?? implode(', ', (array) ($finding['issue_labels'] ?? []))) }}
                            </p>
                        </x-filament-ops::ops-result-card>
                    @endforeach
                </div>
            @endif
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Attention queue"
            description="Use these cards to identify which visible content surfaces still need SEO cleanup."
        >
            <div class="ops-card-list">
                @foreach ($attentionCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Latest record: {{ $card['latest_title'] }}</p>
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
            title="SEO issue queue"
            description="Operational queue for metadata completeness, canonical, robots, indexability, and growth blockers."
        >
            <div class="ops-control-stack">
                <span class="ops-control-label">Query latency</span>
                <p class="ops-control-hint">{{ $issueQueueElapsedMs }} ms across the visible SEO issue queue.</p>
            </div>

            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            @if (\App\Filament\Ops\Support\ContentAccess::canWrite())
                                <th>Select</th>
                            @endif
                            <th>Record</th>
                            <th>Scope</th>
                            <th>Issues</th>
                            <th>Growth signal</th>
                            <th>Actions</th>
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
                                            Open
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
                                    <span class="ops-control-hint">No SEO issues match the current filters.</span>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
