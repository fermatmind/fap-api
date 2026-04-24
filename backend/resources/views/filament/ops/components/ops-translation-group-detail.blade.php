@props([
    'group' => null,
])

@php
    $emptyValue = static fn (array $values): string => implode(', ', $values) ?: (string) __('ops.translation_ops.fields.none');
@endphp

@if ($group)
    <div {{ $attributes->class(['ops-translation-detail']) }}>
        <x-filament-ops::ops-field-grid
            :fields="[
                ['label' => __('ops.translation_ops.fields.content_type'), 'value' => (string) $group['content_type_label'], 'state' => 'info'],
                ['label' => __('ops.translation_ops.fields.translation_group'), 'value' => (string) $group['translation_group_id'], 'state' => 'info'],
                ['label' => __('ops.translation_ops.fields.source_row'), 'value' => $group['source_locale'].' #'.$group['source_record_id'], 'state' => $group['canonical_ok'] ? 'success' : 'failed'],
                ['label' => __('ops.translation_ops.fields.published_locales'), 'value' => $emptyValue($group['published_locales'] ?? []), 'state' => 'success'],
                ['label' => __('ops.translation_ops.fields.stale_locales'), 'value' => (string) $group['stale_locales_count'], 'state' => $group['stale_locales_count'] > 0 ? 'warning' : 'success'],
                ['label' => __('ops.translation_ops.fields.ownership'), 'value' => $group['ownership_ok'] ? __('ops.translation_ops.health.ok') : implode(', ', $group['ownership_issues']), 'state' => $group['ownership_ok'] ? 'success' : 'failed'],
            ]"
        />

        <x-filament-ops::ops-translation-action-list :actions="['primary' => [], 'secondary' => collect($group['group_actions'] ?? [])->where('enabled', true)->values()->all(), 'disabled' => collect($group['group_actions'] ?? [])->where('enabled', false)->values()->all()]" />

        <x-filament-ops::ops-field-grid
            :fields="[
                ['label' => __('ops.translation_ops.fields.target_locales'), 'value' => $emptyValue($group['coverage']['target_locales'] ?? []), 'state' => 'info'],
                ['label' => __('ops.translation_ops.fields.existing_locales'), 'value' => $emptyValue($group['coverage']['existing_locales'] ?? []), 'state' => 'info'],
                ['label' => __('ops.translation_ops.fields.published_locales'), 'value' => $emptyValue($group['coverage']['published_locales'] ?? []), 'state' => 'success'],
                ['label' => __('ops.translation_ops.fields.machine_draft_locales'), 'value' => $emptyValue($group['coverage']['machine_draft_locales'] ?? []), 'state' => 'info'],
                ['label' => __('ops.translation_ops.fields.human_review_locales'), 'value' => $emptyValue($group['coverage']['human_review_locales'] ?? []), 'state' => 'warning'],
                ['label' => __('ops.translation_ops.fields.stale_locales'), 'value' => $emptyValue($group['coverage']['stale_locales'] ?? []), 'state' => empty($group['coverage']['stale_locales'] ?? []) ? 'success' : 'warning'],
                ['label' => __('ops.translation_ops.fields.missing_target_locales'), 'value' => $emptyValue($group['coverage']['missing_target_locales'] ?? []), 'state' => empty($group['coverage']['missing_target_locales'] ?? []) ? 'success' : 'warning'],
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
                    @foreach (($group['locales'] ?? []) as $locale)
                        <tr wire:key="translation-locale-{{ $group['group_key'] }}-{{ $locale['record_id'] }}">
                            <td>
                                <strong>{{ $locale['locale'] }} #{{ $locale['record_id'] }}</strong>
                                <p class="ops-control-hint">{{ __('ops.translation_ops.fields.source_locale') }} {{ $locale['source_locale'] }}</p>
                                <p class="ops-control-hint">{{ __('ops.translation_ops.fields.source_record_id') }} {{ $locale['source_record_id'] ?? __('ops.status.source') }}</p>
                            </td>
                            <td>
                                <x-filament.ops.shared.status-pill
                                    :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                    :label="\App\Filament\Ops\Support\StatusBadge::label($locale['translation_status']).($locale['is_stale'] ? ' '.\App\Filament\Ops\Support\StatusBadge::label('stale') : '')"
                                />
                                <p class="ops-control-hint">{{ \App\Filament\Ops\Support\StatusBadge::label($locale['record_status']) }} / {{ __('ops.translation_ops.fields.public') }} {{ $locale['is_public'] ? __('ops.translation_ops.fields.yes') : __('ops.translation_ops.fields.no') }}</p>
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
                                <x-filament-ops::ops-translation-action-list :actions="[
                                    'primary' => collect($locale['actions'] ?? [])->where('enabled', true)->whereIn('wire_action', ['createTranslationDraft', 'resyncFromSource', 'publishCurrentRevision'])->values()->all(),
                                    'secondary' => collect($locale['actions'] ?? [])->where('enabled', true)->reject(fn ($action) => in_array($action['wire_action'] ?? '', ['createTranslationDraft', 'resyncFromSource', 'publishCurrentRevision'], true))->values()->all(),
                                    'disabled' => collect($locale['actions'] ?? [])->where('enabled', false)->values()->all(),
                                ]" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
