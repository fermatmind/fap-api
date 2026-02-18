# Filament 运营控制塔手册

Status: Active  
Last Updated: 2026-02-18  
Truth Source: `app/Providers/Filament/*PanelProvider.php` + `app/Filament/Ops/**/*`

## 1. 控制台入口与鉴权
- `/ops`（Admin Panel，id=`ops`）
- `/tenant`（Tenant Panel，id=`tenant`）

Admin Panel 关键中间件（真值）：
- `SetOpsLocale`
- `SetOpsRequestContext`
- `ResolveOrgContext`
- `EnsureAdminTotpVerified`
- `RequireOpsOrgSelected`
- `OpsAccessControl`

核心鉴权要点：
- guard：`config('admin.guard', 'admin')`
- 启用 TOTP（二次验证）
- 强制组织上下文选择后才进入大部分操作页面

## 2. 信息架构总览（按分组）
- Governance
- Commerce
- Content
- Support
- SRE

## 3. Resources 清单（逐项）
- `AdminApprovalResource`
- `AdminUserResource`
- `AuditLogResource`
- `BenefitGrantResource`
- `ContentPackReleaseResource`
- `ContentPackVersionResource`
- `DeployResource`
- `OrderResource`
- `OrganizationResource`
- `PaymentEventResource`
- `PermissionResource`
- `RoleResource`
- `ScaleRegistryResource`
- `ScaleSlugResource`
- `SkuResource`

## 4. Pages 与 Widgets 清单
### 4.1 Pages
- `OpsDashboard`
- `OrderLookup`
- `DeliveryTools`
- `QueueMonitor`
- `WebhookMonitor`
- `HealthChecks`
- `GoLiveGatePage`
- `SelectOrgPage`
- `OrganizationsImportPage`
- `GlobalSearchPage`
- `SecureLink`
- `TwoFactorChallenge`

### 4.2 Widgets
- `CommerceKpiWidget`
- `WebhookFailureWidget`
- `QueueFailureWidget`
- `HealthzStatusWidget`
- `FunnelWidget`

## 5. 运营能做什么（场景化）
### 5.1 查单
- 入口：`OrderLookup`
- 可按 `order_no / attempt_id / email` 检索
- 联动查看：订单、支付事件、权益发放、attempt

### 5.2 退款申请
- 入口：`OrderResource -> Request Refund`
- 机制：提交审批单（`AdminApproval`），由审批流执行实际动作

### 5.3 重处理支付事件
- 入口：`PaymentEventResource -> Request Reprocess`
- 机制：创建 `REPROCESS_EVENT` 审批，再由后台 job 执行

### 5.4 重试审批执行
- 入口：`AdminApprovalResource -> Retry Execute`
- 适用：`FAILED` 或 `APPROVED` 但未成功执行的审批

### 5.5 重发/补发报告相关
- 入口：`DeliveryTools`
- 机制：发起 `MANUAL_GRANT` 类型审批（含 order/attempt 载荷），由 `AdminApproval` 执行链处理

### 5.6 失败任务重试
- 入口：`QueueMonitor`
- 操作：对 `failed_jobs` 单条执行 retry（内部调用 `queue:retry`）

## 6. 权限与审批流
### 6.1 权限语义（概括）
- 菜单可见性与操作权限通过 `PermissionNames` 常量集控制。
- 常见能力边界：
  - Commerce 读写
  - Content 读/发布/探针
  - Governance 审批复核
  - SRE 监控与故障处置

### 6.2 审批状态流
`PENDING -> APPROVED/REJECTED -> EXECUTING -> EXECUTED/FAILED`

说明：
- Approve 后会 `dispatch ExecuteApprovalJob`（queue=`ops`）。
- 执行失败会落 `FAILED`，可通过 `Retry Execute` 再次触发。

## 7. 故障应急入口（值班顺序）
1. `OpsDashboard`：先看总体红灯（webhook/queue/health）。
2. `WebhookMonitor`：确认支付回调是否异常堆积（signature/status/handle_status）。
3. `QueueMonitor`：确认失败任务、执行 retry。
4. `OrderLookup`：按用户投诉单号快速定位订单/attempt/权益状态。
5. `AdminApprovalResource`：排查审批卡点（是否 stuck 在 PENDING/FAILED）。
6. `AuditLogResource`：追踪谁在什么时候执行了什么动作。

## 8. 值班最小操作清单
- 查单：`/ops/order-lookup`
- 看队列失败：`/ops/queue-monitor`
- 看 webhook 失败：`/ops/webhook-monitor`
- 审批补偿动作：`/ops/admin-approvals`
- 触发内容探针：`/ops/content-pack-releases` -> `Run Probe`
