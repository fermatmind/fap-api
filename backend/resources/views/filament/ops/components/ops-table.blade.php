@props([
    'emptyDescription' => 'There is no data available for this table.',
    'emptyEyebrow' => 'Ops data',
    'emptyIcon' => 'heroicon-o-table-cells',
    'emptyTitle' => 'No rows found',
    'hasRows' => false,
])

@if ($hasRows)
    <div {{ $attributes->class(['ops-table-shell']) }}>
        <table class="ops-table">
            @isset($head)
                <thead>
                    {{ $head }}
                </thead>
            @endisset

            <tbody>
                {{ $slot }}
            </tbody>
        </table>
    </div>
@else
    <x-filament-ops::ops-empty-state
        :description="$emptyDescription"
        :eyebrow="$emptyEyebrow"
        :icon="$emptyIcon"
        :title="$emptyTitle"
    />
@endif
