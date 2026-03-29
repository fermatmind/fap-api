<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Editorial review"
            title="Editorial review"
            description="Review draft editorial records against a lightweight readiness checklist before handing them into the publish queue."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Approval boundary</span>
                    <p class="ops-control-hint"><code>content_release</code> remains the approval boundary. This page now persists lightweight approval decisions in audit logs without introducing a workflow engine or schema change.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        Editorial Ops
                    </x-filament::button>
                    <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                        Release Queue
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Review snapshot"
            description="Track the current draft review inventory and isolate items that still need editorial cleanup."
        >
            <x-filament-ops::ops-field-grid :fields="$reviewFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Review queue"
            description="The checklist is intentionally lightweight: content completeness plus SEO delivery readiness using the current public contract."
        >
            <x-filament-ops::ops-toolbar :split="false">
                <div class="ops-control-stack">
                    <span class="ops-control-label">Filters</span>
                    <p class="ops-control-hint">Focus the review queue by content type or readiness state without leaving the page.</p>
                </div>

                <x-slot name="actions">
                    <div class="ops-toolbar-inline">
                        <label class="ops-control-stack" for="editorial-review-type">
                            <span class="ops-control-label">Type</span>
                            <select id="editorial-review-type" wire:model.live="typeFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="article">Article</option>
                                <option value="guide">Career Guide</option>
                                <option value="job">Career Job</option>
                            </select>
                        </label>

                        <label class="ops-control-stack" for="editorial-review-state">
                            <span class="ops-control-label">Review state</span>
                            <select id="editorial-review-state" wire:model.live="reviewStateFilter" class="ops-input">
                                <option value="all">All</option>
                                <option value="ready">Ready</option>
                                <option value="approved">Approved</option>
                                <option value="changes_requested">Changes requested</option>
                                <option value="rejected">Rejected</option>
                                <option value="needs_attention">Needs attention</option>
                            </select>
                        </label>
                    </div>
                </x-slot>
            </x-filament-ops::ops-toolbar>

            <x-filament-ops::ops-table
                :has-rows="count($reviewItems) > 0"
                empty-eyebrow="Editorial review"
                empty-title="No draft records match the current review filter"
                empty-description="Adjust the type or review-state filter, or create new draft content from the editorial operations surface."
            >
                <x-slot name="head">
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Review state</th>
                        <th>Checklist</th>
                        <th>Locale</th>
                        <th>Updated</th>
                        <th>Actions</th>
                    </tr>
                </x-slot>

                @foreach ($reviewItems as $item)
                    <tr wire:key="editorial-review-row-{{ $item['type'] }}-{{ md5($item['title'].$item['updated_at']) }}">
                        <td>{{ $item['type_label'] }}</td>
                        <td>{{ $item['title'] }}</td>
                        <td class="ops-table__status">
                            <x-filament.ops.shared.status-pill
                                :state="match ($item['review_state']) { 'approved' => 'success', 'ready' => 'info', 'changes_requested' => 'warning', 'rejected' => 'danger', default => 'warning' }"
                                :label="\App\Filament\Ops\Support\EditorialReviewAudit::label($item['review_state'])"
                            />
                        </td>
                        <td>{{ $item['checklist_label'] }}</td>
                        <td>{{ $item['locale'] }}</td>
                        <td>{{ $item['updated_at'] }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" tag="a" href="{{ $item['edit_url'] }}">
                                    Open
                                </x-filament::button>
                                @if ($item['review_state'] === 'ready' || $item['review_state'] === 'changes_requested')
                                    <x-filament::button size="xs" color="success" type="button" wire:click="approveItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                        Approve
                                    </x-filament::button>
                                @endif
                                @if ($item['review_state'] !== 'rejected')
                                    <x-filament::button size="xs" color="warning" type="button" wire:click="requestChangesItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                        Send Back
                                    </x-filament::button>
                                    <x-filament::button size="xs" color="danger" type="button" wire:click="rejectItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                        Reject
                                    </x-filament::button>
                                @endif
                                @if ($item['review_state'] === 'approved')
                                    <x-filament::button size="xs" color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                                        Release Queue
                                    </x-filament::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
