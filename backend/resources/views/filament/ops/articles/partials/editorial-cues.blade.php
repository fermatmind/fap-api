<div class="ops-article-workspace-cues">
    <div class="ops-article-workspace-cues__pills">
        @foreach ($pills as $pill)
            <x-filament.ops.shared.status-pill :label="$pill['label']" :state="$pill['state']" />
        @endforeach
    </div>

    @if ($facts !== [])
        <dl class="ops-article-workspace-cues__facts">
            @foreach ($facts as $fact)
                <div class="ops-article-workspace-cues__fact">
                    <dt>{{ $fact['label'] }}</dt>

                    <dd>
                        @if (filled($fact['href'] ?? null))
                            <a href="{{ $fact['href'] }}" target="_blank" rel="noreferrer">
                                {{ $fact['value'] }}
                            </a>
                        @else
                            {{ $fact['value'] }}
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>
    @endif
</div>
