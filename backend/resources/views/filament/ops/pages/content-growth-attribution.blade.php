<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.content_growth_attribution.eyebrow')"
            :title="__('ops.custom_pages.content_growth_attribution.title')"
            :description="__('ops.custom_pages.content_growth_attribution.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.content_growth_attribution.contract_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_growth_attribution.contract_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.overview') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.metrics') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.seo_ops') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_search') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_growth_attribution.dashboard_title')"
            :description="__('ops.custom_pages.content_growth_attribution.dashboard_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_growth_attribution.diagnostics_title')"
            :description="__('ops.custom_pages.content_growth_attribution.diagnostics_desc')"
        >
            <div class="ops-card-list">
                @foreach ($diagnosticCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">{{ __('ops.custom_pages.content_growth_attribution.latest_surface', ['title' => $card['latest_title']]) }}</p>
                        <x-slot name="actions">
                            <x-filament.ops.shared.status-pill
                                :state="$card['status_state']"
                                :label="$card['status'].' | '.$card['value']"
                            />
                        </x-slot>
                    </x-filament-ops::ops-result-card>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_growth_attribution.matrix_title')"
            :description="__('ops.custom_pages.content_growth_attribution.matrix_desc')"
        >
            <x-filament-ops::ops-table
                :has-rows="$matrixRows !== []"
                :empty-eyebrow="__('ops.custom_pages.content_growth_attribution.eyebrow')"
                empty-icon="heroicon-o-presentation-chart-line"
                :empty-title="__('ops.custom_pages.content_growth_attribution.empty_title')"
                :empty-description="__('ops.custom_pages.content_growth_attribution.empty_desc')"
            >
                <x-slot name="head">
                    <tr>
                        <th>{{ __('ops.custom_pages.content_growth_attribution.table.surface') }}</th>
                        <th>{{ __('ops.custom_pages.content_growth_attribution.table.seo') }}</th>
                        <th>{{ __('ops.custom_pages.content_growth_attribution.table.signals') }}</th>
                        <th>{{ __('ops.custom_pages.content_growth_attribution.table.share') }}</th>
                        @if ($showCommerceMetrics)
                            <th>{{ __('ops.custom_pages.content_growth_attribution.table.paid') }}</th>
                            <th>{{ __('ops.custom_pages.content_growth_attribution.table.revenue') }}</th>
                        @endif
                        <th>{{ __('ops.custom_pages.content_growth_attribution.table.latest_touch') }}</th>
                    </tr>
                </x-slot>

                @foreach ($matrixRows as $row)
                    <tr>
                        <td>
                            <div class="ops-control-stack">
                                <span class="ops-control-label">{{ $row['title'] }}</span>
                                <span class="ops-control-hint">{{ $row['type'] }} | {{ $row['scope'] }} | {{ $row['locale'] }}</span>
                                <span class="ops-control-hint">{{ $row['public_path'] }}</span>
                            </div>
                        </td>
                        <td>
                            <x-filament.ops.shared.status-pill
                                :state="$row['seo_state']"
                                :label="$row['growth_state_label']"
                            />
                        </td>
                        <td>{{ $row['signals'] }}</td>
                        <td>
                            <div class="ops-control-stack">
                                <span>{{ __('ops.custom_pages.content_growth_attribution.touchpoints', ['count' => $row['share_touchpoints']]) }}</span>
                                @if ($showCommerceMetrics)
                                    <span class="ops-control-hint">{{ __('ops.custom_pages.content_growth_attribution.assisted_orders', ['count' => $row['share_assisted_orders']]) }}</span>
                                @endif
                            </div>
                        </td>
                        @if ($showCommerceMetrics)
                            <td>{{ $row['paid_orders'] }}</td>
                            <td>{{ $row['revenue_label'] }}</td>
                        @endif
                        <td>
                            <div class="ops-control-stack">
                                <span>{{ $row['last_touch_label'] }}</span>
                                <x-filament::button size="xs" color="gray" tag="a" href="{{ $row['public_url'] }}" target="_blank">
                                    {{ __('ops.custom_pages.content_growth_attribution.view_public') }}
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
