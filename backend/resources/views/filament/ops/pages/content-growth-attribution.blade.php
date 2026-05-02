<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            eyebrow="Growth attribution"
            title="Content growth attribution"
            description="Tie public content SEO posture to share propagation, attributed entry signals, and paid conversion outcomes inside the selected org boundary."
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">Attribution contract</span>
                    <p class="ops-control-hint">This surface matches visible CMS content against attributed landing paths, share touchpoints, and paid orders. It does not rewrite checkout or payment data models.</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentOverviewPage::getUrl() }}">
                        Overview
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        Content Metrics
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        SEO Operations
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        Content Search
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Growth dashboard"
            description="Headline attribution signals across public CMS content in the last 30 days."
        >
            <x-filament-ops::ops-field-grid :fields="$headlineFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            title="Growth diagnostics"
            description="Use these queues to spot SEO-to-growth mismatches and the content surfaces already proving conversion value."
        >
            <div class="ops-card-list">
                @foreach ($diagnosticCards as $card)
                    <x-filament-ops::ops-result-card
                        :title="$card['title']"
                        :meta="$card['meta']"
                    >
                        <p class="ops-control-hint">{{ $card['description'] }}</p>
                        <p class="ops-control-hint">Latest surface: {{ $card['latest_title'] }}</p>
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
            title="Attribution matrix"
            description="Public content surfaces ranked by paid conversions, discovery signals, and share-assisted outcomes."
        >
            <x-filament-ops::ops-table
                :has-rows="$matrixRows !== []"
                empty-eyebrow="Growth attribution"
                empty-icon="heroicon-o-presentation-chart-line"
                empty-title="No attributed content signals yet"
                empty-description="Once public content starts receiving attributed traffic or orders, the matrix will appear here."
            >
                <x-slot name="head">
                    <tr>
                        <th>Surface</th>
                        <th>SEO</th>
                        <th>Signals</th>
                        <th>Share</th>
                        @if ($showCommerceMetrics)
                            <th>Paid</th>
                            <th>Revenue</th>
                        @endif
                        <th>Latest touch</th>
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
                                <span>{{ $row['share_touchpoints'] }} touchpoints</span>
                                @if ($showCommerceMetrics)
                                    <span class="ops-control-hint">{{ $row['share_assisted_orders'] }} assisted orders</span>
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
                                    View Public
                                </x-filament::button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-filament-ops::ops-table>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
