<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\CareerRefundRequestResource\Pages;

use App\Filament\Ops\Resources\CareerRefundRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCareerRefundRequests extends ListRecords
{
    protected static string $resource = CareerRefundRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [\Filament\Actions\Action::make('enrollMfa')
            ->label('首次使用：绑定身份验证器')
            ->visible(auth((string) config('admin.guard', 'admin'))->user()?->totp_enabled_at === null)
            ->url(route('filament.ops.pages.two-factor-enrollment'))];
    }

    public function getSubheading(): ?string
    {
        return '官网职业报告退款（94 题／霍兰德 60 题）；不随顶部组织切换。待审核可批量批准／拒绝，异常单独核对。各币种分别结算。';
    }
}
