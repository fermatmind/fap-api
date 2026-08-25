<x-filament-widgets::widget>
    <section class="ops-inline-data-section">
        <div class="ops-inline-data-section__header">
            <h2>{{ __('ops.widgets.test_kpi_daily_detail') }}</h2>
        </div>

        @if ($warning !== null)
            <div class="ops-inline-data-section__warning">
                {{ $warning }}
            </div>
        @else
            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('ops.pages.test_kpi_daily.table.day') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.scale') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.scale_v2') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.form') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.locale') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.started') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.successful') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.failed') }}</th>
                            <th>{{ __('ops.pages.test_kpi_daily.table.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row['day'] }}</td>
                                <td>{{ $row['scale_code'] }}</td>
                                <td>{{ $row['scale_code_v2'] !== '' ? $row['scale_code_v2'] : 'n/a' }}</td>
                                <td>{{ $row['form_code'] !== '' ? $row['form_code'] : __('ops.pages.test_kpi_daily.default_form') }}</td>
                                <td>{{ $row['locale'] }}</td>
                                <td>{{ $this->formatInt((int) $row['started_attempts']) }}</td>
                                <td>{{ $this->formatInt((int) $row['successful_attempts']) }}</td>
                                <td>{{ $this->formatInt((int) $row['failed_attempts']) }}</td>
                                <td>{{ $this->formatInt((int) $row['total_attempts']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-filament-widgets::widget>
