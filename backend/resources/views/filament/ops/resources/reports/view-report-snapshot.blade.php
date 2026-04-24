<x-filament-panels::page>
    @php
        $record = $this->getRecord();
    @endphp

    <div class="ops-shell-page">
        <x-filament::section>
            <div class="ops-workbench-toolbar ops-workbench-toolbar--split">
                <div class="ops-workbench-toolbar__main">
                    <div class="ops-shell-inline-intro">
                        <span class="ops-shell-inline-intro__eyebrow">{{ __('ops.custom_pages.reports.detail.eyebrow') }}</span>
                        <p class="ops-shell-inline-intro__meta">
                            {!! __('ops.custom_pages.reports.detail.rooted_on', ['id' => '<code>'.e((string) $record->getKey()).'</code>']) !!}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['snapshot']['state'] ?? 'gray')"
                            :label="__('ops.custom_pages.reports.detail.headline.snapshot', ['status' => app(\App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport::class)->displayStatusLabel((string) ($headline['snapshot']['label'] ?? '-'))])"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['unlock']['state'] ?? 'gray')"
                            :label="__('ops.custom_pages.reports.detail.headline.unlock', ['status' => app(\App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport::class)->displayStatusLabel((string) ($headline['unlock']['label'] ?? '-'))])"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['pdf']['state'] ?? 'gray')"
                            :label="__('ops.custom_pages.reports.detail.headline.pdf', ['status' => app(\App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport::class)->displayStatusLabel((string) ($headline['pdf']['label'] ?? '-'))])"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['delivery']['state'] ?? 'gray')"
                            :label="__('ops.custom_pages.reports.detail.headline.delivery', ['status' => app(\App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport::class)->displayStatusLabel((string) ($headline['delivery']['label'] ?? '-'))])"
                        />
                        <x-filament.ops.shared.status-pill
                            :state="(string) ($headline['job']['state'] ?? 'gray')"
                            :label="__('ops.custom_pages.reports.detail.headline.report_job', ['status' => app(\App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport::class)->displayStatusLabel((string) ($headline['job']['label'] ?? '-'))])"
                        />
                    </div>
                </div>

                <div class="ops-workbench-toolbar__actions">
                    @foreach ($links as $link)
                        <x-filament::button
                            color="{{ ($link['kind'] ?? 'frontend') === 'ops' ? 'gray' : 'primary' }}"
                            size="sm"
                            tag="a"
                            href="{{ (string) ($link['url'] ?? '#') }}"
                            target="{{ ($link['kind'] ?? 'frontend') === 'ops' ? '_self' : '_blank' }}"
                        >
                            {{ (string) ($link['label'] ?? __('ops.custom_pages.common.actions.open')) }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.snapshot_summary.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.snapshot_summary.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $snapshotSummary['fields'] ?? [],
                    'notes' => $snapshotSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.pdf_delivery.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.pdf_delivery.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $pdfDeliverySummary['fields'] ?? [],
                    'notes' => $pdfDeliverySummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.report_job.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.report_job.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $reportJobSummary['fields'] ?? [],
                    'notes' => $reportJobSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.attempt.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.attempt.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $attemptSummary['fields'] ?? [],
                    'notes' => $attemptSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.result.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.result.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $resultSummary['fields'] ?? [],
                    'notes' => $resultSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.commerce.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.commerce.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $commerceSummary['fields'] ?? [],
                    'notes' => $commerceSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="ops-results-header">
                <div>
                    <h3 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.share_access.title') }}</h3>
                    <p class="ops-results-header__meta">{{ __('ops.custom_pages.reports.detail.sections.share_access.description') }}</p>
                </div>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $shareAccessSummary['fields'] ?? [],
                    'notes' => $shareAccessSummary['notes'] ?? [],
                ])
            </div>

            <div class="mt-6">
                <h4 class="ops-results-header__title">{{ __('ops.custom_pages.reports.detail.sections.exception.title') }}</h4>
                <p class="ops-results-header__meta mt-1">{{ __('ops.custom_pages.reports.detail.sections.exception.description') }}</p>
            </div>

            <div class="mt-4">
                @include('filament.ops.resources.attempts.partials.field-grid', [
                    'fields' => $exceptionSummary['fields'] ?? [],
                    'notes' => $exceptionSummary['notes'] ?? [],
                ])
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
