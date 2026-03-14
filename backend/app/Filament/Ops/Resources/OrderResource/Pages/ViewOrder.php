<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\OrderResource\Pages;

use App\Filament\Ops\Resources\OrderResource;
use App\Filament\Ops\Resources\OrderResource\Support\OrderLinkageSupport;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected static string $view = 'filament.ops.resources.orders.view-order';

    /**
     * @var array<string, array{label:string,state:string}>
     */
    public array $headline = [];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $orderSummary = ['fields' => [], 'notes' => []];

    /**
     * @var list<array{
     *     provider_event_id:string,
     *     status:array{label:string,state:string},
     *     handle_status:array{label:string,state:string},
     *     signature:array{label:string,state:string},
     *     reason:string,
     *     processed_at:string,
     *     handled_at:string,
     *     error:string
     * }>
     */
    public array $paymentEvents = [];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $benefitSummary = ['fields' => [], 'notes' => []];

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
    public array $exceptionSummary = ['fields' => [], 'notes' => []];

    /**
     * @var list<array{label:string,url:string,kind:string}>
     */
    public array $links = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $detail = app(OrderLinkageSupport::class)->buildDetail($this->getRecord());

        $this->headline = $detail['headline'];
        $this->orderSummary = $detail['order_summary'];
        $this->paymentEvents = $detail['payment_events'];
        $this->benefitSummary = $detail['benefit_summary'];
        $this->reportSummary = $detail['report_summary'];
        $this->attemptSummary = $detail['attempt_summary'];
        $this->exceptionSummary = $detail['exception_summary'];
        $this->links = $detail['links'];
    }

    public function getTitle(): string
    {
        return 'Unlock / Commerce Linkage';
    }

    public function getHeading(): string
    {
        return 'Unlock / Commerce Linkage';
    }

    public function getSubheading(): ?string
    {
        return 'Read-only order diagnostics across payment, grant, report, PDF, and share linkage.';
    }

    public function getBreadcrumb(): string
    {
        return (string) ($this->getRecord()->order_no ?? $this->getRecord()->getKey());
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
