<div class="ops-article-workspace-seo">
    <div class="ops-article-workspace-seo__grid">
        @foreach ($checks as $check)
            <div class="ops-article-workspace-seo__item">
                <div class="ops-article-workspace-seo__copy">
                    <span class="ops-article-workspace-seo__label">{{ $check['label'] }}</span>
                    <span class="ops-article-workspace-seo__description">{{ $check['description'] }}</span>
                </div>

                <x-filament.ops.shared.status-pill
                    :label="$check['ready'] ? 'Ready' : 'Missing'"
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
            Open canonical URL
        </a>
    @endif
</div>
