<div class="ops-personality-workspace-seo">
    <div class="ops-personality-workspace-seo__grid">
        @foreach ($checks as $check)
            <div class="ops-personality-workspace-seo__item">
                <div class="ops-personality-workspace-seo__copy">
                    <span class="ops-personality-workspace-seo__label">{{ $check['label'] }}</span>
                    <span class="ops-personality-workspace-seo__description">{{ $check['description'] }}</span>
                </div>

                <x-filament.ops.shared.status-pill
                    :label="$check['ready'] ? __('ops.status.ready') : __('ops.status.missing')"
                    :state="$check['ready'] ? 'ready' : 'draft'"
                />
            </div>
        @endforeach
    </div>

    @if (filled($plannedCanonical))
        <div class="ops-personality-workspace-seo__planned">
            <span class="ops-personality-workspace-seo__planned-label">{{ __('ops.resources.common.fields.planned_canonical') }}</span>
            <span class="ops-personality-workspace-seo__planned-value">{{ $plannedCanonical }}</span>
        </div>
    @endif
</div>
