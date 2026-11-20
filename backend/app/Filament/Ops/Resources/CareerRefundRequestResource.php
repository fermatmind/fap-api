<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\CareerRefundRequestResource\Pages\ListCareerRefundRequests;
use App\Jobs\Commerce\ReconcileCareerRefundJob;
use App\Models\CareerRefundRequest;
use App\Services\Commerce\CareerRefundWorkflow;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** @review-surface refund_approval */
class CareerRefundRequestResource extends Resource
{
    protected static ?string $model = CareerRefundRequest::class;

    protected static ?string $slug = 'refund-requests';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.commerce');
    }

    public static function getNavigationLabel(): string
    {
        return '退款申请';
    }

    public static function getModelLabel(): string
    {
        return '官网退款申请';
    }

    public static function getPluralModelLabel(): string
    {
        return '官网退款申请';
    }

    public static function canViewAny(): bool
    {
        return CareerRefundWorkflow::canOperate(auth((string) config('admin.guard', 'admin'))->user());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && (int) $record->org_id === 0;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // Explicit owner-only public-site workspace, independent of the tenant selector.
        abort_unless(static::canViewAny(), 403);

        return CareerRefundRequest::query()->where('org_id', 0)->with('order');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny() || ! \App\Support\SchemaBaseline::hasTable('career_refund_requests')) {
            return null;
        }
        $count = static::getEloquentQuery()->whereIn('status', ['pending', 'attention'])->count();

        return $count ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        $labels = ['pending' => '待审核', 'approved' => '已批准／待执行', 'processing' => '渠道处理中',
            'succeeded' => '已退款', 'rejected' => '未通过', 'withdrawn' => '已撤回', 'attention' => '异常待处理'];
        $reasons = ['access_problem' => '报告无法打开', 'duplicate_purchase' => '重复购买', 'not_as_expected' => '不符合预期', 'other' => '其他'];

        return $table->poll('15s')->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('申请时间')->dateTime('Y-m-d H:i')->timezone('Asia/Shanghai')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('等待时长')->since(),
                Tables\Columns\TextColumn::make('order_no')->label('订单号')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('order.sku')->label('测试产品')->searchable(),
                Tables\Columns\TextColumn::make('provider')->label('支付渠道')->badge(),
                Tables\Columns\TextColumn::make('amount_cents')->label('申请全额退款')
                    ->formatStateUsing(fn ($state, CareerRefundRequest $record) => $record->currency.' '.number_format((int) $state / 100, 2)),
                Tables\Columns\TextColumn::make('reason_code')->label('原因')->formatStateUsing(fn ($state) => $reasons[$state] ?? $state),
                Tables\Columns\TextColumn::make('detail')->label('用户说明')->wrap()->limit(120)->tooltip(fn ($record) => $record->detail),
                Tables\Columns\TextColumn::make('status')->label('进度')->badge()->formatStateUsing(fn ($state) => $labels[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'succeeded' => 'success', 'attention' => 'danger', default => 'warning'
                    }),
                Tables\Columns\TextColumn::make('decision_note')->label('审核说明')->wrap(),
                Tables\Columns\TextColumn::make('error_code')->label('异常')->wrap(),
                Tables\Columns\TextColumn::make('batch_id')->label('处理批次')->toggleable(isToggledHiddenByDefault: true)->copyable(),
                Tables\Columns\TextColumn::make('checked_at')->label('最近核对')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product')->label('测试产品')
                    ->options(array_combine(\App\Services\Commerce\CareerAssessmentPurchase::allSkus(), \App\Services\Commerce\CareerAssessmentPurchase::allSkus()))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null,
                        fn (Builder $query, string $sku) => $query->whereHas('order', fn (Builder $order) => $order->where('sku', $sku)))),
                Tables\Filters\SelectFilter::make('currency')->label('币种')->options(['CNY' => 'CNY', 'USD' => 'USD']),
                Tables\Filters\SelectFilter::make('status')->label('进度')->options($labels)->default('pending'),
                Tables\Filters\SelectFilter::make('provider')->label('渠道')->options(['alipay' => '支付宝', 'waffo' => 'Waffo / 微信 / 国际支付']),
                Tables\Filters\SelectFilter::make('reason_code')->label('原因')->options($reasons),
            ])
            ->bulkActions([
                static::reviewAction('approve', '批量批准并退款', 'success'),
                static::reviewAction('reject', '批量拒绝', 'danger'),
                Tables\Actions\BulkAction::make('reconcile')->label('核对退款／处理异常')->requiresConfirmation()
                    ->modalDescription('只查询已有退款结果并同步权限，不会再次发起资金退款。每批最多 50 笔。')
                    ->action(function (Collection $records): void {
                        abort_unless(static::canViewAny() && $records->count() <= 50, 403);
                        foreach ($records as $record) {
                            abort_unless((int) $record->org_id === 0, 403);
                            ReconcileCareerRefundJob::dispatch($record->id);
                        }
                        Notification::make()->title('已加入核对队列')->success()->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    private static function reviewAction(string $decision, string $label, string $color): Tables\Actions\BulkAction
    {
        return Tables\Actions\BulkAction::make($decision)->label($label)->color($color)->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription(function (Collection $records): string {
                $totals = $records->groupBy('currency')->map(fn ($rows, $currency) => $currency.' '.number_format($rows->sum('amount_cents') / 100, 2))->implode('；');
                $orders = $records->map(fn ($r) => $r->order_no.' '.$r->currency.' '.number_format($r->amount_cents / 100, 2))->implode('；');

                return '官网测评付费报告，选中 '.$records->count().' 笔。'.$totals.'。'.$orders.'。批准后将原路全额退款，成功后停止本次报告的付费访问。每批最多 50 笔。';
            })
            ->form([
                TextInput::make('fresh_step_up_code')->label('当前验证码／恢复码')->password()->autocomplete('one-time-code')->required()->maxLength(32),
                Textarea::make('note')->label('拒绝原因（用户可见）')->maxLength(255)->required($decision === 'reject')->visible($decision === 'reject'),
            ])
            ->action(function (Collection $records, array $data) use ($decision): void {
                abort_unless(static::canViewAny(), 403);
                try {
                    $batch = app(CareerRefundWorkflow::class)->review($records->modelKeys(), auth((string) config('admin.guard', 'admin'))->user(),
                        (string) $data['fresh_step_up_code'], $decision, (string) ($data['note'] ?? ''));
                    Notification::make()->title($decision === 'approve' ? '已批准，系统将自动退款' : '已拒绝申请')->body('批次 '.$batch)->success()->send();
                } catch (\App\Services\Approvals\HighRiskApprovalValidationException) {
                    Notification::make()->title('身份验证失败或审批目标已变化，请刷新后重试')->danger()->send();
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    Notification::make()->title('申请或订单状态已变化，请刷新并重新选择待审核申请（最多 50 笔）')->danger()->send();
                }
            })->deselectRecordsAfterCompletion();
    }

    public static function getPages(): array
    {
        return ['index' => ListCareerRefundRequests::route('/')];
    }
}
