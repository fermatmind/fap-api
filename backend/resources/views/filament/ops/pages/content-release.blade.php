<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Content release"
            title="Content release"
            description="Review draft content, apply the release permission boundary, and promote approved records without leaving the Ops CMS layer."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Release review</span>
                    <p class="ops-control-hint">This workspace is the lightweight review surface for Phase 1. There is no workflow engine yet, so <code>content_release</code> is the approval boundary.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        Workspace
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release flow"
            description="Track draft and published inventory, then audit the active filter scope before releasing records."
        >
            <x-filament-ops::ops-field-grid :fields="$releaseFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release workspace"
            description="Filter draft content by type and state, then release directly from this queue or jump into the full resource editor."
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
                        <th>Locale</th>
                        <th>Visibility</th>
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
                        <td>{{ $item['locale'] }}</td>
                        <td>{{ $item['visibility'] }}</td>
                        <td>{{ $item['updated_at'] }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['edit_url'] }}">
                                    Open
                                </x-filament::button>

                                @if ($item['releaseable'])
                                    <x-filament::button
                                        size="xs"
                                        color="primary"
                                        type="button"
                                        wire:click="releaseItem('{{ $item['type'] }}', {{ $item['id'] }})"
                                    >
                                        Release
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="xs" color="success" disabled>
                                        Released
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
