<x-filament-widgets::widget class="ops-dashboard-action-queue">
    <x-filament::section
        :heading="__('ops.widgets.action_queue.title')"
    >
        @if ($rows === [])
            <x-filament-ops::ops-not-connected
                :title="__('ops.widgets.action_queue.unavailable')"
                :description="__('ops.widgets.action_queue.unavailable_hint')"
            />
        @else
            <div class="ops-action-queue" role="list">
                @foreach ($rows as $row)
                    <a href="{{ $row['url'] }}" class="ops-action-queue__row" role="listitem">
                        <span @class(['ops-action-queue__signal', 'ops-action-queue__signal--'.$row['tone']]) aria-hidden="true"></span>
                        <span class="ops-action-queue__body">
                            <strong>{{ $row['label'] }}</strong>
                        </span>
                        <span class="ops-action-queue__count tnum">{{ $row['count'] }}</span>
                        <x-heroicon-m-chevron-right class="ops-action-queue__arrow" />
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
