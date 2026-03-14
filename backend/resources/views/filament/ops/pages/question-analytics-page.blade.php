<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[repeat(4,minmax(0,1fr))]">
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
                    <select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" disabled>
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

                <label class="space-y-2 xl:col-span-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Question</span>
                    <select wire:model.defer="questionId" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">All questions</option>
                        @foreach ($questionIdOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" wire:model.defer="onlyCompletedAttempts" class="rounded border-slate-300 text-sky-600 shadow-sm" />
                    <span>Only completed attempts</span>
                </label>

                <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-500">
                    <input type="checkbox" wire:model.defer="excludeNonAuthoritativeScales" class="rounded border-slate-300 text-sky-600 shadow-sm" checked disabled />
                    <span>Exclude non-authoritative scales</span>
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
                        Fixed to {{ $canonicalScale['scale_code'] }} only, with legacy / v2 recognition for {{ $canonicalScale['scale_code_v2'] }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (['option-distribution' => 'Option Distribution', 'dropoff-completion' => 'Dropoff / Completion'] as $tabKey => $label)
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

            <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
                @foreach ($drillLinks as $link)
                    <a href="{{ $link['url'] }}" class="inline-flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-950">
                        <span>{{ $link['label'] }}</span>
                        <span class="text-slate-400">Open</span>
                    </a>
                @endforeach

                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Duration is deferred in v1. No authority duration panel or heatmap is shown on this page.
                </div>
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

        @if ($activeTab === 'option-distribution')
            @if ($hasOptionData)
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Option Distribution KPI Cards</h2>
                        <p class="text-sm text-slate-500">Authoritative rows only. No events-based fallback and no redacted / rowless scales.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($optionKpis as $card)
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

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Question Option Distribution Table</h2>
                        <p class="text-sm text-slate-500">Question ranking, answer count, and option share stacked bars come from daily authority rows.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Order</th>
                                    <th class="px-3 py-3">Question</th>
                                    <th class="px-3 py-3">Answer count</th>
                                    <th class="px-3 py-3">Distinct attempts</th>
                                    <th class="px-3 py-3">Option share</th>
                                    <th class="px-3 py-3">Top option</th>
                                    <th class="px-3 py-3">Attempts Explorer</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($optionDistributionRows as $row)
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-900">#{{ $row['question_order'] }}</td>
                                        <td class="px-3 py-3">
                                            <div class="font-medium text-slate-900">{{ $row['question_id'] }}</div>
                                            <div class="mt-1 max-w-[28rem] text-xs leading-5 text-slate-500">{{ $row['question_label'] }}</div>
                                        </td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['answer_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['distinct_attempts_answered']) }}</td>
                                        <td class="px-3 py-3">
                                            <div class="min-w-[260px]">
                                                <div class="flex h-3 overflow-hidden rounded-full bg-slate-100">
                                                    @foreach ($row['option_segments'] as $segment)
                                                        <div
                                                            title="{{ $segment['label'] }}: {{ $this->formatRate($segment['share']) }}"
                                                            class="h-3"
                                                            style="width: {{ $segment['pct'] }}%; background-color: {{ $this->barColor($segment['option_key']) }};"
                                                        ></div>
                                                    @endforeach
                                                </div>
                                                <div class="mt-2 flex flex-wrap gap-2 text-xs text-slate-500">
                                                    @foreach ($row['option_segments'] as $segment)
                                                        <span class="inline-flex items-center gap-1">
                                                            <span class="inline-block h-2.5 w-2.5 rounded-full" style="background-color: {{ $this->barColor($segment['option_key']) }};"></span>
                                                            {{ $segment['label'] }} · {{ $this->formatRate($segment['share']) }}
                                                        </span>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3">{{ $row['top_option_key'] }} · {{ $this->formatRate($row['top_option_share']) }}</td>
                                        <td class="px-3 py-3">
                                            <a href="/ops/attempts?tableSearch=BIG5_OCEAN%20{{ urlencode($row['question_id']) }}" class="text-sky-700 transition hover:text-sky-900">
                                                Open
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Top Answered Questions</h2>
                            <p class="text-sm text-slate-500">Fast ranking for question-level answer volume.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Question</th>
                                        <th class="px-3 py-3">Answer count</th>
                                        <th class="px-3 py-3">Top share</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($optionRankingRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">#{{ $row['question_order'] }} / {{ $row['question_id'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['answer_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['top_option_share']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Locale Compare</h2>
                            <p class="text-sm text-slate-500">Table cut only. No tiny-locale charting in v1.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Locale</th>
                                        <th class="px-3 py-3">Answered rows</th>
                                        <th class="px-3 py-3">Attempts</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($optionLocaleCompareRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['locale'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['answered_rows_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['distinct_attempts_answered']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Version Compare</h2>
                            <p class="text-sm text-slate-500">Content, scoring, and norm stay explicit so cross-version pools remain visible.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Content</th>
                                        <th class="px-3 py-3">Scoring</th>
                                        <th class="px-3 py-3">Norm</th>
                                        <th class="px-3 py-3">Answered rows</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($optionVersionCompareRows as $row)
                                        <tr>
                                            <td class="px-3 py-3">{{ $row['content_package_version'] }}</td>
                                            <td class="px-3 py-3">{{ $row['scoring_spec_version'] }}</td>
                                            <td class="px-3 py-3">{{ $row['norm_version'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['answered_rows_count']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @else
                <div class="rounded-2xl border border-slate-200 bg-white/95 px-4 py-5 text-sm text-slate-500 shadow-sm">
                    No option-distribution data matches the current scope.
                </div>
            @endif
        @elseif ($activeTab === 'dropoff-completion')
            @if ($hasProgressData)
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Dropoff / Completion KPI Cards</h2>
                        <p class="text-sm text-slate-500">Progression comes from attempts + drafts + rows, not from events. Completed-only mode is explicit.</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ($progressKpis as $card)
                            <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                                <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                                    {{ $this->formatInt((int) $card['value']) }}
                                </div>
                                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['description'] }}</p>
                            </article>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">Question Dropoff / Completion Table</h2>
                        <p class="text-sm text-slate-500">Rates are computed from reached attempts inside the current filtered scope.</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">Order</th>
                                    <th class="px-3 py-3">Question</th>
                                    <th class="px-3 py-3">Reached</th>
                                    <th class="px-3 py-3">Answered</th>
                                    <th class="px-3 py-3">Completed</th>
                                    <th class="px-3 py-3">Dropoff</th>
                                    <th class="px-3 py-3">Dropoff rate</th>
                                    <th class="px-3 py-3">Completion rate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 bg-white">
                                @foreach ($progressRows as $row)
                                    <tr>
                                        <td class="px-3 py-3 font-medium text-slate-900">#{{ $row['question_order'] }}</td>
                                        <td class="px-3 py-3">
                                            <div class="font-medium text-slate-900">{{ $row['question_id'] }}</div>
                                            <div class="mt-1 max-w-[28rem] text-xs leading-5 text-slate-500">{{ $row['question_label'] }}</div>
                                        </td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['reached_attempts_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['answered_attempts_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['completed_attempts_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatInt((int) $row['dropoff_attempts_count']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['dropoff_rate']) }}</td>
                                        <td class="px-3 py-3">{{ $this->formatRate($row['completion_rate']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Highest Dropoff Questions</h2>
                            <p class="text-sm text-slate-500">Ranking by dropoff rate, then dropoff attempt count.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Question</th>
                                        <th class="px-3 py-3">Dropoff</th>
                                        <th class="px-3 py-3">Rate</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($highestDropoffRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">#{{ $row['question_order'] }} / {{ $row['question_id'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['dropoff_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['dropoff_rate']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Lowest Completion Questions</h2>
                            <p class="text-sm text-slate-500">Ranking by completion rate inside the current scope.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Question</th>
                                        <th class="px-3 py-3">Completed</th>
                                        <th class="px-3 py-3">Rate</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($lowestCompletionRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">#{{ $row['question_order'] }} / {{ $row['question_id'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['completed_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['completion_rate']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Locale Compare</h2>
                            <p class="text-sm text-slate-500">Simple locale table cut for reached / answered / completed / dropoff.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Locale</th>
                                        <th class="px-3 py-3">Reached</th>
                                        <th class="px-3 py-3">Completed</th>
                                        <th class="px-3 py-3">Dropoff</th>
                                        <th class="px-3 py-3">Dropoff rate</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($progressLocaleCompareRows as $row)
                                        <tr>
                                            <td class="px-3 py-3 font-medium text-slate-900">{{ $row['locale'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['reached_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['completed_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['dropoff_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatRate($row['dropoff_rate']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                        <div class="mb-4">
                            <h2 class="text-lg font-semibold text-slate-950">Version Compare</h2>
                            <p class="text-sm text-slate-500">Version pool stays explicit to avoid silent cross-version authority claims.</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">Content</th>
                                        <th class="px-3 py-3">Scoring</th>
                                        <th class="px-3 py-3">Norm</th>
                                        <th class="px-3 py-3">Reached</th>
                                        <th class="px-3 py-3">Dropoff</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    @foreach ($progressVersionCompareRows as $row)
                                        <tr>
                                            <td class="px-3 py-3">{{ $row['content_package_version'] }}</td>
                                            <td class="px-3 py-3">{{ $row['scoring_spec_version'] }}</td>
                                            <td class="px-3 py-3">{{ $row['norm_version'] }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['reached_attempts_count']) }}</td>
                                            <td class="px-3 py-3">{{ $this->formatInt((int) $row['dropoff_attempts_count']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @else
                <div class="rounded-2xl border border-slate-200 bg-white/95 px-4 py-5 text-sm text-slate-500 shadow-sm">
                    No dropoff / completion data matches the current scope.
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
