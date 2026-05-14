<x-filament-panels::page>
    <div class="ops-shell-page">
        <x-filament-ops::ops-section
            :eyebrow="__('ops.custom_pages.article_publishing_ops.eyebrow')"
            :title="__('ops.custom_pages.article_publishing_ops.title')"
            :description="__('ops.custom_pages.article_publishing_ops.description')"
        >
            <x-filament-ops::ops-toolbar>
                <div class="ops-control-stack">
                    <span class="ops-control-label">{{ __('ops.custom_pages.article_publishing_ops.contract_label') }}</span>
                    <p class="ops-control-hint">{{ __('ops.custom_pages.article_publishing_ops.contract_hint') }}</p>
                </div>

                <x-slot name="actions">
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Resources\ArticleResource::getUrl() }}">
                        {{ __('ops.nav.articles') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\EditorialReviewPage::getUrl() }}">
                        {{ __('ops.nav.editorial_review') }}
                    </x-filament::button>
                    <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Ops\Pages\PostReleaseObservabilityPage::getUrl() }}">
                        {{ __('ops.nav.post_release_observability') }}
                    </x-filament::button>
                </x-slot>
            </x-filament-ops::ops-toolbar>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.queue_title')"
            :description="__('ops.custom_pages.article_publishing_ops.queue_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$queueFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.daily_title')"
            :description="__('ops.custom_pages.article_publishing_ops.daily_desc')"
        >
            <x-filament-ops::ops-field-grid :fields="$dailyHealthFields" />
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.queue_table_title')"
            :description="__('ops.custom_pages.article_publishing_ops.queue_table_desc')"
        >
            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.article') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.locale') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.track') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.status') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.gates') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.next_action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($queueRows as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row['title'] }}</strong>
                                    <div class="ops-table-meta">/{{ $row['slug'] }}</div>
                                </td>
                                <td>{{ $row['locale'] }}</td>
                                <td>{{ $row['content_track'] }}</td>
                                <td>{{ $row['article_status'] }} · {{ $row['review_status'] }}</td>
                                <td>
                                    {{ __('ops.custom_pages.article_publishing_ops.table.claim') }}: {{ $row['claim_status'] }}<br>
                                    {{ __('ops.custom_pages.article_publishing_ops.table.media') }}: {{ $row['media_status'] }}<br>
                                    {{ __('ops.custom_pages.article_publishing_ops.table.references') }}: {{ $row['references_status'] }}<br>
                                    {{ __('ops.custom_pages.article_publishing_ops.table.graph') }}: {{ $row['graph_status'] }}
                                </td>
                                <td>{{ $row['next_action'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">{{ __('ops.custom_pages.article_publishing_ops.empty_queue') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.import_table_title')"
            :description="__('ops.custom_pages.article_publishing_ops.import_table_desc')"
        >
            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.package') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.status') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.claim') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.references') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.body_hash') }}</th>
                            <th>{{ __('ops.custom_pages.article_publishing_ops.table.imported_at') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recentImportRows as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row['title'] }}</strong>
                                    <div class="ops-table-meta">/{{ $row['slug'] }} · {{ $row['locale'] }} · {{ $row['content_track'] }}</div>
                                </td>
                                <td>{{ $row['status'] }}</td>
                                <td>{{ $row['claim_status'] }}</td>
                                <td>{{ $row['references_count'] }}</td>
                                <td>{{ $row['body_hash'] }}</td>
                                <td>{{ $row['created_at'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">{{ __('ops.custom_pages.article_publishing_ops.empty_imports') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.release_failure_title')"
            :description="__('ops.custom_pages.article_publishing_ops.release_failure_desc')"
        >
            <div class="ops-card-list">
                @forelse ($releaseRows as $row)
                    <x-filament-ops::ops-result-card :title="$row['title']" :meta="$row['target']">
                        <p class="ops-control-hint">{{ $row['action'] }} · {{ $row['result'] }} · {{ $row['created_at'] }}</p>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.article_publishing_ops.eyebrow')"
                        icon="heroicon-o-check-circle"
                        :title="__('ops.custom_pages.article_publishing_ops.empty_release_title')"
                        :description="__('ops.custom_pages.article_publishing_ops.empty_release_desc')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>

        <x-filament-ops::ops-section
            :title="__('ops.custom_pages.article_publishing_ops.review_due_title')"
            :description="__('ops.custom_pages.article_publishing_ops.review_due_desc')"
        >
            <div class="ops-card-list">
                @forelse ($reviewDueRows as $row)
                    <x-filament-ops::ops-result-card :title="$row['title']" :meta="$row['locale'].' · '.$row['age_days'].'d'">
                        <p class="ops-control-hint">/{{ $row['slug'] }} · {{ $row['published_at'] }}</p>
                    </x-filament-ops::ops-result-card>
                @empty
                    <x-filament-ops::ops-empty-state
                        :eyebrow="__('ops.custom_pages.article_publishing_ops.eyebrow')"
                        icon="heroicon-o-calendar-days"
                        :title="__('ops.custom_pages.article_publishing_ops.empty_review_title')"
                        :description="__('ops.custom_pages.article_publishing_ops.empty_review_desc')"
                    />
                @endforelse
            </div>
        </x-filament-ops::ops-section>
    </div>
</x-filament-panels::page>
