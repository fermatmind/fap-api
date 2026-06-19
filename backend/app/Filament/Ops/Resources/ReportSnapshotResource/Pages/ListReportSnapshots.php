<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ReportSnapshotResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\ReportSnapshotResource;
use App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListReportSnapshots extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ReportSnapshotResource::class;

    protected function getTableQuery(): ?Builder
    {
        return app(ReportSnapshotExplorerSupport::class)->indexQuery();
    }

    public function getTitle(): string
    {
        return __('ops.resources.support.report_pdf_center');
    }

    public function getHeading(): string
    {
        return __('ops.resources.support.report_pdf_center');
    }

    public function getSubheading(): ?string
    {
        $support = app(ReportSnapshotExplorerSupport::class);
        $summary = $support->bigFiveResultPageV2CoverageSummary();

        return __('ops.resources.support.report_pdf_subheading', [
            'days' => $support->indexLookbackDays(),
        ]).' '.__('ops.custom_pages.reports.big5_v2_coverage_summary', [
            'total' => (string) $summary['total'],
            'attached' => (string) $summary['attached'],
            'fallback' => (string) $summary['fallback'],
            'invalid' => (string) $summary['invalid'],
            'coverage_rate' => $summary['coverage_rate'],
            'fallback_rate' => $summary['fallback_rate'],
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
