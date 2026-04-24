<x-filament-panels::page>
    @php
        $statusLabel = static fn (?string $state): string => \App\Filament\Ops\Support\StatusBadge::label($state);
        $compactStatusLabel = static fn (?string $state): string => app()->getLocale() === 'zh_CN'
            ? \App\Filament\Ops\Support\StatusBadge::label($state)
            : (string) $state;
        $emptyValue = static fn (array $values): string => implode(', ', $values) ?: (string) __('ops.translation_ops.fields.none');
    @endphp

    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.translation_ops.eyebrow')"
            :title="__('ops.translation_ops.heading')"
            :description="__('ops.translation_ops.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.authority_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.translation_ops.authority_revision') }}</p>
                    <p class="ops-control-hint">{{ __('ops.translation_ops.authority_provider') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" type="button" wire:click="resetFilters">
                        {{ __('ops.translation_ops.reset_filters') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.health_title')" :description="__('ops.translation_ops.health_description')">
            <x-filament-ops::ops-field-grid
                :fields="[
                    ['label' => __('ops.translation_ops.metrics.translation_groups'), 'value' => (string) ($metrics['translation_groups'] ?? 0), 'state' => 'info'],
                    ['label' => __('ops.translation_ops.metrics.stale_groups'), 'value' => (string) ($metrics['stale_groups'] ?? 0), 'state' => (($metrics['stale_groups'] ?? 0) > 0 ? 'warning' : 'success')],
                    ['label' => __('ops.translation_ops.metrics.published_groups'), 'value' => (string) ($metrics['published_groups'] ?? 0), 'state' => 'success'],
                    ['label' => __('ops.translation_ops.metrics.missing_target_locale'), 'value' => (string) ($metrics['missing_target_locale'] ?? 0), 'state' => (($metrics['missing_target_locale'] ?? 0) > 0 ? 'warning' : 'success')],
                    ['label' => __('ops.translation_ops.metrics.ownership_mismatch_groups'), 'value' => (string) ($metrics['ownership_mismatch_groups'] ?? 0), 'state' => (($metrics['ownership_mismatch_groups'] ?? 0) > 0 ? 'failed' : 'success')],
                    ['label' => __('ops.translation_ops.metrics.canonical_risk_groups'), 'value' => (string) ($metrics['canonical_risk_groups'] ?? 0), 'state' => (($metrics['canonical_risk_groups'] ?? 0) > 0 ? 'failed' : 'success')],
                ]"
            />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.filters_title')" :description="__('ops.translation_ops.filters_description')">
            <div class="ops-toolbar-grid">
                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.content_type') }}</span>
                    <select class="fi-select-input" wire:model.live="contentTypeFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_content_types') }}</option>
                        @foreach (($filterOptions['content_types'] ?? []) as $type)
                            <option value="{{ $type }}">{{ __('ops.translation_ops.content_types.'.$type) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.slug') }}</span>
                    <input class="fi-input" type="search" wire:model.live.debounce.350ms="slugSearch" placeholder="{{ __('ops.translation_ops.filters.search_slug') }}" />
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.source_locale') }}</span>
                    <select class="fi-select-input" wire:model.live="sourceLocaleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_source_locales') }}</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.target_locale') }}</span>
                    <select class="fi-select-input" wire:model.live="targetLocaleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_target_locales') }}</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.translation_status') }}</span>
                    <select class="fi-select-input" wire:model.live="translationStatusFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_statuses') }}</option>
                        @foreach (($filterOptions['statuses'] ?? []) as $status)
                            <option value="{{ $status }}">{{ $statusLabel($status) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.freshness') }}</span>
                    <select class="fi-select-input" wire:model.live="staleFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_freshness') }}</option>
                        <option value="stale">{{ __('ops.translation_ops.filters.stale_only') }}</option>
                        <option value="current">{{ __('ops.translation_ops.filters.current_only') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.publication') }}</span>
                    <select class="fi-select-input" wire:model.live="publishedFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_publication_states') }}</option>
                        <option value="published">{{ __('ops.translation_ops.filters.has_published_locale') }}</option>
                        <option value="unpublished">{{ __('ops.translation_ops.filters.no_published_locale') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.ownership') }}</span>
                    <select class="fi-select-input" wire:model.live="ownershipFilter">
                        <option value="all">{{ __('ops.translation_ops.filters.all_ownership') }}</option>
                        <option value="mismatch">{{ __('ops.translation_ops.filters.mismatch_only') }}</option>
                        <option value="ok">{{ __('ops.translation_ops.filters.ok_only') }}</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.translation_ops.filters.missing_locale') }}</span>
                    <span class="ops-toolbar-inline">
                        <input type="checkbox" wire:model.live="missingLocaleFilter" />
                        <span class="ops-control-hint">{{ __('ops.translation_ops.filters.missing_locale_hint') }}</span>
                    </span>
                </label>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section :title="__('ops.translation_ops.groups_title')" :description="__('ops.translation_ops.groups_description')">
            <x-filament-ops::ops-table
                :has-rows="count($groups) > 0"
                :empty-title="__('ops.translation_ops.empty_title')"
                :empty-description="__('ops.translation_ops.empty_description')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __('ops.translation_ops.table.content_type') }}</th>
                        <th>{{ __('ops.translation_ops.table.group') }}</th>
                        <th>{{ __('ops.translation_ops.table.source') }}</th>
                        <th>{{ __('ops.translation_ops.table.locales') }}</th>
                        <th>{{ __('ops.translation_ops.table.published') }}</th>
                        <th>{{ __('ops.translation_ops.table.health') }}</th>
                        <th>{{ __('ops.translation_ops.table.actions') }}</th>
                    </tr>
                </x-slot>

                @foreach ($groups as $group)
                    <tr wire:key="translation-group-{{ $group['group_key'] }}">
                        <td>{{ $group['content_type_label'] }}</td>
                        <td>
                            <strong>{{ $group['slug'] }}</strong>
                            <p class="ops-control-hint">{{ $group['translation_group_id'] }}</p>
                            <p class="ops-control-hint">{{ __('ops.translation_ops.fields.hash') }} {{ \Illuminate\Support\Str::limit((string) ($group['latest_source_hash'] ?? __('ops.status.missing')), 12, '') }}</p>
                        </td>
                        <td>
                            <x-filament.ops.shared.status-pill :state="$group['source_status']" :label="$group['source_locale'].' #'.$group['source_record_id']" />
                            <p class="ops-control-hint">{{ $compactStatusLabel($group['source_status']) }}</p>
                        </td>
                        <td>
                            <div class="ops-toolbar-inline">
                                @foreach (($group['locales'] ?? []) as $locale)
                                    <x-filament.ops.shared.status-pill
                                        :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                        :label="$locale['locale'].' '.$compactStatusLabel($locale['translation_status']).($locale['is_stale'] ? ' '.$compactStatusLabel('stale') : '')"
                                    />
                                @endforeach
                            </div>
                        </td>
                        <td>{{ $emptyValue($group['published_locales'] ?? []) }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament.ops.shared.status-pill :state="$group['ownership_ok'] ? 'success' : 'failed'" :label="$group['ownership_ok'] ? __('ops.translation_ops.health.ownership_ok') : __('ops.translation_ops.health.ownership_mismatch')" />
                                <x-filament.ops.shared.status-pill :state="$group['canonical_ok'] ? 'success' : 'failed'" :label="$group['canonical_ok'] ? __('ops.translation_ops.health.canonical_ok') : __('ops.translation_ops.health.canonical_risk')" />
                            </div>
                            @foreach (($group['alerts'] ?? []) as $alert)
                                <p class="ops-control-hint">{{ $alert['label'] }}</p>
                            @endforeach
                        </td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" type="button" wire:click="inspectGroup('{{ $group['group_key'] }}')">
                                    {{ __('ops.translation_ops.actions.inspect') }}
                                </x-filament::button>
                                @if ($group['source_edit_url'])
                                    <x-filament::button size="xs" color="gray" tag="a" href="{{ $group['source_edit_url'] }}">
                                        {{ __('ops.translation_ops.actions.source') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>

        @if ($selectedGroup)
            <x-filament-ops::ops-section
                :title="__('ops.translation_ops.inspect_title', ['slug' => $selectedGroup['slug']])"
                :description="__('ops.translation_ops.inspect_description')"
            >
                <x-filament-ops::ops-field-grid
                    :fields="[
                        ['label' => __('ops.translation_ops.fields.content_type'), 'value' => (string) $selectedGroup['content_type_label'], 'state' => 'info'],
                        ['label' => __('ops.translation_ops.fields.translation_group'), 'value' => (string) $selectedGroup['translation_group_id'], 'state' => 'info'],
                        ['label' => __('ops.translation_ops.fields.source_row'), 'value' => $selectedGroup['source_locale'].' #'.$selectedGroup['source_record_id'], 'state' => $selectedGroup['canonical_ok'] ? 'success' : 'failed'],
                        ['label' => __('ops.translation_ops.fields.published_locales'), 'value' => $emptyValue($selectedGroup['published_locales'] ?? []), 'state' => 'success'],
                        ['label' => __('ops.translation_ops.fields.stale_locales'), 'value' => (string) $selectedGroup['stale_locales_count'], 'state' => $selectedGroup['stale_locales_count'] > 0 ? 'warning' : 'success'],
                        ['label' => __('ops.translation_ops.fields.ownership'), 'value' => $selectedGroup['ownership_ok'] ? __('ops.translation_ops.health.ok') : implode(', ', $selectedGroup['ownership_issues']), 'state' => $selectedGroup['ownership_ok'] ? 'success' : 'failed'],
                    ]"
                />

                <div class="ops-toolbar-inline">
                    @foreach (($selectedGroup['group_actions'] ?? []) as $action)
                        @if (($action['url'] ?? null) && ($action['enabled'] ?? false))
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $action['url'] }}">
                                {{ $action['label'] }}
                            </x-filament::button>
                        @elseif (($action['wire_action'] ?? null) && ($action['enabled'] ?? false))
                            <x-filament::button
                                size="xs"
                                color="primary"
                                type="button"
                                wire:click="{{ $action['wire_action'] }}(@js($action['content_type']), {{ (int) $action['record_id'] }}, @js($action['target_locale'] ?? ''))"
                            >
                                {{ $action['label'] }}
                            </x-filament::button>
                        @else
                            <span class="ops-control-hint">{{ __('ops.translation_ops.actions.disabled', ['action' => $action['label'], 'reason' => $action['reason']]) }}</span>
                        @endif
                    @endforeach
                </div>

                <x-filament-ops::ops-field-grid
                    :fields="[
                        ['label' => __('ops.translation_ops.fields.target_locales'), 'value' => $emptyValue($selectedGroup['coverage']['target_locales'] ?? []), 'state' => 'info'],
                        ['label' => __('ops.translation_ops.fields.existing_locales'), 'value' => $emptyValue($selectedGroup['coverage']['existing_locales'] ?? []), 'state' => 'info'],
                        ['label' => __('ops.translation_ops.fields.published_locales'), 'value' => $emptyValue($selectedGroup['coverage']['published_locales'] ?? []), 'state' => 'success'],
                        ['label' => __('ops.translation_ops.fields.machine_draft_locales'), 'value' => $emptyValue($selectedGroup['coverage']['machine_draft_locales'] ?? []), 'state' => 'info'],
                        ['label' => __('ops.translation_ops.fields.human_review_locales'), 'value' => $emptyValue($selectedGroup['coverage']['human_review_locales'] ?? []), 'state' => 'warning'],
                        ['label' => __('ops.translation_ops.fields.stale_locales'), 'value' => $emptyValue($selectedGroup['coverage']['stale_locales'] ?? []), 'state' => empty($selectedGroup['coverage']['stale_locales'] ?? []) ? 'success' : 'warning'],
                        ['label' => __('ops.translation_ops.fields.missing_target_locales'), 'value' => $emptyValue($selectedGroup['coverage']['missing_target_locales'] ?? []), 'state' => empty($selectedGroup['coverage']['missing_target_locales'] ?? []) ? 'success' : 'warning'],
                    ]"
                />

                <div class="ops-table-shell">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>{{ __('ops.translation_ops.table.locale_row') }}</th>
                                <th>{{ __('ops.translation_ops.table.status') }}</th>
                                <th>{{ __('ops.translation_ops.table.workflow') }}</th>
                                <th>{{ __('ops.translation_ops.table.version_match') }}</th>
                                <th>{{ __('ops.translation_ops.table.ownership_preflight') }}</th>
                                <th>{{ __('ops.translation_ops.table.entry_points') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($selectedGroup['locales'] ?? []) as $locale)
                                <tr wire:key="translation-locale-{{ $selectedGroup['group_key'] }}-{{ $locale['record_id'] }}">
                                    <td>
                                        <strong>{{ $locale['locale'] }} #{{ $locale['record_id'] }}</strong>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.source_locale') }} {{ $locale['source_locale'] }}</p>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.source_record_id') }} {{ $locale['source_record_id'] ?? __('ops.status.source') }}</p>
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill
                                            :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                            :label="$compactStatusLabel($locale['translation_status']).($locale['is_stale'] ? ' '.$compactStatusLabel('stale') : '')"
                                        />
                                        <p class="ops-control-hint">{{ $compactStatusLabel($locale['record_status']) }} / {{ __('ops.translation_ops.fields.public') }} {{ $locale['is_public'] ? __('ops.translation_ops.fields.yes') : __('ops.translation_ops.fields.no') }}</p>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.published_at') }} {{ $locale['published_at'] ?? __('ops.translation_ops.fields.null') }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">{{ $locale['workflow_kind_label'] ?? $locale['workflow_kind'] }} {{ __('ops.translation_ops.fields.workflow') }}</p>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.working_revision') }} {{ $locale['working_revision_id'] ?? __('ops.translation_ops.fields.not_available') }}</p>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.published_revision') }} {{ $locale['published_revision_id'] ?? __('ops.translation_ops.fields.not_available') }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.source_hash') }} {{ \Illuminate\Support\Str::limit((string) ($locale['source_version_hash'] ?? __('ops.status.missing')), 12, '') }}</p>
                                        <p class="ops-control-hint">{{ __('ops.translation_ops.fields.translated_from') }} {{ \Illuminate\Support\Str::limit((string) ($locale['translated_from_version_hash'] ?? __('ops.status.missing')), 12, '') }}</p>
                                        @foreach (($locale['compare_summary'] ?? []) as $line)
                                            <p class="ops-control-hint">{{ $line }}</p>
                                        @endforeach
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill :state="$locale['ownership_ok'] ? 'success' : 'failed'" :label="$locale['ownership_ok'] ? __('ops.translation_ops.health.ok') : __('ops.translation_ops.health.mismatch')" />
                                        @foreach (($locale['ownership_issues'] ?? []) as $issue)
                                            <p class="ops-control-hint">{{ $issue }}</p>
                                        @endforeach
                                        <x-filament.ops.shared.status-pill
                                            :state="($locale['preflight']['ok'] ?? true) ? 'success' : 'failed'"
                                            :label="($locale['preflight']['ok'] ?? true) ? __('ops.translation_ops.health.preflight_ok') : __('ops.translation_ops.health.preflight_blocked')"
                                        />
                                        @foreach (($locale['preflight']['blockers'] ?? []) as $blocker)
                                            <p class="ops-control-hint">{{ $blocker }}</p>
                                        @endforeach
                                    </td>
                                    <td>
                                        <div class="ops-control-stack">
                                            @foreach (($locale['actions'] ?? []) as $action)
                                                @if (($action['url'] ?? null) && ($action['enabled'] ?? false))
                                                    <x-filament::button size="xs" color="gray" tag="a" href="{{ $action['url'] }}">
                                                        {{ $action['label'] }}
                                                    </x-filament::button>
                                                @elseif (($action['wire_action'] ?? null) && ($action['enabled'] ?? false))
                                                    <x-filament::button
                                                        size="xs"
                                                        color="primary"
                                                        type="button"
                                                        wire:click="{{ $action['wire_action'] }}(@js($action['content_type']), {{ (int) $action['record_id'] }}, @js($action['target_locale'] ?? ''))"
                                                    >
                                                        {{ $action['label'] }}
                                                    </x-filament::button>
                                                @else
                                                    <span class="ops-control-hint">{{ __('ops.translation_ops.actions.disabled', ['action' => $action['label'], 'reason' => $action['reason']]) }}</span>
                                                @endif
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
