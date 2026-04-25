<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ReportSnapshotResource\Pages;

use App\Filament\Ops\Resources\ReportSnapshotResource;
use App\Filament\Ops\Resources\ReportSnapshotResource\Support\ReportSnapshotExplorerSupport;
use App\Models\ReportSnapshot;
use Filament\Resources\Pages\Page;

class ViewReportSnapshot extends Page
{
    protected static string $resource = ReportSnapshotResource::class;

    protected static string $view = 'filament.ops.resources.reports.view-report-snapshot';

    protected ?ReportSnapshot $recordModel = null;

    /**
     * @var array<string, array{label:string,state:string}>
     */
    public array $headline = [];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $snapshotSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $pdfDeliverySummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $reportJobSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $attemptSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $resultSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $commerceSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $shareAccessSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $exceptionSummary = ['fields' => [], 'notes' => []];

    /**
     * @var list<array{label:string,url:string,kind:string}>
     */
    public array $links = [];

    public function mount(int|string $record): void
    {
        abort_unless(ReportSnapshotResource::canViewAny(), 403);

        /** @var ReportSnapshot $resolved */
        $resolved = app(ReportSnapshotExplorerSupport::class)->query()->whereKey($record)->firstOrFail();

        abort_unless(ReportSnapshotResource::canView($resolved), 403);

        $this->recordModel = $resolved;

        $detail = app(ReportSnapshotExplorerSupport::class)->buildDetail($this->getRecord());

        $this->headline = $detail['headline'];
        $this->snapshotSummary = $detail['snapshot_summary'];
        $this->pdfDeliverySummary = $detail['pdf_delivery_summary'];
        $this->reportJobSummary = $detail['report_job_summary'];
        $this->attemptSummary = $detail['attempt_summary'];
        $this->resultSummary = $detail['result_summary'];
        $this->commerceSummary = $detail['commerce_summary'];
        $this->shareAccessSummary = $detail['share_access_summary'];
        $this->exceptionSummary = $detail['exception_summary'];
        $this->links = $detail['links'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ReportSnapshotResource::canViewAny();
    }

    public function getRecord(): ReportSnapshot
    {
        return $this->recordModel ?? throw new \LogicException('Report snapshot record has not been resolved.');
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
        return __('ops.resources.support.report_pdf_detail_subheading');
    }

    public function getBreadcrumb(): string
    {
        return (string) $this->getRecord()->getKey();
    }

    /**
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            ReportSnapshotResource::getUrl() => ReportSnapshotResource::getBreadcrumb(),
            $this->getBreadcrumb(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
