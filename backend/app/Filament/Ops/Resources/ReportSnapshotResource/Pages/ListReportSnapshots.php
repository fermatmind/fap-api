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
        return __('ops.resources.support.report_pdf_subheading', [
            'days' => app(ReportSnapshotExplorerSupport::class)->indexLookbackDays(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
