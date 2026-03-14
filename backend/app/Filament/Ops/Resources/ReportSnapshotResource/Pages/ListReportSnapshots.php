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
        return 'Report / PDF Center';
    }

    public function getHeading(): string
    {
        return 'Report / PDF Center';
    }

    public function getSubheading(): ?string
    {
        return 'Snapshot-rooted support diagnostics for report delivery, PDF availability, claim/resend clues, and unlock linkage.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
