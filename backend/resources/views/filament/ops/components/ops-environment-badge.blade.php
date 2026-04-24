@php
    $environment = app()->environment('production')
        ? __('ops.topbar.production')
        : __('ops.topbar.local');
@endphp

<span
    class="ops-environment-badge"
    aria-label="{{ __('ops.topbar.environment') }}"
>
    <span class="ops-environment-badge__dot"></span>
    <span class="ops-environment-badge__label">{{ $environment }}</span>
</span>
