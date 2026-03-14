<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ResultResource\Pages;

use App\Filament\Ops\Resources\ResultResource;
use App\Filament\Ops\Resources\ResultResource\Support\ResultExplorerSupport;
use App\Models\Result;
use Filament\Resources\Pages\Page;

class ViewResult extends Page
{
    protected static string $resource = ResultResource::class;

    protected static string $view = 'filament.ops.resources.results.view-result';

    protected ?Result $recordModel = null;

    /**
     * @var array<string, array{label:string,state:string}>
     */
    public array $headline = [];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $resultSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $scoreAxisSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $versionSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $reportSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $attemptSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $commerceSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{
     *     fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,
     *     notes:list<string>,
     *     events:list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     * }
     */
    public array $shareSummary = ['fields' => [], 'notes' => [], 'events' => []];

    /**
     * @var list<array{label:string,url:string,kind:string}>
     */
    public array $links = [];

    public function mount(int|string $record): void
    {
        abort_unless(ResultResource::canViewAny(), 403);

        /** @var Result $resolved */
        $resolved = ResultResource::getEloquentQuery()->whereKey($record)->firstOrFail();

        abort_unless(ResultResource::canView($resolved), 403);

        $this->recordModel = $resolved;

        $detail = app(ResultExplorerSupport::class)->buildDetail($this->getRecord());

        $this->headline = $detail['headline'];
        $this->resultSummary = $detail['result_summary'];
        $this->scoreAxisSummary = $detail['score_axis_summary'];
        $this->versionSummary = $detail['version_summary'];
        $this->reportSummary = $detail['report_summary'];
        $this->attemptSummary = $detail['attempt_summary'];
        $this->commerceSummary = $detail['commerce_summary'];
        $this->shareSummary = $detail['share_summary'];
        $this->links = $detail['links'];
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ResultResource::canViewAny();
    }

    public function getRecord(): Result
    {
        return $this->recordModel ?? throw new \LogicException('Result record has not been resolved.');
    }

    public function getTitle(): string
    {
        return 'Results Explorer';
    }

    public function getHeading(): string
    {
        return 'Results Explorer';
    }

    public function getSubheading(): ?string
    {
        return 'Read-only support diagnostics rooted on results.';
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
            ResultResource::getUrl() => ResultResource::getBreadcrumb(),
            $this->getBreadcrumb(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
