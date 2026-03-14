<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[repeat(8,minmax(0,1fr))_auto]">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">From</span>
                    <input type="date" wire:model.defer="fromDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">To</span>
                    <input type="date" wire:model.defer="toDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Scale</span>
                    <select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All scales</option>
                        @foreach ($scaleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Locale</span>
                    <select wire:model.defer="locale" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All locales</option>
                        @foreach ($localeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Region</span>
                    <select wire:model.defer="region" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All regions</option>
                        @foreach ($regionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Content</span>
                    <select wire:model.defer="contentPackageVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All content versions</option>
                        @foreach ($contentPackageVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Scoring</span>
                    <select wire:model.defer="scoringSpecVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All scoring versions</option>
                        @foreach ($scoringSpecVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Norm</span>
                    <select wire:model.defer="normVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All norm versions</option>
                        @foreach ($normVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end gap-3">
                    <x-filament::button type="submit">Apply</x-filament::button>
                </div>
            </div>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Authority Scope</h2>
                    <p class="text-sm text-slate-500">
                        Admin-only / internal-only. Quality stays org-scoped; psychometrics and norm objects remain reference layers with explicit authority boundaries.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (['quality' => 'Quality', 'psychometrics' => 'Psychometrics', 'norms-drift' => 'Norms & Drift'] as $tabKey => $label)
                        <button
                            type="button"
                            wire:click="setActiveTab('{{ $tabKey }}')"
                            class="rounded-full border px-4 py-2 text-sm font-medium transition {{ $activeTab === $tabKey ? 'border-sky-300 bg-sky-50 text-sky-700' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:text-slate-900' }}"
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-3">
                @foreach ($scopeNotes as $note)
                    <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm leading-6 text-slate-700">
                        {{ $note }}
                    </div>
                @endforeach
            </div>
        </section>

        @if ($warnings !== [])
            <div class="space-y-3">
                @foreach ($warnings as $warning)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        {{ $warning }}
                    </div>
                @endforeach
            </div>
        @endif

        @if ($activeTab === 'quality')
            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-950">Quality</h2>
                        <p class="text-sm text-slate-500">analytics_scale_quality_daily drives the summary layer. Attempts / Results explorers remain the drill-through path.</p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <a href="/ops/attempts" class="rounded-full border border-slate-200 px-3 py-2 text-sm text-slate-600 transition hover:border-slate-300 hover:text-slate-900">Attempts Explorer</a>
                        <a href="/ops/results" class="rounded-full border border-slate-200 px-3 py-2 text-sm text-slate-600 transition hover:border-slate-300 hover:text-slate-900">Results Explorer</a>
                    </div>
                </div>

                <div class="mt-4 grid gap-3 lg:grid-cols-3">
                    @foreach ($qualityNotes as $note)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm leading-6 text-slate-700">
                            {{ $note }}
                        </div>
                    @endforeach
                </div>

                <form wire:submit.prevent="applyFilters" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,0.7fr))_auto]">
                        <label class="space-y-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Quality level</span>
                            <select wire:model.defer="qualityLevel" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                                <option value="all">All levels</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </label>

                        <label class="flex items-end gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="onlyCrisis" class="rounded border-slate-300 text-sky-600 shadow-sm" />
                            <span>Only crisis</span>
                        </label>

                        <label class="flex items-end gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="onlyInvalid" class="rounded border-slate-300 text-sky-600 shadow-sm" />
                            <span>Only invalid</span>
                        </label>

                        <label class="flex items-end gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                            <input type="checkbox" wire:model.defer="onlyWarnings" class="rounded border-slate-300 text-sky-600 shadow-sm" />
                            <span>Only warnings</span>
                        </label>

                        <div class="flex items-end">
                            <x-filament::button type="submit" color="gray">Apply quality filters</x-filament::button>
                        </div>
                    </div>
                </form>
            </section>

            @if ($hasQualityData)
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">KPI Cards</h2>
                        <p class="text-sm text-slate-500">Cards stay on the global scope. Local quality filters only reshape the tables below.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($qualityKpis as $card)
                            <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                                <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $card['display_value'] }}</div>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Daily Quality Summary</h2>
                            <p class="text-sm text-slate-500">Day-level summary across the selected global scope and current quality table filters.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Day</th>
                                        <th class="px-3 py-3">Results</th>
                                        <th class="px-3 py-3">Completion</th>
                                        <th class="px-3 py-3">Validity</th>
                                        <th class="px-3 py-3">Quality mix</th>
                                        <th class="px-3 py-3">Crisis</th>
                                        <th class="px-3 py-3">Warnings</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @forelse ($qualityDailyRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['completion_rate']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['validity_rate']) }}</td>
                                            <td class="px-3 py-3">{{ $row['quality_mix'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['crisis_alert_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['warnings_count']) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-3 py-6 text-center text-sm text-slate-500">No daily quality rows in the current filtered scope.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Quality Flags Breakdown</h2>
                            <p class="text-sm text-slate-500">Reference counters only. Some flags are only stable on subset scales in v1.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Flag</th>
                                        <th class="px-3 py-3">Count</th>
                                        <th class="px-3 py-3">Rate</th>
                                        <th class="px-3 py-3">Reference</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @forelse ($qualityFlagRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['label'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['rate']) }}</td>
                                            <td class="px-3 py-3 text-slate-600">{{ $row['reference'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="px-3 py-6 text-center text-sm text-slate-500">No flag rows in the current filtered scope.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Scale-by-Scale Quality Comparison</h2>
                        <p class="text-sm text-slate-500">Use Attempts / Results Explorer for record-level review after spotting outliers here.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Scale</th>
                                    <th class="px-3 py-3">Results</th>
                                    <th class="px-3 py-3">Completion</th>
                                    <th class="px-3 py-3">Validity</th>
                                    <th class="px-3 py-3">Quality mix</th>
                                    <th class="px-3 py-3">Crisis</th>
                                    <th class="px-3 py-3">Longstring</th>
                                    <th class="px-3 py-3">Straightlining</th>
                                    <th class="px-3 py-3">Extreme</th>
                                    <th class="px-3 py-3">Inconsistency</th>
                                    <th class="px-3 py-3">Version mix</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @forelse ($qualityScaleRows as $row)
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['completion_rate']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['validity_rate']) }}</td>
                                        <td class="px-3 py-3">{{ $row['quality_mix'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['crisis_alert_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['longstring_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['straightlining_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['extreme_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['inconsistency_count']) }}</td>
                                        <td class="px-3 py-3 text-slate-600">{{ $row['version_mix'] }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="px-3 py-6 text-center text-sm text-slate-500">No scale comparison rows in the current filtered scope.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            @else
                <section class="rounded-2xl border border-dashed border-slate-300 bg-white/80 p-8 text-center text-sm text-slate-500">
                    Quality data is not available for the current scope yet.
                </section>
            @endif
        @elseif ($activeTab === 'psychometrics')
            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Psychometric Summary Table</h2>
                    <p class="text-sm text-slate-500">Snapshot-driven and internal-reference by default. Small-sample rows stay reference-only.</p>
                </div>

                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($psychometricNotes as $note)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm leading-6 text-slate-700">
                            {{ $note }}
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Scale</th>
                                <th class="px-3 py-3">Latest snapshot</th>
                                <th class="px-3 py-3">Locale / Region</th>
                                <th class="px-3 py-3">Norm version</th>
                                <th class="px-3 py-3">Window</th>
                                <th class="px-3 py-3">Sample n</th>
                                <th class="px-3 py-3">Reference state</th>
                                <th class="px-3 py-3">Primary summary</th>
                                <th class="px-3 py-3">Secondary summary</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($psychometricRows as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                    <td class="px-3 py-3">{{ $row['latest_snapshot_at'] }}</td>
                                    <td class="px-3 py-3">{{ $row['locale'] }} / {{ $row['region'] }}</td>
                                    <td class="px-3 py-3">{{ $row['norm_version'] }}</td>
                                    <td class="px-3 py-3">{{ $row['time_window'] }}</td>
                                    <td class="px-3 py-3">
                                        {{ $this->formatInt((int) $row['sample_n']) }}
                                        <div class="text-xs text-slate-500">min display {{ $this->formatInt((int) $row['min_display_samples']) }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-slate-600">{{ $row['reference_state'] }}</td>
                                    <td class="px-3 py-3">{{ $row['summary_primary'] }}</td>
                                    <td class="px-3 py-3">{{ $row['summary_secondary'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-6 text-center text-sm text-slate-500">No psychometric snapshots match the current scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Norms & Drift</h2>
                    <p class="text-sm text-slate-500">Hard coverage on active norm objects, with rollout and drift staying internal reference diagnostics.</p>
                </div>

                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($normNotes as $note)
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-sm leading-6 text-slate-700">
                            {{ $note }}
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Norm Version Coverage</h2>
                    <p class="text-sm text-slate-500">scale_norms_versions + scale_norm_stats drive this object view directly.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Scale</th>
                                <th class="px-3 py-3">Locale / Region</th>
                                <th class="px-3 py-3">Active norm version</th>
                                <th class="px-3 py-3">Active groups</th>
                                <th class="px-3 py-3">Coverage sample</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Latest published</th>
                                <th class="px-3 py-3">Latest updated</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($normCoverageRows as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                    <td class="px-3 py-3">{{ $row['locale'] }} / {{ $row['region'] }}</td>
                                    <td class="px-3 py-3">{{ $row['active_norm_version'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['active_group_count']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['coverage_sample_n']) }}</td>
                                    <td class="px-3 py-3">{{ $row['status_summary'] !== '' ? $row['status_summary'] : 'n/a' }}</td>
                                    <td class="px-3 py-3">{{ $row['latest_published_at'] }}</td>
                                    <td class="px-3 py-3">{{ $row['latest_updated_at'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-3 py-6 text-center text-sm text-slate-500">No active norm coverage rows match the current scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Scoring Rollout Diagnostics</h2>
                    <p class="text-sm text-slate-500">Config comes from scoring_models / scoring_model_rollouts. Observation coverage is scoped to selected org + date range.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Scale</th>
                                <th class="px-3 py-3">Model key</th>
                                <th class="px-3 py-3">Driver</th>
                                <th class="px-3 py-3">Scoring spec</th>
                                <th class="px-3 py-3">Rollout rule</th>
                                <th class="px-3 py-3">Config state</th>
                                <th class="px-3 py-3">Observation coverage</th>
                                <th class="px-3 py-3">Observation share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($rolloutRows as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                    <td class="px-3 py-3">{{ $row['model_key'] }}</td>
                                    <td class="px-3 py-3">{{ $row['driver_type'] }}</td>
                                    <td class="px-3 py-3">{{ $row['scoring_spec_version'] }}</td>
                                    <td class="px-3 py-3">{{ $row['rollout_rule'] }}</td>
                                    <td class="px-3 py-3">{{ $row['config_state'] }}</td>
                                    <td class="px-3 py-3 text-slate-600">{{ $row['observation_summary'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatRate($row['observation_share']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-3 py-6 text-center text-sm text-slate-500">No rollout rows match the current scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4">
                    <h2 class="text-lg font-semibold text-slate-950">Latest vs Previous Drift Compare</h2>
                    <p class="text-sm text-slate-500">Internal compare reference only. If coverage is thin, continue using norms:*:drift-check commands offline.</p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Scale</th>
                                <th class="px-3 py-3">Locale / Region</th>
                                <th class="px-3 py-3">Active version</th>
                                <th class="px-3 py-3">Previous version</th>
                                <th class="px-3 py-3">Groups</th>
                                <th class="px-3 py-3">Metrics</th>
                                <th class="px-3 py-3">Max mean diff</th>
                                <th class="px-3 py-3">Max sd diff</th>
                                <th class="px-3 py-3">Reference</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse ($driftRows as $row)
                                <tr>
                                    <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                    <td class="px-3 py-3">{{ $row['locale'] }} / {{ $row['region'] }}</td>
                                    <td class="px-3 py-3">{{ $row['active_norm_version'] }}</td>
                                    <td class="px-3 py-3">{{ $row['previous_norm_version'] }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['compared_groups']) }}</td>
                                    <td class="px-3 py-3">{{ $this->formatInt((int) $row['compared_metrics']) }}</td>
                                    <td class="px-3 py-3">{{ number_format((float) $row['max_mean_diff'], 4) }}</td>
                                    <td class="px-3 py-3">{{ number_format((float) $row['max_sd_diff'], 4) }}</td>
                                    <td class="px-3 py-3 text-slate-600">{{ $row['reference_state'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-6 text-center text-sm text-slate-500">No drift compare rows are available for the current scope.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
