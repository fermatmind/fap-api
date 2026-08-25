@props(['state', 'title' => null, 'description' => null, 'updatedAt' => null])

@php
    $normalizedState = \App\Filament\Ops\Support\SeoOperationsUiState::normalize($state);
    $tone = \App\Filament\Ops\Support\SeoOperationsUiState::tone($normalizedState);
@endphp

<div {{ $attributes->class(['ops-state-message', 'ops-state-message--'.$tone]) }} role="status" data-state="{{ $normalizedState }}">
    <span class="ops-state-message__signal" aria-hidden="true"></span>
    <div>
        <strong>{{ $title ?? __('ops.custom_pages.seo_operations.states.'.$normalizedState.'.label') }}</strong>
        <p>{{ $description ?? __('ops.custom_pages.seo_operations.states.'.$normalizedState.'.description') }}</p>
        @if ($updatedAt)
            <small>{{ __('ops.custom_pages.seo_operations.sources.updated_at', ['time' => $updatedAt]) }}</small>
        @endif
    </div>
</div>
