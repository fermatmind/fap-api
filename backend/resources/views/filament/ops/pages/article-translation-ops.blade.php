<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="CMS"
            title="Unified Translation Ops Console"
            description="Inspect multilingual source and translation ownership, stale state, locale coverage, publish readiness, and safe workflow actions across articles, support articles, interpretation guides, and content pages."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Backend multilingual authority</span>
                    <p class="ops-control-hint">Articles remain revision-backed. Support articles, interpretation guides, and content pages are currently sibling-row backed, so published re-sync is disabled until those editors move to revision-backed authority.</p>
                    <p class="ops-control-hint">Create translation draft disabled when no machine translation provider is configured. Re-sync from source disabled when the target is not stale, provider access is unavailable, or a row-backed published translation would be overwritten.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" type="button" wire:click="resetFilters">
                        Reset filters
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section title="Translation health" description="Cross-type pressure points before multilingual release.">
            <x-filament-ops::ops-field-grid
                :fields="[
                    ['label' => 'Translation groups', 'value' => (string) ($metrics['translation_groups'] ?? 0), 'state' => 'info'],
                    ['label' => 'Stale groups', 'value' => (string) ($metrics['stale_groups'] ?? 0), 'state' => (($metrics['stale_groups'] ?? 0) > 0 ? 'warning' : 'success')],
                    ['label' => 'Published groups', 'value' => (string) ($metrics['published_groups'] ?? 0), 'state' => 'success'],
                    ['label' => 'Missing target locale', 'value' => (string) ($metrics['missing_target_locale'] ?? 0), 'state' => (($metrics['missing_target_locale'] ?? 0) > 0 ? 'warning' : 'success')],
                    ['label' => 'Ownership mismatches', 'value' => (string) ($metrics['ownership_mismatch_groups'] ?? 0), 'state' => (($metrics['ownership_mismatch_groups'] ?? 0) > 0 ? 'failed' : 'success')],
                    ['label' => 'Canonical risks', 'value' => (string) ($metrics['canonical_risk_groups'] ?? 0), 'state' => (($metrics['canonical_risk_groups'] ?? 0) > 0 ? 'failed' : 'success')],
                ]"
            />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section title="Filters" description="Narrow by content type, slug, locale, translation status, freshness, publication, missing locale, or ownership mismatch.">
            <div class="ops-toolbar-grid">
                <label class="ops-control-stack">
                    <span class="ops-control-label">Content type</span>
                    <select class="fi-select-input" wire:model.live="contentTypeFilter">
                        <option value="all">All content types</option>
                        @foreach (($filterOptions['content_types'] ?? []) as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Slug</span>
                    <input class="fi-input" type="search" wire:model.live.debounce.350ms="slugSearch" placeholder="Search slug" />
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Source locale</span>
                    <select class="fi-select-input" wire:model.live="sourceLocaleFilter">
                        <option value="all">All source locales</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Target locale</span>
                    <select class="fi-select-input" wire:model.live="targetLocaleFilter">
                        <option value="all">All target locales</option>
                        @foreach (($filterOptions['locales'] ?? []) as $locale)
                            <option value="{{ $locale }}">{{ $locale }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Translation status</span>
                    <select class="fi-select-input" wire:model.live="translationStatusFilter">
                        <option value="all">All statuses</option>
                        @foreach (($filterOptions['statuses'] ?? []) as $status)
                            <option value="{{ $status }}">{{ $status }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Freshness</span>
                    <select class="fi-select-input" wire:model.live="staleFilter">
                        <option value="all">All freshness</option>
                        <option value="stale">Stale only</option>
                        <option value="current">Current only</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Publication</span>
                    <select class="fi-select-input" wire:model.live="publishedFilter">
                        <option value="all">All publication states</option>
                        <option value="published">Has published locale</option>
                        <option value="unpublished">No published locale</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Ownership</span>
                    <select class="fi-select-input" wire:model.live="ownershipFilter">
                        <option value="all">All ownership</option>
                        <option value="mismatch">Mismatch only</option>
                        <option value="ok">OK only</option>
                    </select>
                </label>

                <label class="ops-control-stack">
                    <span class="ops-control-label">Missing locale</span>
                    <span class="ops-toolbar-inline">
                        <input type="checkbox" wire:model.live="missingLocaleFilter" />
                        <span class="ops-control-hint">Uses the selected target locale.</span>
                    </span>
                </label>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section title="Translation groups" description="Each row is one translation group under one CMS content type.">
            <x-filament-ops::ops-table
                :has-rows="count($groups) > 0"
                empty-title="No translation groups found"
                empty-description="Adjust filters or create translation groups through the existing CMS workflow."
            >
                <x-slot name="head">
                    <tr>
                        <th>Content type</th>
                        <th>Group</th>
                        <th>Source</th>
                        <th>Locales</th>
                        <th>Published</th>
                        <th>Health</th>
                        <th>Actions</th>
                    </tr>
                </x-slot>

                @foreach ($groups as $group)
                    <tr wire:key="translation-group-{{ $group['group_key'] }}">
                        <td>{{ $group['content_type_label'] }}</td>
                        <td>
                            <strong>{{ $group['slug'] }}</strong>
                            <p class="ops-control-hint">{{ $group['translation_group_id'] }}</p>
                            <p class="ops-control-hint">hash {{ \Illuminate\Support\Str::limit((string) ($group['latest_source_hash'] ?? 'missing'), 12, '') }}</p>
                        </td>
                        <td>
                            <x-filament.ops.shared.status-pill :state="$group['source_status']" :label="$group['source_locale'].' #'.$group['source_record_id']" />
                            <p class="ops-control-hint">{{ $group['source_status'] }}</p>
                        </td>
                        <td>
                            <div class="ops-toolbar-inline">
                                @foreach (($group['locales'] ?? []) as $locale)
                                    <x-filament.ops.shared.status-pill
                                        :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                        :label="$locale['locale'].' '.$locale['translation_status'].($locale['is_stale'] ? ' stale' : '')"
                                    />
                                @endforeach
                            </div>
                        </td>
                        <td>{{ implode(', ', $group['published_locales'] ?? []) ?: 'None' }}</td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament.ops.shared.status-pill :state="$group['ownership_ok'] ? 'success' : 'failed'" :label="$group['ownership_ok'] ? 'ownership ok' : 'ownership mismatch'" />
                                <x-filament.ops.shared.status-pill :state="$group['canonical_ok'] ? 'success' : 'failed'" :label="$group['canonical_ok'] ? 'canonical ok' : 'canonical risk'" />
                            </div>
                            @foreach (($group['alerts'] ?? []) as $alert)
                                <p class="ops-control-hint">{{ $alert['label'] }}</p>
                            @endforeach
                        </td>
                        <td>
                            <div class="ops-toolbar-inline">
                                <x-filament::button size="xs" color="gray" type="button" wire:click="inspectGroup('{{ $group['group_key'] }}')">
                                    Inspect
                                </x-filament::button>
                                @if ($group['source_edit_url'])
                                    <x-filament::button size="xs" color="gray" tag="a" href="{{ $group['source_edit_url'] }}">
                                        Source
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
                title="Group inspect: {{ $selectedGroup['slug'] }}"
                description="Canonical source, sibling locales, version hashes, publish readiness, and safe action entry points."
            >
                <x-filament-ops::ops-field-grid
                    :fields="[
                        ['label' => 'Content type', 'value' => (string) $selectedGroup['content_type_label'], 'state' => 'info'],
                        ['label' => 'Translation group', 'value' => (string) $selectedGroup['translation_group_id'], 'state' => 'info'],
                        ['label' => 'Source row', 'value' => $selectedGroup['source_locale'].' #'.$selectedGroup['source_record_id'], 'state' => $selectedGroup['canonical_ok'] ? 'success' : 'failed'],
                        ['label' => 'Published locales', 'value' => implode(', ', $selectedGroup['published_locales'] ?? []) ?: 'None', 'state' => 'success'],
                        ['label' => 'Stale locales', 'value' => (string) $selectedGroup['stale_locales_count'], 'state' => $selectedGroup['stale_locales_count'] > 0 ? 'warning' : 'success'],
                        ['label' => 'Ownership', 'value' => $selectedGroup['ownership_ok'] ? 'OK' : implode(', ', $selectedGroup['ownership_issues']), 'state' => $selectedGroup['ownership_ok'] ? 'success' : 'failed'],
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
                            <span class="ops-control-hint">{{ $action['label'] }} disabled: {{ $action['reason'] }}</span>
                        @endif
                    @endforeach
                </div>

                <x-filament-ops::ops-field-grid
                    :fields="[
                        ['label' => 'Target locales', 'value' => implode(', ', $selectedGroup['coverage']['target_locales'] ?? []) ?: 'None', 'state' => 'info'],
                        ['label' => 'Existing locales', 'value' => implode(', ', $selectedGroup['coverage']['existing_locales'] ?? []) ?: 'None', 'state' => 'info'],
                        ['label' => 'Published locales', 'value' => implode(', ', $selectedGroup['coverage']['published_locales'] ?? []) ?: 'None', 'state' => 'success'],
                        ['label' => 'Machine draft locales', 'value' => implode(', ', $selectedGroup['coverage']['machine_draft_locales'] ?? []) ?: 'None', 'state' => 'info'],
                        ['label' => 'Human review locales', 'value' => implode(', ', $selectedGroup['coverage']['human_review_locales'] ?? []) ?: 'None', 'state' => 'warning'],
                        ['label' => 'Stale locales', 'value' => implode(', ', $selectedGroup['coverage']['stale_locales'] ?? []) ?: 'None', 'state' => empty($selectedGroup['coverage']['stale_locales'] ?? []) ? 'success' : 'warning'],
                        ['label' => 'Missing target locales', 'value' => implode(', ', $selectedGroup['coverage']['missing_target_locales'] ?? []) ?: 'None', 'state' => empty($selectedGroup['coverage']['missing_target_locales'] ?? []) ? 'success' : 'warning'],
                    ]"
                />

                <div class="ops-table-shell">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>Locale row</th>
                                <th>Status</th>
                                <th>Workflow</th>
                                <th>Version match</th>
                                <th>Ownership / preflight</th>
                                <th>Entry points</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($selectedGroup['locales'] ?? []) as $locale)
                                <tr wire:key="translation-locale-{{ $selectedGroup['group_key'] }}-{{ $locale['record_id'] }}">
                                    <td>
                                        <strong>{{ $locale['locale'] }} #{{ $locale['record_id'] }}</strong>
                                        <p class="ops-control-hint">source_locale {{ $locale['source_locale'] }}</p>
                                        <p class="ops-control-hint">source_record_id {{ $locale['source_record_id'] ?? 'source' }}</p>
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill
                                            :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                            :label="$locale['translation_status'].($locale['is_stale'] ? ' stale' : '')"
                                        />
                                        <p class="ops-control-hint">{{ $locale['record_status'] }} / public {{ $locale['is_public'] ? 'yes' : 'no' }}</p>
                                        <p class="ops-control-hint">published_at {{ $locale['published_at'] ?? 'null' }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">{{ $locale['workflow_kind'] }} workflow</p>
                                        <p class="ops-control-hint">working revision {{ $locale['working_revision_id'] ?? 'n/a' }}</p>
                                        <p class="ops-control-hint">published revision {{ $locale['published_revision_id'] ?? 'n/a' }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">source {{ \Illuminate\Support\Str::limit((string) ($locale['source_version_hash'] ?? 'missing'), 12, '') }}</p>
                                        <p class="ops-control-hint">from {{ \Illuminate\Support\Str::limit((string) ($locale['translated_from_version_hash'] ?? 'missing'), 12, '') }}</p>
                                        @foreach (($locale['compare_summary'] ?? []) as $line)
                                            <p class="ops-control-hint">{{ $line }}</p>
                                        @endforeach
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill :state="$locale['ownership_ok'] ? 'success' : 'failed'" :label="$locale['ownership_ok'] ? 'ok' : 'mismatch'" />
                                        @foreach (($locale['ownership_issues'] ?? []) as $issue)
                                            <p class="ops-control-hint">{{ $issue }}</p>
                                        @endforeach
                                        <x-filament.ops.shared.status-pill
                                            :state="($locale['preflight']['ok'] ?? true) ? 'success' : 'failed'"
                                            :label="($locale['preflight']['ok'] ?? true) ? 'preflight ok' : 'preflight blocked'"
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
                                                    <span class="ops-control-hint">{{ $action['label'] }} disabled: {{ $action['reason'] }}</span>
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
