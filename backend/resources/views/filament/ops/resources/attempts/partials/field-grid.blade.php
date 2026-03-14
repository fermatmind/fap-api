@props([
    'fields' => [],
    'notes' => [],
])

<div class="space-y-4">
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($fields as $field)
            <div class="ops-result-card">
                <p class="ops-result-card__meta">{{ (string) ($field['label'] ?? '-') }}</p>

                @if (($field['kind'] ?? 'text') === 'pill')
                    <x-filament.ops.shared.status-pill
                        :state="(string) ($field['state'] ?? 'gray')"
                        :label="(string) ($field['value'] ?? '-')"
                    />
                @else
                    <p class="ops-result-card__title break-all">{{ (string) ($field['value'] ?? '-') }}</p>
                @endif

                @if (filled($field['hint'] ?? null))
                    <p class="ops-control-hint">{{ (string) $field['hint'] }}</p>
                @endif
            </div>
        @empty
            <x-filament.ops.shared.empty-state
                eyebrow="Attempt diagnostics"
                icon="heroicon-o-clipboard-document-list"
                title="No diagnostic fields"
                description="This section has no summary fields for the current attempt."
            />
        @endforelse
    </div>

    @if ($notes !== [])
        <div class="space-y-2">
            @foreach ($notes as $note)
                <p class="ops-control-hint">{{ (string) $note }}</p>
            @endforeach
        </div>
    @endif
</div>
