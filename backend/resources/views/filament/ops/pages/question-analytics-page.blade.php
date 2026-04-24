<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[repeat(4,minmax(0,1fr))]">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.from') }}</span>
                    <input type="date" wire:model.defer="fromDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.to') }}</span>
                    <input type="date" wire:model.defer="toDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.scale') }}</span>
                    <select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" disabled>
                        @foreach ($scaleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.locale') }}</span>
                    <select wire:model.defer="locale" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_locales') }}</option>
                        @foreach ($localeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.region') }}</span>
                    <select wire:model.defer="region" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_regions') }}</option>
                        @foreach ($regionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.content') }}</span>
                    <select wire:model.defer="contentPackageVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_content_versions') }}</option>
                        @foreach ($contentPackageVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.scoring') }}</span>
                    <select wire:model.defer="scoringSpecVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_scoring_versions') }}</option>
                        @foreach ($scoringSpecVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.norm') }}</span>
                    <select wire:model.defer="normVersion" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_norm_versions') }}</option>
                        @foreach ($normVersionOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2 xl:col-span-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.custom_pages.question_analytics.filters.question') }}</span>
                    <select wire:model.defer="questionId" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.custom_pages.question_analytics.filters.all_questions') }}</option>
                        @foreach ($questionIdOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-700">
                    <input type="checkbox" wire:model.defer="onlyCompletedAttempts" class="rounded border-slate-300 text-sky-600 shadow-sm" />
                    <span>{{ __('ops.custom_pages.question_analytics.filters.only_completed_attempts') }}</span>
                </label>

                <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-sm text-slate-500">
                    <input type="checkbox" wire:model.defer="excludeNonAuthoritativeScales" class="rounded border-slate-300 text-sky-600 shadow-sm" checked disabled />
                    <span>{{ __('ops.custom_pages.question_analytics.filters.exclude_non_authoritative_scales') }}</span>
                </label>

                <div class="flex items-end gap-3">
                    <x-filament::button type="submit">{{ __('ops.custom_pages.common.actions.apply') }}</x-filament::button>
                </div>
            </div>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.authority.title') }}</h2>
                    <p class="text-sm text-slate-500">
                        {{ __('ops.custom_pages.question_analytics.authority.description', ['scale' => $canonicalScale['scale_code'], 'scale_v2' => $canonicalScale['scale_code_v2']]) }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach (['option-distribution' => __('ops.custom_pages.question_analytics.tabs.option_distribution'), 'dropoff-completion' => __('ops.custom_pages.question_analytics.tabs.dropoff_completion')] as $tabKey => $label)
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
                        <span class="text-slate-400">{{ __('ops.custom_pages.common.actions.open') }}</span>
                    </a>
                @endforeach

                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    {{ __('ops.custom_pages.question_analytics.authority.duration_deferred') }}
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
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.option_kpis') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.option_kpis_desc') }}</p>
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
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.option_table') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.option_table_desc') }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.order') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.question') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.answer_count') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.distinct_attempts') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.option_share') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.top_option') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.attempts_explorer') }}</th>
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
                                                {{ __('ops.custom_pages.common.actions.open') }}
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.top_answered') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.top_answered_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.question') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.answer_count') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.top_share') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.locale_compare') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.locale_compare_option_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.filters.locale') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.answered_rows') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.attempts') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.version_compare') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.version_compare_option_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.content') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.scoring') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.norm') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.answered_rows') }}</th>
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
                    {{ __('ops.custom_pages.question_analytics.sections.no_option_data') }}
                </div>
            @endif
        @elseif ($activeTab === 'dropoff-completion')
            @if ($hasProgressData)
                <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                    <div class="mb-4">
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.progress_kpis') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.progress_kpis_desc') }}</p>
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
                        <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.progress_table') }}</h2>
                        <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.progress_table_desc') }}</p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                <tr>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.order') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.question') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.reached') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.answered') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.completed') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff_rate') }}</th>
                                    <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.completion_rate') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.highest_dropoff') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.highest_dropoff_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.question') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.rate') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.lowest_completion') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.lowest_completion_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.question') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.completed') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.rate') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.locale_compare') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.locale_compare_progress_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.filters.locale') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.reached') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.completed') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff_rate') }}</th>
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
                            <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.custom_pages.question_analytics.sections.version_compare') }}</h2>
                            <p class="text-sm text-slate-500">{{ __('ops.custom_pages.question_analytics.sections.version_compare_progress_desc') }}</p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    <tr>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.content') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.scoring') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.norm') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.reached') }}</th>
                                        <th class="px-3 py-3">{{ __('ops.custom_pages.question_analytics.table.dropoff') }}</th>
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
                    {{ __('ops.custom_pages.question_analytics.sections.no_progress_data') }}
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
