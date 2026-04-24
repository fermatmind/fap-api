<div class="ops-article-workspace-seo">
    <div class="ops-article-workspace-seo__grid">
        @foreach ($checks as $check)
            <div class="ops-article-workspace-seo__item">
                <div class="ops-article-workspace-seo__copy">
                    <span class="ops-article-workspace-seo__label">{{ $check['label'] }}</span>
                    <span class="ops-article-workspace-seo__description">{{ $check['description'] }}</span>
                </div>

                <x-filament.ops.shared.status-pill
                    :label="$check['ready'] ? __('ops.status.ready') : __('ops.status.missing')"
                    :state="$check['ready'] ? 'ready' : 'draft'"
                />
            </div>
        @endforeach
    </div>

    @if (filled($canonicalUrl))
        <a
            class="ops-article-workspace-seo__link"
            href="{{ $canonicalUrl }}"
            target="_blank"
            rel="noreferrer"
        >
            {{ __('ops.resources.common.fields.open_canonical_url') }}
        </a>
    @endif
</div>
