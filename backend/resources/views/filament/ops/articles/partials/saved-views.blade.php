<div class="flex flex-col gap-4">
    <x-filament-panels::header
        :actions="$actions"
        :breadcrumbs="$breadcrumbs"
        :heading="$heading"
        :subheading="$subheading"
    />

    @if (! empty($savedViews))
        <div class="ops-article-saved-views" role="tablist" aria-label="{{ __('ops.resources.articles.saved_views.all') }}">
            @foreach ($savedViews as $view)
                @php($isActive = ($view['id'] ?? '') === $activeSavedView)
                <button
                    type="button"
                    wire:click="applySavedView('{{ $view['id'] }}')"
                    wire:key="article-saved-view-{{ $view['id'] }}"
                    class="ops-article-chip{{ $isActive ? ' ops-article-chip--active' : '' }}"
                    role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    @if (! empty($view['tone'])) data-tone="{{ $view['tone'] }}" @endif
                >
                    <span class="ops-article-chip__label">{{ $view['label'] }}</span>
                    <span class="ops-article-chip__count tnum">{{ $view['count'] }}</span>
                </button>
            @endforeach
        </div>
    @endif
</div>
