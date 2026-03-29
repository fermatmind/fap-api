<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Editorial review"
            title="Editorial review"
            description="Route draft editorial records through owner assignment, reviewer assignment, explicit submission, and recorded approval decisions before they enter the publish queue."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Approval boundary</span>
                    <p class="ops-control-hint"><code>content_write</code> drives ownership, <code>admin.approval.review</code> or <code>content_release</code> handles formal review, and every transition is persisted into both workflow state and audit logs.</p>
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
                                <option value="in_review">In review</option>
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
                        <th>Owner</th>
                        <th>Reviewer</th>
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
                                        Open
                                    </x-filament::button>
                                    @if ($item['review_state'] === 'approved')
                                        <x-filament::button size="xs" color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                                            Release Queue
                                        </x-filament::button>
                                    @endif
                                </div>

                                <div class="ops-toolbar-inline">
                                    @if ($item['can_assign_owner'])
                                        <label class="ops-control-stack" for="owner-{{ $item['workflow_key'] }}">
                                            <span class="ops-control-label">Owner</span>
                                            <select id="owner-{{ $item['workflow_key'] }}" wire:model.live="ownerAssignments.{{ $item['workflow_key'] }}" class="ops-input">
                                                <option value="">Select owner</option>
                                                @foreach ($ownerOptions as $adminId => $label)
                                                    <option value="{{ $adminId }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <x-filament::button size="xs" color="gray" type="button" wire:click="assignOwnerItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Save Owner
                                        </x-filament::button>
                                    @endif

                                    @if ($item['can_assign_reviewer'])
                                        <label class="ops-control-stack" for="reviewer-{{ $item['workflow_key'] }}">
                                            <span class="ops-control-label">Reviewer</span>
                                            <select id="reviewer-{{ $item['workflow_key'] }}" wire:model.live="reviewerAssignments.{{ $item['workflow_key'] }}" class="ops-input">
                                                <option value="">Select reviewer</option>
                                                @foreach ($reviewerOptions as $adminId => $label)
                                                    <option value="{{ $adminId }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <x-filament::button size="xs" color="gray" type="button" wire:click="assignReviewerItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Save Reviewer
                                        </x-filament::button>
                                    @endif
                                </div>

                                <div class="ops-toolbar-inline">
                                    @if ($item['can_submit'])
                                        <x-filament::button size="xs" color="info" type="button" wire:click="submitItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Submit
                                        </x-filament::button>
                                    @endif
                                    @if ($item['can_decide'])
                                        <x-filament::button size="xs" color="success" type="button" wire:click="approveItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Approve
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="warning" type="button" wire:click="requestChangesItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Send Back
                                        </x-filament::button>
                                        <x-filament::button size="xs" color="danger" type="button" wire:click="rejectItem('{{ $item['type'] }}', {{ $item['id'] }})">
                                            Reject
                                        </x-filament::button>
                                    @endif
                                    @if (! $item['can_submit'] && ! $item['can_decide'] && $item['review_state'] !== 'approved')
                                        <span class="ops-control-hint">Assign owner + reviewer, then submit.</span>
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
