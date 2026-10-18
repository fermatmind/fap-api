@php
    use App\Filament\Ops\Resources\ArticleResource;
    use App\Filament\Ops\Resources\CareerGuideResource;
    use App\Filament\Ops\Resources\CareerJobResource;
    use App\Filament\Ops\Support\ContentAccess;
    use App\Filament\Ops\Support\SeoContentPublishingUiContract;
    use App\Filament\Ops\Support\SeoOperationsUiState;
    use App\Services\SeoIntel\OpsDashboard\ContentLifecycleReadService;

    $copy = 'ops.custom_pages.seo_operations.content_publishing';
    $canReadAuthority = ContentAccess::canRead();
    $snapshot = $canReadAuthority
        ? SeoContentPublishingUiContract::snapshot(app(ContentLifecycleReadService::class)->read(1, 25))
        : SeoContentPublishingUiContract::unavailableSnapshot();
    $authorityUrls = $canReadAuthority
        ? [
            'article' => ArticleResource::getUrl(),
            'career_guide' => CareerGuideResource::getUrl(),
            'career_job' => CareerJobResource::getUrl(),
        ]
        : [];
@endphp

<div class="ops-content-publishing" data-contract-state="{{ $snapshot['state'] }}">
    <header class="ops-content-publishing__header">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.eyebrow') }}</span>
            <h2>{{ __($copy.'.title') }}</h2>
        </div>
        <span class="ops-tag">{{ __('ops.custom_pages.seo_operations.article_workspace.evidence_only') }}</span>
    </header>

    <div
        class="ops-content-publishing__authority"
        aria-label="{{ __($copy.'.authority.label') }}"
        data-authority-access="{{ $canReadAuthority ? 'granted' : 'denied' }}"
        data-write-state="read_only"
    >
        <div>
            <strong>{{ __($copy.'.authority.title') }}</strong>
        </div>
        <div class="ops-content-publishing__authority-links">
            @foreach ($snapshot['authority_types'] as $type)
                @if ($canReadAuthority)
                    <a href="{{ $authorityUrls[$type] }}">{{ __($copy.'.authority.types.'.$type) }}</a>
                @else
                    <span aria-disabled="true">{{ __($copy.'.authority.types.'.$type) }} · {{ __('ops.custom_pages.seo_operations.states.permission_denied.label') }}</span>
                @endif
            @endforeach
        </div>
    </div>

    @include('filament.ops.components.ops-article-content-workspace')

    <section class="ops-inline-data-section" aria-labelledby="content-lifecycle-read-model-title">
        <div class="ops-inline-data-section__header">
            <h3 id="content-lifecycle-read-model-title">{{ __($copy.'.read_model.title') }}</h3>
            <p>{{ __($copy.'.read_model.description') }}</p>
        </div>
        @if ($snapshot['rows'] === [])
            <x-filament-ops::ops-state-message
                :state="$snapshot['state']"
                :title="__($copy.'.read_model.empty')"
                :description="''"
            />
        @else
            <div class="ops-table-shell">
                <table class="ops-table">
                    <thead>
                        <tr>
                            @foreach (['authority', 'locale', 'revision', 'review', 'fingerprint', 'lastmod', 'candidate'] as $column)
                                <th>{{ __($copy.'.read_model.columns.'.$column) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($snapshot['rows'] as $row)
                            <tr>
                                <td>{{ $row['authority_type'] }} · {{ $row['page_family'] }}</td>
                                <td>{{ $row['locale'] }}</td>
                                <td>{{ data_get($row, 'revision.kind') }}<br><code>{{ data_get($row, 'revision.value') }}</code></td>
                                <td>{{ data_get($row, 'review.state') }}<br><small>{{ data_get($row, 'review.reviewed_at') ?? '—' }}</small></td>
                                <td><code>{{ $row['fingerprint'] ?? '—' }}</code></td>
                                <td>{{ $row['material_lastmod'] ?? '—' }}<br><small>{{ $row['material_authority_state'] }}</small></td>
                                <td>{{ data_get($row, 'candidate.status') }}<br><small>{{ data_get($row, 'candidate.recommended_action') ?? '—' }}</small></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ops-server-pagination" data-pagination-state="read-only">
                <span>{{ __('pagination.previous') }}</span>
                <strong>{{ data_get($snapshot, 'pagination.page', 1) }} / {{ data_get($snapshot, 'pagination.last_page', 1) }}</strong>
                <span>{{ __('pagination.next') }}</span>
            </div>
        @endif
    </section>

</div>
