<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.content_overview.eyebrow')"
            :title="__('ops.custom_pages.content_overview.title')"
            :description="__('ops.custom_pages.content_overview.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.content_overview.contract_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.content_overview.contract_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.editorial_ops') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentMetricsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_metrics') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentGrowthAttributionPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.growth_attribution') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\SeoOperationsPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.seo_operations') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentSearchPage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.content_search') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.editorial_review') }}
                        </x-filament::button>
                        <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\PostReleaseObservabilityPage::getUrl() }}">
                            {{ __('ops.custom_pages.common.nav.observability') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\ContentWorkspacePage::getUrl() }}">
                        {{ __('ops.custom_pages.common.nav.workspace') }}
                    </x-filament::button>
                    @if (\App\Filament\Ops\Support\ContentAccess::canRelease())
                        <x-filament::button color="primary" tag="a" href="{{ \App\Filament\Ops\Pages\ContentReleasePage::getUrl() }}">
                            {{ __('ops.custom_pages.content_overview.release_action') }}
                        </x-filament::button>
                    @endif
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        {{-- 内容生命周期：草稿 / 审阅中 / 已排期 / 已发布 / 需更新 --}}
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_overview.lifecycle.title')"
            :description="__('ops.custom_pages.content_overview.lifecycle.hint')"
        >
            <x-filament-ops::ops-data-strip
                :label="__('ops.custom_pages.content_overview.lifecycle.title')"
                :metrics="collect($lifecycleStages)->map(fn (array $stage): array => [
                    'label' => $stage['label'],
                    'value' => $stage['count'],
                    'tone' => $stage['tone'],
                ])->all()"
            />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_overview.health_title')"
            :description="__('ops.custom_pages.content_overview.health_desc')"
        >
            <x-filament-ops::ops-data-strip
                :metrics="collect($summaryFields)->map(fn (array $field): array => [
                    'label' => $field['label'],
                    'value' => $field['value'],
                    'meta' => $field['hint'],
                ])->all()"
            />
        </x-filament-ops::ops-section>

        {{-- 内容库存：按类型横向条形图 --}}
        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_overview.inventory.title')"
        >
            <div class="ops-inventory-grid">
                @foreach ($inventoryByType as $item)
                    <div class="ops-inventory-row">
                        <span class="ops-inventory-row__label">{{ $item['label'] }}</span>
                        <span class="ops-inventory-row__bar" aria-hidden="true">
                            <span class="ops-inventory-row__fill" style="width: {{ $item['percent'] }}%"></span>
                        </span>
                        <span class="ops-inventory-row__count tnum">{{ $item['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.content_overview.recent_title')"
            :description="__('ops.custom_pages.content_overview.recent_desc')"
        >
            @if ($recentItems === [])
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.content_overview.title')"
                        icon="heroicon-o-clipboard-document-list"
                        :title="__('ops.custom_pages.content_overview.empty_title')"
                        :description="__('ops.custom_pages.content_overview.empty_desc')"
                    />
            @else
                <div class="ops-table-shell">
                    <table class="ops-table">
                        <thead>
                            <tr>
                                <th>{{ __('ops.table.record') }}</th>
                                <th>{{ __('ops.table.scope') }}</th>
                                <th>{{ __('ops.table.updated') }}</th>
                                <th aria-label="{{ __('ops.custom_pages.common.actions.open') }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentItems as $item)
                                <tr>
                                    <td><strong>{{ $item['title'] }}</strong></td>
                                    <td>{{ $item['label'] }}</td>
                                    <td>{{ $item['meta'] }}</td>
                                    <td><a class="ops-link" href="{{ $item['url'] }}">{{ __('ops.custom_pages.common.actions.open') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
