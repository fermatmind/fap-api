<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Articles"
            title="Translation Ops Console"
            description="Inspect article source and translation ownership, stale state, revision pointers, locale coverage, and safe next-step entry points from one surface."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Public article org scope</span>
                    <p class="ops-control-hint">This console reads the canonical public article surface only. It does not edit body copy, slugs, citations, DOI fields, or public routing behavior.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Resources\ArticleResource::getUrl('index') }}">
                        Articles
                    </x-filament::button>
                    <x-filament::button color="gray" type="button" wire:click="resetFilters">
                        Reset filters
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section title="Translation health" description="Group-level pressure points before article release.">
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

        <x-filament-ops::ops-section title="Filters" description="Narrow the list by slug, source/target locale, translation status, freshness, publication, missing locale, or ownership mismatch.">
            <div class="ops-toolbar-grid">
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
                        <span class="ops-control-hint">Use target locale, default en.</span>
                    </span>
                </label>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section title="Translation groups" description="Each row is one translation_group_id with source, locale coverage, revision pointers, ownership health, and alerts.">
            <x-filament-ops::ops-table
                :has-rows="count($groups) > 0"
                empty-title="No translation groups found"
                empty-description="Adjust filters or create article translation groups through the existing article workflow."
            >
                <x-slot name="head">
                    <tr>
                        <th>Group</th>
                        <th>Source</th>
                        <th>Locales</th>
                        <th>Published</th>
                        <th>Revisions</th>
                        <th>Health</th>
                        <th>Actions</th>
                    </tr>
                </x-slot>

                @foreach ($groups as $group)
                    <tr wire:key="translation-group-{{ $group['translation_group_id'] }}">
                        <td>
                            <strong>{{ $group['slug'] }}</strong>
                            <p class="ops-control-hint">{{ $group['translation_group_id'] }}</p>
                            <p class="ops-control-hint">hash {{ \Illuminate\Support\Str::limit((string) ($group['latest_source_hash'] ?? 'missing'), 12, '') }}</p>
                        </td>
                        <td>
                            <x-filament.ops.shared.status-pill :state="$group['source_article_status']" :label="$group['source_locale'].' #'.$group['source_article_id']" />
                            <p class="ops-control-hint">{{ $group['source_article_status'] }}</p>
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
                            <p class="ops-control-hint">working: {{ $group['has_working_revision'] ? 'yes' : 'no' }}</p>
                            <p class="ops-control-hint">published: {{ $group['has_published_revision'] ? 'yes' : 'no' }}</p>
                            <p class="ops-control-hint">stale locales: {{ $group['stale_locales_count'] }}</p>
                        </td>
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
                                <x-filament::button size="xs" color="gray" type="button" wire:click="inspectGroup('{{ $group['translation_group_id'] }}')">
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
                description="Canonical source, sibling locale articles, version hashes, latest revisions, and action availability."
            >
                <x-filament-ops::ops-field-grid
                    :fields="[
                        ['label' => 'Translation group', 'value' => (string) $selectedGroup['translation_group_id'], 'state' => 'info'],
                        ['label' => 'Source article', 'value' => $selectedGroup['source_locale'].' #'.$selectedGroup['source_article_id'], 'state' => $selectedGroup['canonical_ok'] ? 'success' : 'failed'],
                        ['label' => 'Published locales', 'value' => implode(', ', $selectedGroup['published_locales'] ?? []) ?: 'None', 'state' => 'success'],
                        ['label' => 'Stale locales', 'value' => (string) $selectedGroup['stale_locales_count'], 'state' => $selectedGroup['stale_locales_count'] > 0 ? 'warning' : 'success'],
                        ['label' => 'Ownership', 'value' => $selectedGroup['ownership_ok'] ? 'OK' : implode(', ', $selectedGroup['ownership_issues']), 'state' => $selectedGroup['ownership_ok'] ? 'success' : 'failed'],
                        ['label' => 'Canonical', 'value' => $selectedGroup['canonical_ok'] ? 'OK' : implode(', ', $selectedGroup['canonical_issues']), 'state' => $selectedGroup['canonical_ok'] ? 'success' : 'failed'],
                    ]"
                />

                <div class="ops-toolbar-inline">
                    @foreach (($selectedGroup['actions'] ?? []) as $action)
                        @if (($action['url'] ?? null) && ($action['enabled'] ?? false))
                            <x-filament::button size="xs" color="gray" tag="a" href="{{ $action['url'] }}">
                                {{ $action['label'] }}
                            </x-filament::button>
                        @else
                            <span class="ops-control-hint">{{ $action['label'] }} disabled: {{ $action['reason'] }}</span>
                        @endif
                    @endforeach
                </div>

                <div class="ops-table-shell">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>Locale article</th>
                                <th>Status</th>
                                <th>Revision pointers</th>
                                <th>Version match</th>
                                <th>Ownership</th>
                                <th>Entry points</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (($selectedGroup['locales'] ?? []) as $locale)
                                <tr wire:key="translation-locale-{{ $locale['article_id'] }}">
                                    <td>
                                        <strong>{{ $locale['locale'] }} #{{ $locale['article_id'] }}</strong>
                                        <p class="ops-control-hint">source_locale {{ $locale['source_locale'] }}</p>
                                        <p class="ops-control-hint">source_article_id {{ $locale['source_article_id'] ?? 'source' }}</p>
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill
                                            :state="$locale['is_stale'] ? 'warning' : $locale['translation_status']"
                                            :label="$locale['translation_status'].($locale['is_stale'] ? ' stale' : '')"
                                        />
                                        <p class="ops-control-hint">{{ $locale['article_status'] }} / public {{ $locale['is_public'] ? 'yes' : 'no' }}</p>
                                        <p class="ops-control-hint">published_at {{ $locale['published_at'] ?? 'null' }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">working #{{ $locale['working_revision_id'] ?? 'missing' }} {{ $locale['working_revision_status'] }}</p>
                                        <p class="ops-control-hint">published #{{ $locale['published_revision_id'] ?? 'missing' }} {{ $locale['published_revision_status'] }}</p>
                                    </td>
                                    <td>
                                        <p class="ops-control-hint">source {{ \Illuminate\Support\Str::limit((string) ($locale['source_version_hash'] ?? 'missing'), 12, '') }}</p>
                                        <p class="ops-control-hint">from {{ \Illuminate\Support\Str::limit((string) ($locale['translated_from_version_hash'] ?? 'missing'), 12, '') }}</p>
                                    </td>
                                    <td>
                                        <x-filament.ops.shared.status-pill :state="$locale['ownership_ok'] ? 'success' : 'failed'" :label="$locale['ownership_ok'] ? 'ok' : 'mismatch'" />
                                        @foreach (($locale['ownership_issues'] ?? []) as $issue)
                                            <p class="ops-control-hint">{{ $issue }}</p>
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
                                                    <x-filament::button size="xs" color="primary" type="button" wire:click="{{ $action['wire_action'] }}({{ $action['article_id'] }})">
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

            <x-filament-ops::ops-section title="Revision history summary" description="Latest revisions across the inspected group.">
                <x-filament-ops::ops-table
                    :has-rows="count($selectedGroup['revision_history'] ?? []) > 0"
                    empty-title="No revisions found"
                    empty-description="This group has no revision rows yet."
                >
                    <x-slot name="head">
                        <tr>
                            <th>Revision</th>
                            <th>Article</th>
                            <th>Status</th>
                            <th>Hashes</th>
                            <th>Updated</th>
                        </tr>
                    </x-slot>

                    @foreach (($selectedGroup['revision_history'] ?? []) as $revision)
                        <tr>
                            <td>#{{ $revision['id'] }} / v{{ $revision['revision_number'] }}</td>
                            <td>{{ $revision['locale'] }} #{{ $revision['article_id'] }}</td>
                            <td>{{ $revision['revision_status'] }}</td>
                            <td>
                                <p class="ops-control-hint">source {{ $revision['source_version_hash'] ?? 'missing' }}</p>
                                <p class="ops-control-hint">from {{ $revision['translated_from_version_hash'] ?? 'missing' }}</p>
                            </td>
                            <td>{{ $revision['updated_at'] }}</td>
                        </tr>
                    @endforeach
                </x-filament-ops::ops-table>
            </x-filament-ops::ops-section>
        @endif
    </div>
</x-filament-panels::page>
