<div class="ops-career-job-workspace-seo">
    <div class="ops-career-job-workspace-seo__grid">
        @foreach ($rows as $row)
            <div class="ops-career-job-workspace-seo__item">
                <div class="ops-career-job-workspace-seo__copy">
                    <span class="ops-career-job-workspace-seo__label">{{ $row['label'] }}</span>
                    <span class="ops-career-job-workspace-seo__description">{{ $row['value'] }}</span>
                </div>

                <x-filament.ops.shared.status-pill
                    :label="$row['state_label']"
                    :state="$row['state']"
                />
            </div>
        @endforeach
    </div>
</div>
