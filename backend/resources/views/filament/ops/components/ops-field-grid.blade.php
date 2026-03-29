@props([
    'cardless' => false,
    'emptyDescription' => 'There is no data available for this section.',
    'emptyEyebrow' => 'Ops diagnostics',
    'emptyIcon' => 'heroicon-o-clipboard-document-list',
    'emptyTitle' => 'No diagnostic fields',
    'fields' => [],
    'notes' => [],
])

<div class="ops-field-grid">
    <div class="ops-field-grid__items">
        @forelse ($fields as $field)
            @if ($cardless)
                <div class="ops-field-grid__flat-item">
                    <p class="ops-result-card__meta">{{ (string) ($field['label'] ?? '-') }}</p>

                    @if (($field['kind'] ?? 'text') === 'pill')
                        <x-filament.ops.shared.status-pill
                            class="mt-1"
                            :state="(string) ($field['state'] ?? 'gray')"
                            :label="(string) ($field['value'] ?? '-')"
                        />
                    @elseif (($field['kind'] ?? 'text') === 'code')
                        <pre class="ops-field-grid__code">{{ (string) ($field['value'] ?? '-') }}</pre>
                    @else
                        <p class="ops-result-card__title break-all">{{ (string) ($field['value'] ?? '-') }}</p>
                    @endif

                    @if (filled($field['hint'] ?? null))
                        <p class="ops-control-hint">{{ (string) $field['hint'] }}</p>
                    @endif
                </div>
            @else
                <x-filament-ops::ops-result-card class="ops-field-grid__item">
                    <p class="ops-result-card__meta">{{ (string) ($field['label'] ?? '-') }}</p>

                    @if (($field['kind'] ?? 'text') === 'pill')
                        <x-filament.ops.shared.status-pill
                            class="mt-1"
                            :state="(string) ($field['state'] ?? 'gray')"
                            :label="(string) ($field['value'] ?? '-')"
                        />
                    @elseif (($field['kind'] ?? 'text') === 'code')
                        <pre class="ops-field-grid__code">{{ (string) ($field['value'] ?? '-') }}</pre>
                    @else
                        <p class="ops-result-card__title break-all">{{ (string) ($field['value'] ?? '-') }}</p>
                    @endif

                    @if (filled($field['hint'] ?? null))
                        <p class="ops-control-hint">{{ (string) $field['hint'] }}</p>
                    @endif
                </x-filament-ops::ops-result-card>
            @endif
        @empty
            <x-filament-ops::ops-empty-state
                :description="$emptyDescription"
                :eyebrow="$emptyEyebrow"
                :icon="$emptyIcon"
                :title="$emptyTitle"
            />
        @endforelse
    </div>

    @if ($notes !== [])
        <div class="ops-field-grid__notes">
            @foreach ($notes as $note)
                <p class="ops-control-hint">{{ (string) $note }}</p>
            @endforeach
        </div>
    @endif
</div>
