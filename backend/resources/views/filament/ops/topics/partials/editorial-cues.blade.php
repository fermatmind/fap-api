<div class="ops-topic-workspace-cues">
    <div class="ops-topic-workspace-cues__pills">
        @foreach ($pills as $pill)
            <x-filament.ops.shared.status-pill :label="$pill['label']" :state="$pill['state']" />
        @endforeach
    </div>

    @if ($facts !== [])
        <dl class="ops-topic-workspace-cues__facts">
            @foreach ($facts as $fact)
                <div class="ops-topic-workspace-cues__fact">
                    <dt>{{ $fact['label'] }}</dt>
                    <dd>{{ $fact['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
