<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\AttemptResource\Pages;

use App\Filament\Ops\Resources\AttemptResource;
use App\Filament\Ops\Resources\AttemptResource\Support\AttemptExplorerSupport;
use Filament\Resources\Pages\ViewRecord;

class ViewAttempt extends ViewRecord
{
    protected static string $resource = AttemptResource::class;

    protected static string $view = 'filament.ops.resources.attempts.view-attempt';

    /**
     * @var array<string, array{label:string,state:string}>
     */
    public array $headline = [];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>}
     */
    public array $attemptSummary = ['fields' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $answersSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $resultSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $reportSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $commerceSummary = ['fields' => [], 'notes' => []];

    /**
     * @var list<array{title:string,occurred_at:string,meta:list<string>,share_id:string,channel:string}>
     */
    public array $eventTimeline = [];

    /**
     * @var list<array{label:string,url:string,kind:string}>
     */
    public array $links = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $detail = app(AttemptExplorerSupport::class)->buildDetail($this->getRecord());

        $this->headline = $detail['headline'];
        $this->attemptSummary = $detail['attempt_summary'];
        $this->answersSummary = $detail['answers_summary'];
        $this->resultSummary = $detail['result_summary'];
        $this->reportSummary = $detail['report_summary'];
        $this->commerceSummary = $detail['commerce_summary'];
        $this->eventTimeline = $detail['event_timeline'];
        $this->links = $detail['links'];
    }

    public function getTitle(): string
    {
        return 'Attempt Diagnostics';
    }

    public function getBreadcrumb(): string
    {
        return (string) $this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
