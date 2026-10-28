<x-filament-widgets::widget>
    <section class="ops-inline-data-section" aria-labelledby="entertainment-statistics-heading">
        <div class="ops-inline-data-section__header">
            <h2 id="entertainment-statistics-heading">{{ __('entertainment-activity.dashboard_title') }}</h2>
        </div>
        <div class="space-y-6">
            @include('filament.ops.components.entertainment-statistics-content', ['inline' => true])
        </div>
    </section>
</x-filament-widgets::widget>
