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
        <span class="ops-tag">#10 · {{ __('ops.custom_pages.seo_operations.states.'.$snapshot['state'].'.label') }}</span>
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

    <div class="ops-content-publishing__context" aria-label="{{ __($copy.'.context_label') }}">
        @php
            $selected = $snapshot['rows'][0] ?? [];
            $contextValues = [
                'content_type' => $selected['authority_type'] ?? null,
                'page_family' => $selected['page_family'] ?? null,
                'locale' => $selected['locale'] ?? null,
                'revision' => data_get($selected, 'revision.value'),
                'version_diff' => null,
                'public_preview' => null,
                'submit_review' => null,
            ];
        @endphp
        @foreach ($contextValues as $field => $value)
            <div>
                <span>{{ __($copy.'.context.'.$field) }}</span>
                <strong>{{ SeoOperationsUiState::metricValue($value, $snapshot['state']) }}</strong>
            </div>
        @endforeach
    </div>

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

    <div class="ops-content-publishing__layout">
        <section class="ops-content-publishing__editor" aria-labelledby="content-structure-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.editor.eyebrow') }}</span>
                    <h3 id="content-structure-title">{{ __($copy.'.editor.title') }}</h3>
                </div>
            </div>
            <div class="ops-content-publishing__groups">
                @foreach ($snapshot['field_groups'] as $group)
                    <div>
                        <strong>{{ __($copy.'.editor.groups.'.$group) }}</strong>
                        <span>{{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</span>
                    </div>
                @endforeach
            </div>

            <div class="ops-content-publishing__checks">
                <strong>{{ __($copy.'.checks.title') }}</strong>
                <ul>
                    @foreach ($snapshot['seo_checks'] as $check)
                        <li>
                            <span>{{ __($copy.'.checks.items.'.$check) }}</span>
                            <small>{{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</small>
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>

        <aside class="ops-content-publishing__preview" aria-labelledby="content-preview-title">
            <span class="ops-shell-eyebrow">{{ __($copy.'.preview.eyebrow') }}</span>
            <h3 id="content-preview-title">{{ __($copy.'.preview.title') }}</h3>
            <div class="ops-content-publishing__devices" aria-label="{{ __($copy.'.preview.devices_label') }}">
                @foreach ($snapshot['preview_devices'] as $device)
                    <span aria-disabled="true">{{ __($copy.'.preview.devices.'.$device) }}</span>
                @endforeach
            </div>
            <x-filament-ops::ops-state-message
                :state="SeoOperationsUiState::UNAVAILABLE"
                :title="__('ops.custom_pages.seo_operations.states.unavailable.label')"
                :description="''"
            />
        </aside>
    </div>

    <footer class="ops-content-publishing__release" aria-labelledby="content-release-title">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.release.eyebrow') }}</span>
            <h3 id="content-release-title">{{ __($copy.'.release.title') }}</h3>
        </div>
        <ol>
            @foreach ($snapshot['lifecycle'] as $stage)
                <li>
                    <span>{{ $loop->iteration }}</span>
                    <strong>{{ __($copy.'.release.stages.'.$stage) }}</strong>
                    <small>{{ __('ops.custom_pages.seo_operations.states.unavailable.label') }}</small>
                </li>
            @endforeach
        </ol>
        <dl>
            @foreach (['saved_at', 'review_state', 'material_lastmod', 'candidate_state'] as $field)
                <div>
                    <dt>{{ __($copy.'.release.fields.'.$field) }}</dt>
                    <dd>{{ SeoOperationsUiState::metricValue($snapshot[$field], $snapshot['state']) }}</dd>
                </div>
            @endforeach
        </dl>
        <x-filament-ops::ops-state-message
            :state="$snapshot['state']"
            :title="__($copy.'.hold_title')"
            :description="''"
        />
    </footer>
</div>
