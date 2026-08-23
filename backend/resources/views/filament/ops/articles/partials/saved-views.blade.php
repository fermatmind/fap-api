<div class="flex flex-col gap-4">
    <x-filament-panels::header
        :actions="$actions"
        :breadcrumbs="$breadcrumbs"
        :heading="$heading"
        :subheading="$subheading"
    />

    @if (! empty($savedViews))
        <x-filament-ops::ops-saved-views
            :views="collect($savedViews)->mapWithKeys(fn (array $view): array => [$view['id'] => [
                'label' => $view['label'],
                'count' => $view['count'],
            ]])->all()"
            :active="$activeSavedView"
            :label="__('ops.resources.articles.saved_views.all')"
        />
    @endif
</div>
