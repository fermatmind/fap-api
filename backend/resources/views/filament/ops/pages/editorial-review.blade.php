<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.editorial_review.eyebrow')"
            :title="__('ops.custom_pages.editorial_review.title')"
            :description="__('ops.custom_pages.editorial_review.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.editorial_review.approval_boundary') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.editorial_review.approval_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_ops') }}
                    </x-filament::button>
                    <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.release_queue') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.editorial_review.snapshot_title')"
            :description="__('ops.custom_pages.editorial_review.snapshot_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$reviewFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.editorial_review.queue_title')"
            :description="__('ops.custom_pages.editorial_review.queue_desc')"
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.editorial_review.filters_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.editorial_review.filters_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <label class="ops-control-stack" for="editorial-review-type">
                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.type') }}</span>
                            <select id="editorial-review-type" wire:model.live="typeFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="article">{{ __('ops.custom_pages.common.filters.article') }}</option>
                                <option value="guide">{{ __('ops.custom_pages.common.filters.career_guide') }}</option>
                                <option value="job">{{ __('ops.custom_pages.common.filters.career_job') }}</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="editorial-review-state">
                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.review_state') }}</span>
                            <select id="editorial-review-state" wire:model.live="reviewStateFilter" class="ops-input">
                                <option value="all">{{ __('ops.custom_pages.common.filters.all') }}</option>
                                <option value="ready">{{ __('ops.custom_pages.common.filters.ready') }}</option>
                                <option value="in_review">{{ __('ops.custom_pages.common.filters.in_review') }}</option>
                                <option value="approved">{{ __('ops.custom_pages.common.filters.approved') }}</option>
                                <option value="changes_requested">{{ __('ops.custom_pages.common.filters.changes_requested') }}</option>
                                <option value="rejected">{{ __('ops.custom_pages.common.filters.rejected') }}</option>
                                <option value="needs_attention">{{ __('ops.custom_pages.common.filters.needs_attention') }}</option>
                            </select>
                        </label>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>

            <x-filament-ops::ops-table
                :has-rows="count($reviewItems) > 0"
                :empty-eyebrow="__('ops.custom_pages.editorial_review.eyebrow')"
                :empty-title="__('ops.custom_pages.editorial_review.empty_title')"
                :empty-description="__('ops.custom_pages.editorial_review.empty_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __('ops.custom_pages.common.table.type') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.title') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.review_state') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.owner') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.reviewer') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.checklist') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.locale') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.updated') }}</th>
                        <th>{{ __('ops.custom_pages.common.table.actions') }}</th>
                    </tr>
                </x-slot>

                @foreach ($reviewItems as $item)
                    <tr wire:key="editorial-review-row-{{ $item['type'] }}-{{ md5($item['title'].$item['updated_at']) }}">
                        <td>{{ $item['type_label'] }}</td>
                        <td>{{ $item['title'] }}</td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="match ($item['review_state']) { 'approved' => 'success', 'ready' => 'info', 'in_review' => 'info', 'changes_requested' => 'warning', 'rejected' => 'danger', default => 'warning' }"
                                :label="\App\Filament\Ops\Support\EditorialReviewAudit::label($item['review_state'])"
                            />
                        </td>
                        <td>{{ $item['owner_label'] }}</td>
                        <td>{{ $item['reviewer_label'] }}</td>
                        <td>{{ $item['checklist_label'] }}</td>
                        <td>{{ $item['locale'] }}</td>
                        <td>{{ $item['updated_at'] }}</td>
                        <td>
                            <div class="ops-control-stack">
                                <div class="ops-toolbar-inline">
                                    <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['edit_url'] }}">
                                        {{ __('ops.custom_pages.common.actions.open') }}
                                    </x-filament::button>
                                    @if ($item['review_state'] === 'approved')
                                        <x-filament::button size="xs" color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                                            {{ __('ops.custom_pages.common.nav.release_queue') }}
                                        </x-filament::button>
                                    @endif
                                </div>

                                <div class="ops-toolbar-inline">
                                    @if ($item['can_assign_owner'])
                                        <label class="ops-control-stack" for="owner-{{ $item['workflow_key'] }}">
                                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.owner') }}</span>
                                            <select id="owner-{{ $item['workflow_key'] }}" wire:model.live="ownerAssignments.{{ $item['workflow_key'] }}" class="ops-input">
                                                <option value="">{{ __('ops.custom_pages.editorial_review.select_owner') }}</option>
                                                @foreach ($ownerOptions as $adminId => $label)
                                                    <option value="{{ $adminId }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <x-filament::button size="xs" color="gray" type="button" wire:click="assignOwnerItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.save_owner') }}
                                        </x-filament::button>
                                    @endif

                                    @if ($item['can_assign_reviewer'])
                                        <label class="ops-control-stack" for="reviewer-{{ $item['workflow_key'] }}">
                                            <span class="ops-control-label">{{ __('ops.custom_pages.common.table.reviewer') }}</span>
                                            <select id="reviewer-{{ $item['workflow_key'] }}" wire:model.live="reviewerAssignments.{{ $item['workflow_key'] }}" class="ops-input">
                                                <option value="">{{ __('ops.custom_pages.editorial_review.select_reviewer') }}</option>
                                                @foreach ($reviewerOptions as $adminId => $label)
                                                    <option value="{{ $adminId }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <x-filament::button size="xs" color="gray" type="button" wire:click="assignReviewerItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.save_reviewer') }}
                                        </x-filament::button>
                                    @endif
                                </div>

                                <div class="ops-toolbar-inline">
                                    @if ($item['can_submit'])
                                        <x-filament::button size="xs" color="info" type="button" wire:click="submitItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.submit') }}
                                        </x-filament::button>
                                    @endif
                                    @if ($item['can_decide'])
                                        <x-filament::button size="xs" color="success" type="button" wire:click="approveItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.approve') }}
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="warning" type="button" wire:click="requestChangesItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.send_back') }}
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="danger" type="button" wire:click="rejectItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            {{ __('ops.custom_pages.common.actions.reject') }}
                                        </x-filament::button>
                                    @endif
                                    @if (! $item['can_submit'] && ! $item['can_decide'] && $item['review_state'] !== 'approved')
                                        <span class="ops-control-hint">{{ __('ops.custom_pages.editorial_review.assign_hint') }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
