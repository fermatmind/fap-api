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
     * @var list<array<string,mixed>>
     */
    public array $paymentAttempts = [];

    /**
     * @var list<array{
     *     id:string,
     *     provider_event_id:string,
     *     payment_attempt_id:string,
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
    public array $accessSummary = ['fields' => [], 'notes' => []];

    /**
     * @var array{fields:list<array{label:string,value:string,hint:?string,kind:string,state:?string}>,notes:list<string>}
     */
    public array $compensationSummary = ['fields' => [], 'notes' => []];

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
        $this->paymentAttempts = $detail['payment_attempts'];
        $this->paymentEvents = $detail['payment_events'];
        $this->benefitSummary = $detail['benefit_summary'];
        $this->reportSummary = $detail['report_summary'];
        $this->attemptSummary = $detail['attempt_summary'];
        $this->accessSummary = $detail['access_summary'];
        $this->compensationSummary = $detail['compensation_summary'];
        $this->exceptionSummary = $detail['exception_summary'];
        $this->links = $detail['links'];
    }

    public function getTitle(): string
    {
        return 'Commerce Timeline';
    }

    public function getHeading(): string
    {
        return 'Commerce Timeline';
    }

    public function getSubheading(): ?string
    {
        return 'Read-only order diagnostics across payment attempts, webhook events, unlock, access, compensation, report, PDF, and share linkage.';
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
