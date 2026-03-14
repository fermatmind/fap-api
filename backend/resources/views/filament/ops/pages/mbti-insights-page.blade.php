<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[repeat(7,minmax(0,1fr))_auto]">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">From</span>
                    <input type="date" wire:model.defer="fromDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">To</span>
                    <input type="date" wire:model.defer="toDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
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
                    <a href="/ops/results" class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                        Results Explorer
                    </a>
                </div>
            </div>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Authority Scope</h2>
                    <p class="text-sm text-slate-500">
                        Fixed to {{ $canonicalScale['scale_code'] }} only, with dual-write recognition for {{ $canonicalScale['scale_code_v2'] }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (['overview' => 'Overview', 'types' => 'Type Distribution', 'axes' => 'Axis Distribution'] as $tabKey => $label)
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

            <div class="mt-4 grid gap-3 lg:grid-cols-2">
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

        @if ($hasData)
            @if ($activeTab === 'overview')
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Overview KPI Cards</h2>
                        <p class="text-sm text-slate-500">First-phase authority numbers only. No paid, share, or channel subsets in the core KPI line.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        @foreach ($kpis as $card)
                            <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                                <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                                    {{ $card['display_value'] ?? $this->formatInt((int) $card['value']) }}
                                </div>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,1fr)]">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Daily Results Trend</h2>
                            <p class="text-sm text-slate-500">Authority result volume by result day.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Day</th>
                                        <th class="px-3 py-3">Results</th>
                                        <th class="px-3 py-3">Relative volume</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($dailyTrend as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                            <td class="px-3 py-3">
                                                <div class="h-2 rounded-full bg-slate-100">
                                                    <div class="h-2 rounded-full bg-sky-500" style="width: {{ $row['pct'] }}%;"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Locale Split</h2>
                            <p class="text-sm text-slate-500">Cross-locale summary inside the current org and date/version scope.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Locale</th>
                                        <th class="px-3 py-3">Results</th>
                                        <th class="px-3 py-3">Share</th>
                                        <th class="px-3 py-3">Top type</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($localeSplit as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['locale'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['share']) }}</td>
                                            <td class="px-3 py-3">{{ $row['top_type'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Version Split</h2>
                        <p class="text-sm text-slate-500">Version mix stays visible so first-phase insight pages do not silently pool cross-version results.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Content</th>
                                    <th class="px-3 py-3">Scoring</th>
                                    <th class="px-3 py-3">Norm</th>
                                    <th class="px-3 py-3">Results</th>
                                    <th class="px-3 py-3">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($versionSplit as $row)
                                    <tr>
                                        <td class="px-3 py-3">{{ $row['content_package_version'] }}</td>
                                        <td class="px-3 py-3">{{ $row['scoring_spec_version'] }}</td>
                                        <td class="px-3 py-3">{{ $row['norm_version'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['share']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @elseif ($activeTab === 'types')
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-slate-950">16-Type Distribution</h2>
                            <p class="text-sm text-slate-500">Counts and shares are normalized to the 16 base types. A/T stays out of the main type split.</p>
                        </div>
                        <a href="/ops/results" class="rounded-full border border-slate-200 px-3 py-1.5 text-sm text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                            Open Results Explorer
                        </a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Type</th>
                                    <th class="px-3 py-3">Count</th>
                                    <th class="px-3 py-3">Share</th>
                                    <th class="px-3 py-3">Distribution</th>
                                    <th class="px-3 py-3">Drill-through</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($typeDistribution as $row)
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['type_code'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['share']) }}</td>
                                        <td class="px-3 py-3">
                                            <div class="h-2 rounded-full bg-slate-100">
                                                <div class="h-2 rounded-full bg-sky-500" style="width: {{ round((float) (($row['share'] ?? 0) * 100), 1) }}%;"></div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">
                                            <a href="{{ $row['drill_url'] }}" class="text-sm font-medium text-sky-700 hover:text-sky-900">Search {{ $row['type_code'] }}</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Type Trend</h2>
                        <p class="text-sm text-slate-500">Daily MBTI result volume with the leading type for each day.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Day</th>
                                    <th class="px-3 py-3">Results</th>
                                    <th class="px-3 py-3">Top type</th>
                                    <th class="px-3 py-3">Top type share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($typeTrend as $row)
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                        <td class="px-3 py-3">{{ $row['top_type'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['top_type_share']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @else
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Axis Distribution</h2>
                        <p class="text-sm text-slate-500">
                            E/I, S/N, T/F, and J/P stay authoritative. A/T appears only when the current filtered scope has full A/T coverage.
                        </p>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-2">
                        @foreach ($axisSummary as $axis)
                            <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $axis['axis_code'] }}</div>
                                        <div class="text-lg font-semibold text-slate-950">{{ $axis['label'] }}</div>
                                    </div>
                                    <div class="text-sm text-slate-500">{{ $this->formatInt((int) $axis['results_count']) }} side rows</div>
                                </div>

                                <div class="mt-4 space-y-3">
                                    @foreach ($axis['sides'] as $side)
                                        <div class="space-y-1">
                                            <div class="flex items-center justify-between text-sm">
                                                <span class="font-medium text-slate-900">{{ $side['side_code'] }}</span>
                                                <span class="text-slate-600">{{ $this->formatInt((int) $side['results_count']) }} · {{ $this->formatRate($side['share']) }}</span>
                                            </div>
                                            <div class="h-2 rounded-full bg-white">
                                                <div class="h-2 rounded-full bg-sky-500" style="width: {{ round((float) (($side['share'] ?? 0) * 100), 1) }}%;"></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Axis Table</h2>
                            <p class="text-sm text-slate-500">Counts and shares are calculated within each axis, not across all rows.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Axis</th>
                                        <th class="px-3 py-3">Side</th>
                                        <th class="px-3 py-3">Count</th>
                                        <th class="px-3 py-3">Share</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($axisSummary as $axis)
                                        @foreach ($axis['sides'] as $side)
                                            <tr>
                                                <td class="px-3 py-3 font-medium text-slate-900">{{ $axis['axis_code'] }}</td>
                                                <td class="px-3 py-3">{{ $side['side_code'] }}</td>
                                                <td class="px-3 py-3">{{ $this->formatInt((int) $side['results_count']) }}</td>
                                                <td class="px-3 py-3">{{ $this->formatRate($side['share']) }}</td>
                                            </tr>
                                        @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Locale Comparison</h2>
                            <p class="text-sm text-slate-500">Compact lead-side view by locale. Keep locale = All to preserve a cross-locale panel.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Locale</th>
                                        <th class="px-3 py-3">Results</th>
                                        @foreach (array_keys($axisComparisonByLocale[0]['lead_map'] ?? []) as $axisCode)
                                            <th class="px-3 py-3">{{ $axisCode }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($axisComparisonByLocale as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['locale'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                            @foreach ($row['lead_map'] as $lead)
                                                <td class="px-3 py-3">{{ $lead }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Version Comparison</h2>
                        <p class="text-sm text-slate-500">Static version summary only. Deeper version drift analysis remains out of scope for AIC-06 v1.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Content</th>
                                    <th class="px-3 py-3">Scoring</th>
                                    <th class="px-3 py-3">Results</th>
                                    <th class="px-3 py-3">Axis snapshot</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($axisComparisonByVersion as $row)
                                    <tr>
                                        <td class="px-3 py-3">{{ $row['content_package_version'] }}</td>
                                        <td class="px-3 py-3">{{ $row['scoring_spec_version'] }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['results_count']) }}</td>
                                        <td class="px-3 py-3 text-slate-600">{{ $row['axis_snapshot'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
