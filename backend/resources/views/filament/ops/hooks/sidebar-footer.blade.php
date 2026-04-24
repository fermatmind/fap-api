<div class="ops-sidebar-footer">
    <span class="ops-sidebar-footer__eyebrow">{{ __('ops.topbar.system_status') }}</span>
    <p class="ops-sidebar-footer__title">{{ __('ops.topbar.shell_ready') }}</p>
    <p class="ops-sidebar-footer__meta">
        {{ __('ops.topbar.version_label') }} · {{ app()->environment('production') ? __('ops.topbar.production') : __('ops.topbar.local') }}
    </p>
</div>
