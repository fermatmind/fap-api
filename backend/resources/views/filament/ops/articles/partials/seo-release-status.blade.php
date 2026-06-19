<div class="ops-article-workspace-seo-release">
    <div class="ops-article-workspace-seo-release__header">
        <x-filament.ops.shared.status-pill
            :label="$decision"
            :state="$ok ? 'complete' : 'danger'"
        />

        @if (filled($canonicalUrl))
            <a
                class="ops-article-workspace-seo-release__link"
                href="{{ $canonicalUrl }}"
                target="_blank"
                rel="noreferrer"
            >
                Open public URL
            </a>
        @endif
    </div>

    <div class="ops-article-workspace-seo__grid">
        @foreach ($checks as $check)
            <div class="ops-article-workspace-seo__item">
                <div class="ops-article-workspace-seo__copy">
                    <span class="ops-article-workspace-seo__label">{{ $check['label'] }}</span>
                    <span class="ops-article-workspace-seo__description">{{ $check['summary'] }}</span>

                    @if ($check['issues'] > 0)
                        <span class="ops-article-workspace-seo__description">
                            {{ $check['issues'] }} issue{{ $check['issues'] === 1 ? '' : 's' }}
                        </span>
                    @endif
                </div>

                <x-filament.ops.shared.status-pill
                    :label="$check['state']"
                    :state="$check['state']"
                />
            </div>
        @endforeach
    </div>

    @if ($issues !== [])
        <details class="ops-article-workspace-seo-release__details">
            <summary>Closeout issues</summary>

            <ul>
                @foreach ($issues as $issue)
                    <li>
                        <code>{{ $issue['code'] ?? 'unknown_issue' }}</code>
                        {{ $issue['message'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    <code class="ops-article-workspace-seo-release__command">{{ $command }}</code>
</div>
