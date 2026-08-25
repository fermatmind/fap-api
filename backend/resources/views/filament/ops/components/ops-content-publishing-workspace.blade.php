@php
    use App\Filament\Ops\Resources\ArticleResource;
    use App\Filament\Ops\Resources\CareerGuideResource;
    use App\Filament\Ops\Resources\CareerJobResource;
    use App\Filament\Ops\Support\ContentAccess;
    use App\Filament\Ops\Support\SeoContentPublishingUiContract;
    use App\Filament\Ops\Support\SeoOperationsUiState;

    $snapshot = SeoContentPublishingUiContract::unavailableSnapshot();
    $copy = 'ops.custom_pages.seo_operations.content_publishing';
    $canReadAuthority = ContentAccess::canRead();
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
            <p>{{ __($copy.'.description') }}</p>
        </div>
        <span class="ops-tag">#10 · {{ __('ops.custom_pages.seo_operations.states.production_unproven.label') }}</span>
    </header>

    <div class="ops-content-publishing__authority" aria-label="{{ __($copy.'.authority.label') }}">
        <div>
            <strong>{{ __($copy.'.authority.title') }}</strong>
            <small>{{ __($copy.'.authority.description') }}</small>
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
        @foreach (['content_type', 'page_family', 'locale', 'revision', 'version_diff', 'public_preview', 'submit_review'] as $field)
            <div>
                <span>{{ __($copy.'.context.'.$field) }}</span>
                <strong>{{ SeoOperationsUiState::metricValue(null, SeoOperationsUiState::UNAVAILABLE) }}</strong>
            </div>
        @endforeach
    </div>

    <div class="ops-content-publishing__layout">
        <section class="ops-content-publishing__editor" aria-labelledby="content-structure-title">
            <div class="ops-seo-section-heading">
                <div>
                    <span class="ops-shell-eyebrow">{{ __($copy.'.editor.eyebrow') }}</span>
                    <h3 id="content-structure-title">{{ __($copy.'.editor.title') }}</h3>
                    <p>{{ __($copy.'.editor.description') }}</p>
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
            <p>{{ __($copy.'.preview.description') }}</p>
            <div class="ops-content-publishing__devices" aria-label="{{ __($copy.'.preview.devices_label') }}">
                @foreach ($snapshot['preview_devices'] as $device)
                    <span aria-disabled="true">{{ __($copy.'.preview.devices.'.$device) }}</span>
                @endforeach
            </div>
            <x-filament-ops::ops-state-message
                :state="SeoOperationsUiState::UNAVAILABLE"
                :title="__('ops.custom_pages.seo_operations.states.unavailable.label')"
                :description="__($copy.'.preview.hold')"
            />
        </aside>
    </div>

    <footer class="ops-content-publishing__release" aria-labelledby="content-release-title">
        <div>
            <span class="ops-shell-eyebrow">{{ __($copy.'.release.eyebrow') }}</span>
            <h3 id="content-release-title">{{ __($copy.'.release.title') }}</h3>
            <p>{{ __($copy.'.release.description') }}</p>
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
            @foreach (['saved_at', 'review_state', 'material_lastmod'] as $field)
                <div>
                    <dt>{{ __($copy.'.release.fields.'.$field) }}</dt>
                    <dd>{{ SeoOperationsUiState::metricValue($snapshot[$field], $snapshot['state']) }}</dd>
                </div>
            @endforeach
        </dl>
        <x-filament-ops::ops-state-message
            :state="$snapshot['state']"
            :title="__($copy.'.hold_title')"
            :description="__($copy.'.hold_description')"
        />
        <p class="ops-control-hint">{{ __($copy.'.release.lastmod_rule') }}</p>
    </footer>
</div>
