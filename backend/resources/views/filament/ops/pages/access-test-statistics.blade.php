<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit.prevent="applyFilters" class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
            <div class="mb-4 flex flex-wrap gap-2">
                <x-filament::button type="button" color="gray" wire:click="selectToday">{{ __('ops.pages.access_test_statistics.ranges.today') }}</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="selectYesterday">{{ __('ops.pages.access_test_statistics.ranges.yesterday') }}</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="selectRange(7)">{{ __('ops.pages.access_test_statistics.ranges.seven_days') }}</x-filament::button>
                <x-filament::button type="button" color="gray" wire:click="selectRange(30)">{{ __('ops.pages.access_test_statistics.ranges.thirty_days') }}</x-filament::button>
            </div>

            <div class="grid gap-4 xl:grid-cols-[repeat(6,minmax(0,1fr))_auto]">
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.from') }}</span><input type="date" wire:model.defer="fromDate" class="block w-full rounded-xl border-slate-300 text-sm" /></label>
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.to') }}</span><input type="date" wire:model.defer="toDate" class="block w-full rounded-xl border-slate-300 text-sm" /></label>
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.scope') }}</span><select wire:model.defer="scope" class="block w-full rounded-xl border-slate-300 text-sm"><option value="global_org0">{{ __('ops.pages.access_test_statistics.filters.global') }}</option><option value="current_org">{{ __('ops.pages.access_test_statistics.filters.current_org') }}</option></select></label>
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.scale') }}</span><select wire:model.defer="scaleCode" class="block w-full rounded-xl border-slate-300 text-sm"><option value="*">{{ __('ops.pages.access_test_statistics.filters.all') }}</option>@foreach ($scaleOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.form') }}</span><select wire:model.defer="formCode" class="block w-full rounded-xl border-slate-300 text-sm"><option value="*">{{ __('ops.pages.access_test_statistics.filters.all') }}</option>@foreach ($formOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <label class="space-y-2"><span class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.filters.locale') }}</span><select wire:model.defer="locale" class="block w-full rounded-xl border-slate-300 text-sm"><option value="*">{{ __('ops.pages.access_test_statistics.filters.all') }}</option>@foreach ($localeOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                <div class="flex items-end gap-2"><x-filament::button type="submit">{{ __('ops.custom_pages.common.actions.apply') }}</x-filament::button><x-filament::button type="button" color="gray" wire:click="exportCsv">CSV</x-filament::button></div>
            </div>
        </form>

        @foreach ($warnings as $warning)
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ $warning }}</div>
        @endforeach

        @if ($summary !== [])
            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div><h2 class="text-lg font-semibold text-slate-950">{{ __('ops.pages.access_test_statistics.sections.summary') }}</h2><p class="mt-1 text-sm text-slate-500">{{ $meta['is_multi_day'] ? __('ops.pages.access_test_statistics.daily_sum_note') : __('ops.pages.access_test_statistics.daily_unique_note') }}</p></div>
                    <div class="text-right text-xs text-slate-500">{{ $meta['timezone'] }} · {{ $meta['coverage'] }}<br>{{ __('ops.pages.access_test_statistics.last_refresh') }} {{ $meta['last_successful_refresh_at'] ?: 'n/a' }}</div>
                </div>
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($summary as $key => $value)
                        <article class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4"><div class="text-xs font-semibold text-slate-500">{{ __('ops.pages.access_test_statistics.metrics.'.$key) }}</div><div class="mt-3 text-2xl font-semibold text-slate-950">{{ $this->formatInt((int) $value) }}</div></article>
                    @endforeach
                </div>
                <div class="mt-4 text-xs text-slate-500">{{ __('ops.pages.access_test_statistics.data_through') }} {{ $meta['data_through_at'] ?: 'n/a' }} · {{ __('ops.pages.access_test_statistics.rule_version') }} {{ $meta['source_version'] }}</div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold text-slate-950">{{ __('ops.pages.access_test_statistics.sections.trend') }}</h2>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500"><tr><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.table.day') }}</th>@foreach (array_keys($summary) as $key)<th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.metrics.'.$key) }}</th>@endforeach<th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.table.coverage') }}</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($dailyRows as $row)<tr><td class="px-3 py-3 font-medium">{{ $row['day'] }}</td>@foreach (array_keys($summary) as $key)<td class="px-3 py-3">{{ $this->formatInt((int) $row[$key]) }}</td>@endforeach<td class="px-3 py-3">{{ $row['coverage_status'] }}</td></tr>@endforeach</tbody></table></div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white/95 p-5 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold text-slate-950">{{ __('ops.pages.access_test_statistics.sections.by_test') }}</h2>
                <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm"><thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500"><tr><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.table.day') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.filters.scale') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.metrics.started_test_ips') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.metrics.completed_test_ips') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.metrics.successful_attempts') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.table.missing_ip') }}</th><th class="px-3 py-3">{{ __('ops.pages.access_test_statistics.table.excluded') }}</th></tr></thead><tbody class="divide-y divide-slate-100">@forelse ($testRows as $row)<tr><td class="px-3 py-3">{{ $row['day'] }}</td><td class="px-3 py-3 font-medium">{{ $row['scale_code'] }}</td><td class="px-3 py-3">{{ $row['started_test_ips'] }}</td><td class="px-3 py-3">{{ $row['completed_test_ips'] }}</td><td class="px-3 py-3">{{ $row['successful_attempts'] }}</td><td class="px-3 py-3">{{ $row['missing_started_ip_attempts'] + $row['missing_completed_ip_attempts'] }}</td><td class="px-3 py-3">{{ $row['excluded_started_attempts'] + $row['excluded_completed_attempts'] }}</td></tr>@empty<tr><td colspan="7" class="px-3 py-4 text-center text-slate-500">{{ __('ops.pages.access_test_statistics.no_data') }}</td></tr>@endforelse</tbody></table></div>
                <p class="mt-4 text-xs text-slate-500">{{ __('ops.pages.access_test_statistics.suspected_note') }}</p>
            </section>
        @endif
    </div>
</x-filament-panels::page>
