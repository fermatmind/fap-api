<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="grid gap-4 xl:grid-cols-[repeat(6,minmax(0,1fr))_auto]">
                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.from') }}</span>
                    <input type="date" wire:model.defer="fromDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.to') }}</span>
                    <input type="date" wire:model.defer="toDate" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm" />
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.scope') }}</span>
                    <select wire:model.defer="scope" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="current_org">{{ __('ops.pages.test_kpi_daily.filters.current_org') }}</option>
                        <option value="global_org0">{{ __('ops.pages.test_kpi_daily.filters.global_org0') }}</option>
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.scale') }}</span>
                    <select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.pages.test_kpi_daily.filters.all_scales') }}</option>
                        @foreach ($scaleOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.form') }}</span>
                    <select wire:model.defer="formCode" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.pages.test_kpi_daily.filters.all_forms') }}</option>
                        @foreach ($formOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ __('ops.pages.test_kpi_daily.filters.locale') }}</span>
                    <select wire:model.defer="locale" class="block w-full rounded-xl border-slate-300 text-sm shadow-sm">
                        <option value="all">{{ __('ops.pages.test_kpi_daily.filters.all_locales') }}</option>
                        @foreach ($localeOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="flex items-end">
                    <x-filament::button type="submit">{{ __('ops.custom_pages.common.actions.apply') }}</x-filament::button>
                </div>
            </div>
        </form>

        @if ($warnings !== [])
            <div class="space-y-3">
                @foreach ($warnings as $warning)
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        {{ $warning }}
                    </div>
                @endforeach
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.pages.test_kpi_daily.sections.kpis') }}</h2>
                <p class="text-sm text-slate-500">{{ __('ops.pages.test_kpi_daily.sections.kpis_desc') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($kpis as $card)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">{{ $card['label'] }}</div>
                        <div class="mt-3 text-2xl font-semibold tracking-tight text-slate-950">{{ $this->formatInt((int) $card['value']) }}</div>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $card['description'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-slate-950">{{ __('ops.pages.test_kpi_daily.sections.detail') }}</h2>
                <p class="text-sm text-slate-500">{{ __('ops.pages.test_kpi_daily.sections.detail_desc') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        <tr>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.day') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.scale') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.scale_v2') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.form') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.locale') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.started') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.successful') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.failed') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.total') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.success_rate') }}</th>
                            <th class="px-3 py-3">{{ __('ops.pages.test_kpi_daily.table.failure_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @forelse ($dailyRows as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium text-slate-900">{{ $row['day'] }}</td>
                                <td class="px-3 py-3 font-medium text-slate-900">{{ $row['scale_code'] }}</td>
                                <td class="px-3 py-3 text-slate-600">{{ $row['scale_code_v2'] !== '' ? $row['scale_code_v2'] : 'n/a' }}</td>
                                <td class="px-3 py-3">{{ $row['form_code'] !== '' ? $row['form_code'] : __('ops.pages.test_kpi_daily.default_form') }}</td>
                                <td class="px-3 py-3">{{ $row['locale'] }}</td>
                                <td class="px-3 py-3">{{ $this->formatInt((int) $row['started_attempts']) }}</td>
                                <td class="px-3 py-3">{{ $this->formatInt((int) $row['successful_attempts']) }}</td>
                                <td class="px-3 py-3">{{ $this->formatInt((int) $row['failed_attempts']) }}</td>
                                <td class="px-3 py-3">{{ $this->formatInt((int) $row['total_attempts']) }}</td>
                                <td class="px-3 py-3">{{ $this->formatRate($row['success_rate']) }}</td>
                                <td class="px-3 py-3">{{ $this->formatRate($row['failure_rate']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="px-3 py-4 text-center text-slate-500">{{ __('ops.pages.test_kpi_daily.sections.no_rows') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
