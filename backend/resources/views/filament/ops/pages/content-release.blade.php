<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Content release"
            title="Content release"
            description="Review draft content, apply the release permission boundary, and publish approved records without leaving the Ops CMS layer."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Release review</span>
                    <p class="ops-control-hint">Only records that have completed owner assignment, reviewer assignment, formal submission, and approval can publish from this queue.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                        Editorial Review
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\PostReleaseObservabilityPage::getUrl() }}">
                        Observability
                    </x-filament::button>
                    <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        Workspace
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release flow"
            description="Track draft and published inventory, then audit the active filter scope before publishing records."
        >
            <x-filament-ops::ops-field-grid :fields="$releaseFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release workspace"
            description="Filter draft content by type and state, then publish directly from this queue or jump into the full resource editor."
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">Filters</span>
                    <p class="ops-control-hint">Default view stays on draft items so release operators can review what is still pending.</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <label class="ops-control-stack" for="content-release-type">
                            <span class="ops-control-label">Type</span>
                            <select id="content-release-type" wire:model.live="typeFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="article">Article</option>
                                <option value="guide">Career Guide</option>
                                <option value="job">Career Job</option>
                                <option value="method">Method</option>
                                <option value="data">Data</option>
                                <option value="personality">Personality</option>
                                <option value="topic">Topic</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="content-release-status">
                            <span class="ops-control-label">Status</span>
                            <select id="content-release-status" wire:model.live="statusFilter" class="ops-input">
                                <option value="draft">Draft</option>
                                <option value="published">Published</option>
                                <option value="all">All</option>
                            </select>
                        </label>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>

            <x-filament-ops::ops-table
                :has-rows="count($releaseItems) > 0"
                empty-eyebrow="Release queue"
                empty-title="No content matches the current filter"
                empty-description="Switch the type or status filter to widen the queue, or create new draft content from the workspace."
            >
                <x-slot name="head">
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Review state</th>
                        <th>Locale</th>
                        <th>Visibility</th>
                        <th>Citation QA</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </x-slot>

                @foreach ($releaseItems as $item)
                    <tr wire:key="content-release-row-{{ $item['type'] }}-{{ $item['id'] }}">
                        <td>{{ $item['type_label'] }}</td>
                        <td>
                            <div class="ops-control-stack">
                                <span class="ops-control-label">{{ $item['title'] }}</span>
                                <span class="ops-control-hint">{{ $item['type_label'] }} release candidate</span>
                            </div>
                        </td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="$item['status'] === 'published' ? 'success' : 'warning'"
                                :label="ucfirst($item['status'])"
                            />
                        </td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="match ($item['review_state']) { 'approved' => 'success', 'ready' => 'info', 'in_review' => 'info', 'changes_requested' => 'warning', 'rejected' => 'danger', default => 'warning' }"
                                :label="\App\Filament\Ops\Support\EditorialReviewAudit::label($item['review_state'])"
                            />
                        </td>
                        <td>{{ $item['locale'] }}</td>
                        <td>{{ $item['visibility'] }}</td>
                        <td class="ops-table__status">
                            @if ($item['type'] === 'data')
                                <div class="ops-control-stack">
                                    <x-filament.ops.shared.status-pill
                                        :state="(string) ($item['citation_qa']['state'] ?? 'warning')"
                                        :label="(string) ($item['citation_qa']['label'] ?? 'Missing')"
                                    />
                                    <span class="ops-control-hint">
                                        {{ (string) ($item['citation_qa_summary'] ?? 'Run Citation QA before release.') }}
                                    </span>
                                    @if (!empty($item['citation_qa_audited_at']))
                                        <span class="ops-control-hint">Last run: {{ $item['citation_qa_audited_at'] }}</span>
                                    @endif
                                </div>
                            @else
                                <span class="ops-control-hint">N/A</span>
                            @endif
                        </td>
                        <td>{{ $item['updated_at'] }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['edit_url'] }}">
                                    Open
                                </x-filament::button>

                                @if ($item['type'] === 'data')
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        type="button"
                                        wire:click="runCitationQa({{ $item['id'] }})"
                                    >
                                        Run Citation QA
                                    </x-filament::button>
                                @endif

                                @if ($item['releaseable'])
                                    <x-filament::button
                                        size="xs"
                                        color="primary"
                                        type="button"
                                        wire:click="releaseItem('{{ $item['type'] }}', {{ $item['id'] }})"
                                    >
                                        Publish
                                    </x-filament::button>
                                @elseif ($item['status'] === 'draft')
                                    <x-filament::button size="xs" color="warning" disabled>
                                        {{ $item['type'] === 'data' && empty($item['citation_qa']['passed']) ? 'Needs Citation QA' : 'Needs Workflow' }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="xs" color="success" disabled>
                                        Published
                                    </x-filament::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Resource surfaces"
            description="Jump into the full resource workspaces for deeper editing, taxonomy cleanup, or post-release review."
        >
            <div class="ops-card-list">
                @foreach ($surfaceCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>

                        <x-slot name="actions">
                            <x-filament::button size="xs" color="primary" tag="a" href="{{ $card['index_url'] }}">
                                Open
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
