@props([
    'fields' => [],
    'notes' => [],
])

<x-filament-ops::ops-field-grid
    :fields="$fields"
    :notes="$notes"
    empty-description="This section has no summary fields for the current attempt."
    empty-eyebrow="Attempt diagnostics"
    empty-icon="heroicon-o-clipboard-document-list"
    empty-title="No diagnostic fields"
/>
