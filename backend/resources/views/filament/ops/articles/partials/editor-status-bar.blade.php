@php
    $order = array_keys($steps);
    $activeIndex = (int) ($activeIndex ?? 0);
@endphp

<div class="ops-editor-sticky-header">
    <x-filament-panels::header
        :actions="$actions"
        :breadcrumbs="$breadcrumbs"
        :heading="$heading"
        :subheading="$subheading"
    />

    <div class="ops-editor-status-bar" role="status" aria-label="{{ __('ops.resources.articles.editor_status_label') }}">
        <ol class="ops-editor-status-bar__track">
            @foreach ($steps as $key => $label)
                @php
                    $index = array_search($key, $order, true);
                    $state = $index < $activeIndex ? 'done' : ($index === $activeIndex ? 'active' : 'todo');
                @endphp
                <li class="ops-editor-status-bar__step ops-editor-status-bar__step--{{ $state }}">
                    <span class="ops-editor-status-bar__dot">{{ $index < $activeIndex ? '✓' : $index + 1 }}</span>
                    <span class="ops-editor-status-bar__label">{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    </div>
</div>
