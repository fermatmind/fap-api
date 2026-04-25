<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Registry governance"
            title="Enneagram Registry Governance"
            description="Preview, validate, publish, activate, and roll back ENNEAGRAM v2 registry releases without changing runtime interpretation unless Ops explicitly publishes a governance release."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Current release</span>
                    <p class="ops-control-hint">Scale {{ data_get($preview, 'scale_code', 'ENNEAGRAM') }} · Registry {{ data_get($preview, 'registry_version', 'unknown') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" type="button" wire:click="refreshRegistryPreview">
                        Refresh preview
                    </x-filament::button>
                    <x-filament::button color="primary" type="button" wire:click="publishRegistryRelease">
                        Publish registry release
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release overview"
            description="Release hash, manifest release id, validation state, active release, and last published release are all visible before any publish or rollback action."
        >
            <x-filament-ops::ops-field-grid :fields="[
                ['label' => 'Scale', 'value' => data_get($preview, 'scale_code', 'ENNEAGRAM')],
                ['label' => 'Registry version', 'value' => data_get($preview, 'registry_version', 'unknown')],
                ['label' => 'Manifest release id', 'value' => data_get($preview, 'release_id', 'unknown')],
                ['label' => 'Registry release hash', 'value' => data_get($preview, 'registry_release_hash', 'missing')],
                ['label' => 'Validation', 'value' => data_get($preview, 'validation.status', 'unknown'), 'kind' => 'pill', 'state' => data_get($preview, 'validation.status') === 'passed' ? 'success' : 'failed'],
                ['label' => 'Active release', 'value' => data_get($preview, 'release_state.active_release.release_id', 'none')],
                ['label' => 'Last published release', 'value' => data_get($preview, 'release_state.last_published_release.release_id', 'none')],
                ['label' => 'Technical Note version', 'value' => data_get($preview, 'technical_note_preview.technical_note_version', 'unknown')],
                ['label' => 'Observation day coverage', 'value' => (string) data_get($preview, 'coverage.observation_day_coverage', 0)],
                ['label' => 'P0 pair coverage', 'value' => (string) data_get($preview, 'coverage.p0_pair_coverage_count', 0)],
            ]" />
        </x-filament-ops::ops-section>

        @if ($validationErrors !== [])
            <x-filament-ops::ops-section
                title="Validation errors"
                description="Publish is blocked until RegistryValidator returns a clean result."
            >
                <ul class="ops-list">
                    @foreach ($validationErrors as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-filament-ops::ops-section>
        @endif

        <x-filament-ops::ops-section
            title="Registry summary"
            description="Content maturity, evidence boundaries, theory hint safety, and required registry coverage are grouped here for release review."
        >
            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament-ops::ops-result-card title="Content maturity summary" :meta="count((array) data_get($preview, 'content_maturity_summary', [])).' buckets'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'content_maturity_summary', []) as $key => $count)
                            <li>{{ $key }} · {{ $count }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Evidence level summary" :meta="count((array) data_get($preview, 'evidence_level_summary', [])).' buckets'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'evidence_level_summary', []) as $key => $count)
                            <li>{{ $key }} · {{ $count }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Theory hint safety" :meta="data_get($preview, 'theory_hint_safety.all_non_hard_judgement') ? 'non-hard-judgement' : 'violations detected'">
                    <p class="ops-control-hint">Entries: {{ data_get($preview, 'theory_hint_safety.entry_count', 0) }}</p>
                    @if (data_get($preview, 'theory_hint_safety.all_non_hard_judgement'))
                        <p>All theory hint entries remain non-hard-judgement.</p>
                    @else
                        <ul class="ops-list">
                            @foreach ((array) data_get($preview, 'theory_hint_safety.hard_judgement_violations', []) as $violation)
                                <li>{{ $violation }}</li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Required registries" :meta="count((array) data_get($preview, 'required_registries', [])).' files'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'registry_files', []) as $row)
                            <li>{{ data_get($row, 'registry_key') }} · {{ data_get($row, 'entry_count', 0) }} entries · {{ data_get($row, 'content_maturity', 'unknown') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Preview surfaces"
            description="These previews are read-only. They exist to verify pack readiness before publish, not to edit registry copy in-place."
        >
            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament-ops::ops-result-card title="Type registry coverage" :meta="data_get($preview, 'coverage.type_count', 0).' types'">
                    <ul class="ops-list">
                        @foreach (array_slice((array) data_get($preview, 'type_preview.types', []), 0, 5) as $type)
                            <li>{{ data_get($type, 'type_id') }} · {{ data_get($type, 'content_maturity') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Pair preview" :meta="data_get($preview, 'coverage.p0_pair_coverage_count', 0).' P0 pairs'">
                    <ul class="ops-list">
                        @foreach (array_slice((array) data_get($preview, 'pair_preview.pairs', []), 0, 6) as $pair)
                            <li>{{ data_get($pair, 'pair_key') }} · {{ data_get($pair, 'evidence_level') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Group registry" :meta="data_get($preview, 'group_preview.group_count', 0).' entries'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'group_preview.groups', []) as $group)
                            <li>{{ data_get($group, 'group_key') }} · {{ data_get($group, 'content_maturity') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Observation Day1-Day7" :meta="data_get($preview, 'coverage.observation_day_coverage', 0).' days'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'observation_preview.days', []) as $day)
                            <li>Day {{ data_get($day, 'day') }} · {{ data_get($day, 'phase') }} · {{ data_get($day, 'title') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Sample reports" :meta="data_get($preview, 'sample_report_preview.sample_count', 0).' samples'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'sample_report_preview.samples', []) as $sample)
                            <li>{{ data_get($sample, 'sample_key') }} · {{ data_get($sample, 'interpretation_scope') }} · {{ data_get($sample, 'form_code') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Method boundaries" :meta="data_get($preview, 'coverage.method_boundary_count', 0).' entries'">
                    <ul class="ops-list">
                        @foreach (array_slice((array) data_get($preview, 'method_preview.boundaries', []), 0, 6) as $boundary)
                            <li>{{ data_get($boundary, 'method_key') }} · {{ data_get($boundary, 'evidence_level') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Technical Note preview"
            description="Make method boundaries, disclaimers, and unsupported-claim guards visible before a registry release is published."
        >
            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament-ops::ops-result-card title="Technical Note sections" :meta="data_get($preview, 'coverage.technical_note_sections_count', 0).' sections'">
                    <ul class="ops-list">
                        @foreach ((array) data_get($preview, 'technical_note_preview.sections', []) as $section)
                            <li>{{ data_get($section, 'section_key') }} · {{ data_get($section, 'data_status') }}</li>
                        @endforeach
                    </ul>
                </x-filament-ops::ops-result-card>

                <x-filament-ops::ops-result-card title="Unsupported claims guard" :meta="data_get($preview, 'technical_note_preview.disclaimers_present') ? 'disclaimers present' : 'disclaimers missing'">
                    <ul class="ops-list">
                        <li>No clinical claim · {{ data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_clinical_claim') ? 'yes' : 'no' }}</li>
                        <li>No hiring screening claim · {{ data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_hiring_screening_claim') ? 'yes' : 'no' }}</li>
                        <li>No hard theory judgement · {{ data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_hard_theory_judgement') ? 'yes' : 'no' }}</li>
                        <li>No cross-form numeric compare claim · {{ data_get($preview, 'technical_note_preview.unsupported_claims_guard.no_cross_form_numeric_compare') ? 'yes' : 'no' }}</li>
                    </ul>
                </x-filament-ops::ops-result-card>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Workplace / team placeholder"
            description="Visible in Ops only. This is not an active B2B product and does not enable a workplace or team dashboard."
        >
            <x-filament-ops::ops-field-grid :fields="[
                ['label' => 'Supported context modes', 'value' => implode(', ', (array) data_get($preview, 'workplace_placeholder.supported_context_modes', []))],
                ['label' => 'Active context modes', 'value' => implode(', ', (array) data_get($preview, 'workplace_placeholder.active_context_modes', [])) ?: 'individual'],
                ['label' => 'Workplace active', 'value' => data_get($preview, 'workplace_placeholder.workplace_active') ? 'yes' : 'no', 'kind' => 'pill', 'state' => data_get($preview, 'workplace_placeholder.workplace_active') ? 'warning' : 'success'],
                ['label' => 'Team active', 'value' => data_get($preview, 'workplace_placeholder.team_active') ? 'yes' : 'no', 'kind' => 'pill', 'state' => data_get($preview, 'workplace_placeholder.team_active') ? 'warning' : 'success'],
                ['label' => 'Dashboard enabled', 'value' => data_get($preview, 'workplace_placeholder.dashboard_enabled') ? 'yes' : 'no', 'kind' => 'pill', 'state' => data_get($preview, 'workplace_placeholder.dashboard_enabled') ? 'warning' : 'success'],
                ['label' => 'Placeholder summary', 'value' => data_get($preview, 'workplace_placeholder.summary', 'not configured')],
            ]" />

            <div class="mt-4">
                <h3 class="ops-control-label">Future modules</h3>
                <ul class="ops-list">
                    @foreach ((array) data_get($preview, 'workplace_placeholder.future_modules', []) as $module)
                        <li>{{ $module }}</li>
                    @endforeach
                </ul>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Release history"
            description="Publish and rollback records live here. Activate points the current governance release to a previously published registry snapshot."
        >
            <x-filament-ops::ops-table
                :has-rows="count((array) data_get($preview, 'release_state.history', [])) > 0"
                empty-eyebrow="Registry history"
                empty-title="No ENNEAGRAM registry releases yet"
                empty-description="Publish the validated registry once to create the first governance release record."
            >
                <x-slot name="head">
                    <tr>
                        <th>Release ID</th>
                        <th>Action</th>
                        <th>Manifest hash</th>
                        <th>Created by</th>
                        <th>Created at</th>
                        <th>State</th>
                        <th>Actions</th>
                    </tr>
                </x-slot>

                @foreach ((array) data_get($preview, 'release_state.history', []) as $release)
                    <tr wire:key="enneagram-release-{{ data_get($release, 'release_id') }}">
                        <td>{{ data_get($release, 'release_id') }}</td>
                        <td>{{ data_get($release, 'action') }}</td>
                        <td>{{ data_get($release, 'manifest_hash') }}</td>
                        <td>{{ data_get($release, 'created_by') }}</td>
                        <td>{{ data_get($release, 'created_at') }}</td>
                        <td>
                            <x-filament.ops.shared.status-pill
                                :state="data_get($release, 'is_active') ? 'success' : 'info'"
                                :label="data_get($release, 'is_active') ? 'active' : 'published'"
                            />
                        </td>
                        <td>
                            <div class="ops-toolbar-inline">
                                @if (! data_get($release, 'is_active'))
                                    <x-filament::button size="xs" color="gray" type="button" wire:click="activateRelease('{{ data_get($release, 'release_id') }}')">
                                        Activate
                                    </x-filament::button>
                                @endif
                                <x-filament::button size="xs" color="warning" type="button" wire:click="rollbackRelease('{{ data_get($release, 'release_id') }}')">
                                    Roll back to here
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
