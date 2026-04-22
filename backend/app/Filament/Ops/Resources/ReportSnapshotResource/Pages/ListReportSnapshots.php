<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ReportSnapshotResource\Pages;

use App\Filament\Ops\Resources\Pages\Concerns\HasSharedListEmptyState;
use App\Filament\Ops\Resources\ReportSnapshotResource;
use Filament\Resources\Pages\ListRecords;

class ListReportSnapshots extends ListRecords
{
    use HasSharedListEmptyState;

    protected static string $resource = ReportSnapshotResource::class;

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
        return __('ops.resources.support.report_pdf_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
