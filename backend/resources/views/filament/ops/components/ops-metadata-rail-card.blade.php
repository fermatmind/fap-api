<div class="ops-metadata-rail-card">
    @if ($pills !== [])
        <div class="ops-metadata-rail-card__pills">
            @foreach ($pills as $pill)
                <x-filament.ops.shared.status-pill :label="$pill['label']" :state="$pill['state']" />
            @endforeach
        </div>
    @endif

    @if ($alerts !== [])
        <div class="ops-metadata-rail-card__alerts">
            @foreach ($alerts as $alert)
                <div class="ops-metadata-rail-card__alert">{{ $alert }}</div>
            @endforeach
        </div>
    @endif

    @if ($rows !== [])
        <dl class="ops-metadata-rail-card__rows">
            @foreach ($rows as [$label, $value])
                <div class="ops-metadata-rail-card__row">
                    <dt>{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
