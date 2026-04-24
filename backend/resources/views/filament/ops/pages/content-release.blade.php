<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.content_release.eyebrow')"
            :title="__('ops.custom_pages.content_release.title')"
            :description="__('ops.custom_pages.content_release.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.content_release.review_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_release.review_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_review') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\PostReleaseObservabilityPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.observability') }}
                    </x-filament::button>
                    <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.workspace') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_release.flow_title')"
            :description="__('ops.custom_pages.content_release.flow_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$releaseFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_release.workspace_title')"
            :description="__('ops.custom_pages.content_release.workspace_desc')"
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.editorial_review.filters_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_release.filters_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <label class="ops-control-stack" for="content-release-type">
                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.type') }}</span>
                            <select id="content-release-type" wire:model.live="typeFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="article">{{ __('ops.custom_pages.common.filters.article') }}</option>
                                <option value="guide">{{ __('ops.custom_pages.common.filters.career_guide') }}</option>
                                <option value="job">{{ __('ops.custom_pages.common.filters.career_job') }}</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="content-release-status">
                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.status') }}</span>
                            <select id="content-release-status" wire:model.live="statusFilter" class="ops-input">
                                <option value="draft">{{ __('ops.custom_pages.common.filters.draft') }}</option>
                                <option value="published">{{ __('ops.custom_pages.common.filters.published') }}</option>
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                            </select>
                        </label>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>

            <x-filament-ops::ops-table
                :has-rows="count($releaseItems) > 0"
                :empty-eyebrow="__('ops.custom_pages.common.nav.release_queue')"
                :empty-title="__('ops.custom_pages.content_release.empty_title')"
                :empty-description="__('ops.custom_pages.content_release.empty_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __('ops.custom_pages.common.table.type') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.title') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.status') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.review_state') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.locale') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.visibility') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.updated') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.actions') }}</th>
                    </tr>
                </x-slot>

                @foreach ($releaseItems as $item)
                    <tr wire:key="content-release-row-{{ $item['type'] }}-{{ $item['id'] }}">
                        <td>{{ $item['type_label'] }}</td>
                        <td>
                            <div class="ops-control-stack">
                                <span class="ops-control-label">{{ $item['title'] }}</span>
                                <span class="ops-control-hint">{{ __('ops.custom_pages.content_release.candidate', ['type' => $item['type_label']]) }}</span>
                            </div>
                        </td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="$item['status'] === 'published' ? 'success' : 'warning'"
                                :label="$item['status_label'] ?? $item['status']"
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
                        <td>{{ $item['updated_at'] }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['edit_url'] }}">
                                    {{ __('ops.custom_pages.common.actions.open') }}
                                </x-filament::button>

                                @if ($item['releaseable'])
                                    <x-filament::button
                                        size="xs"
                                        color="primary"
                                        type="button"
                                        wire:click="releaseItem('{{ $item['type'] }}', {{ $item['id'] }})"
                                    >
                                        {{ __('ops.custom_pages.common.actions.publish') }}
                                    </x-filament::button>
                                @elseif ($item['status'] === 'draft')
                                    <x-filament::button size="xs" color="warning" disabled>
                                        {{ __('ops.custom_pages.content_release.needs_workflow') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button size="xs" color="success" disabled>
                                        {{ __('ops.custom_pages.common.filters.published') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_release.resource_surfaces_title')"
            :description="__('ops.custom_pages.content_release.resource_surfaces_desc')"
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
                                {{ __('ops.custom_pages.common.actions.open') }}
                            </x-filament::button>
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
