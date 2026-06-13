<x-filament-widgets::widget>
    <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="mb-4">
            <h2 class="text-base font-semibold text-gray-950">{{ __('ops.widgets.test_kpi_daily_detail') }}</h2>
            <p class="mt-1 text-sm text-gray-500">{{ __('ops.widgets.test_kpi_daily_detail_desc') }}</p>
        </div>

        @if ($warning !== null)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ $warning }}
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500">
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
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $row['day'] }}</td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $row['scale_code'] }}</td>
                                <td class="px-3 py-3 text-gray-600">{{ $row['scale_code_v2'] !== '' ? $row['scale_code_v2'] : 'n/a' }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $row['form_code'] !== '' ? $row['form_code'] : __('ops.pages.test_kpi_daily.default_form') }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $row['locale'] }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $this->formatInt((int) $row['started_attempts']) }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $this->formatInt((int) $row['successful_attempts']) }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ $this->formatInt((int) $row['failed_attempts']) }}</td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ $this->formatInt((int) $row['total_attempts']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
