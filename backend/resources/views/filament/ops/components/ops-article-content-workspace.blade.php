@php
    $articleCopy = 'ops.custom_pages.seo_operations.article_workspace';
    $articleWorkspace = app(\App\Filament\Ops\Support\ArticleContentWorkspace::class)->read($this->contentArticleId, $this->localeFilter);
    $article = $articleWorkspace['selected'];
@endphp
<section class="ops-inline-data-section" aria-label="{{ __($articleCopy.'.title') }}" data-article-workspace="{{ $articleWorkspace['state'] }}">
    <div class="ops-inline-data-section__header">
        <h3>{{ __($articleCopy.'.title') }}</h3>
        <p>{{ __($articleCopy.'.description') }}</p>
    </div>
    @if ($articleWorkspace['articles'] !== [])
        <label class="ops-seo-commandbar__field">
            {{ __($articleCopy.'.select') }}
            <select wire:model.live="contentArticleId" aria-label="{{ __($articleCopy.'.select') }}">
                <option value="0">{{ __($articleCopy.'.latest') }}</option>
                @foreach ($articleWorkspace['articles'] as $option)
                    <option value="{{ $option['id'] }}">{{ $option['title'] }} · {{ $option['locale'] }} · #{{ $option['id'] }}</option>
                @endforeach
            </select>
        </label>
    @endif
    @if (($article['state'] ?? null) === 'available')
        <h3>{{ $article['title'] }}</h3>
        <p>{{ $article['locale'] }} · /{{ $article['slug'] }} · Article #{{ $article['id'] }}</p>
        <div class="ops-content-publishing__authority-links">
            @if ($article['edit_url'])
                <a href="{{ $article['edit_url'] }}">{{ __($articleCopy.'.edit') }}</a>
            @endif
            @if ($article['preview_url'])
                <a href="{{ $article['preview_url'] }}" target="_blank" rel="noopener noreferrer">{{ __($articleCopy.'.preview') }} #{{ $article['working_revision_id'] }}</a>
            @endif
            <a href="{{ \App\Filament\Ops\Pages\ArticlePublishingOpsPage::getUrl() }}">{{ __($articleCopy.'.publishing') }}</a>
            @if ($article['public_url'])
                <a href="{{ $article['public_url'] }}" target="_blank" rel="noopener noreferrer">{{ __($articleCopy.'.public') }}</a>
            @endif
        </div>
        <dl class="ops-content-publishing__context">
            @foreach (['working_revision_id', 'published_revision_id', 'revision_status', 'saved_at'] as $field)
                <div><dt>{{ __($articleCopy.'.'.$field) }}</dt><dd>{{ $article[$field] ?? '—' }}</dd></div>
            @endforeach
        </dl>
        <p><strong>{{ __($articleCopy.'.diff') }}</strong>:
            @forelse ($article['changed_fields'] as $field)
                <span>{{ __($articleCopy.'.fields.'.$field) }}{{ $loop->last ? '' : ' · ' }}</span>
            @empty
                {{ $article['working_revision_id'] ? __($articleCopy.'.unchanged') : __($articleCopy.'.missing_revision') }}
            @endforelse
        </p>
        <div class="ops-content-publishing__checks">
            <strong>{{ __($articleCopy.'.checks_title') }}</strong>
            <ul>
                @foreach ($article['checks'] as $check => $passed)
                    <li><span>{{ __($articleCopy.'.checks.'.$check) }}</span><small>{{ __($articleCopy.($passed ? '.passed' : '.needs_attention')) }}</small></li>
                @endforeach
            </ul>
        </div>
        <p>{{ __($articleCopy.'.publication_state') }}: <strong>{{ __($articleCopy.'.'.$article['publication_state']) }}</strong></p>
        <x-filament::button wire:click="inspectArticlePublication" wire:loading.attr="disabled" wire:target="inspectArticlePublication" color="gray">
            {{ __($articleCopy.'.preflight') }}
        </x-filament::button>
        @if (($this->articlePublicationPreflight['article_id'] ?? null) === $article['id'] && ($this->articlePublicationPreflight['fingerprint'] ?? null) === $article['preview_fingerprint'])
            <p role="status">{{ __($articleCopy.'.preflight') }} · #{{ $this->articlePublicationPreflight['revision_id'] }}:
                {{ __($articleCopy.'.'.$this->articlePublicationPreflight['state']) }}
            </p>
            @if ($this->articlePublicationPreflight['issues'] !== [])
                <ul>
                    @foreach ($this->articlePublicationPreflight['issues'] as $issue)
                        <li><code>{{ $issue }}</code></li>
                    @endforeach
                </ul>
            @endif
        @endif
        <p>{{ __($articleCopy.'.publish_boundary') }}</p>
    @else
        <p role="status">{{ __($articleCopy.'.'.($article['state'] ?? $articleWorkspace['state'])) }}</p>
    @endif
</section>
