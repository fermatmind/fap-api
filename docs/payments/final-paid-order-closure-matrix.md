# 支付主链最终闭环矩阵

## 目的

这份文档只回答一个问题：

在当前仓库责任边界内，`网页支付一笔订单 -> truth 收敛 -> repair -> public lookup / Ops / Tenant 可见性收敛` 是否已经闭环。

结论：**已经闭环**。

这里的“CMS”仅指本仓库内可验证的可见性面：

- public lookup
- Filament Ops
- Filament Tenant

不包含：

- browser E2E
- 仓库外 CMS
- 第三方运营后台

## Canonical Truths

以下四套 truth 必须严格分开，不能混用：

- `payment truth = orders.payment_state`
- `unlock truth = benefit_grants.status`
- `lifecycle truth = orders.status`
- `webhook diagnostics truth = payment_events.status / handle_status / last_error_code`

对应代码真值：

- public lookup status 只从 `orders.payment_state` 映射  
  见 [CommerceController.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Controllers/API/V0_3/CommerceController.php)
- Ops 将 `paymentStatus` / `unlockStatus` / `webhookStatus` 分离  
  见 [OrderLinkageSupport.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Filament/Ops/Resources/OrderResource/Support/OrderLinkageSupport.php)
- Tenant 将 `paymentStatus` / `grantStatus` / `unlockStatus` 分离  
  见 [OrderResource.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Filament/Tenant/Resources/OrderResource.php)

## Repair Boundaries

当前 payment 主链只承认三类修复路径：

### 1. replay repair

主命令：

- `commerce:repair-post-commit-failed`

用途：

- `post_commit_failed`
- `ORDER_NOT_FOUND / orphan`
- semantic reject replay family

实现入口：

- [CommerceRepairPostCommitFailed.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Console/Commands/CommerceRepairPostCommitFailed.php)
- [ReprocessPaymentEventJob.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Jobs/Commerce/ReprocessPaymentEventJob.php)

### 2. paid-order repair

主命令：

- `commerce:repair-paid-orders`

用途：

- `paid_no_grant`
- 已 paid 但 grant 未落库、且不需要 replay webhook 语义的订单真值修复

实现入口：

- [CommerceRepairPaidOrders.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Console/Commands/CommerceRepairPaidOrders.php)
- [OrderRepairService.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Commerce/Repair/OrderRepairService.php)

### 3. manual reprocess

这是人工触发 replay，不算 automatic coverage：

- Ops `requestReprocess`
- [PaymentEventResource.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Filament/Ops/Resources/PaymentEventResource.php)
- [ApprovalExecutor.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Services/Approvals/ApprovalExecutor.php)
- [ReprocessPaymentEventAction.php](/Users/rainie/Desktop/GitHub/fap-api/backend/app/Actions/Commerce/ReprocessPaymentEventAction.php)

## 主链闭环矩阵

| 家族 | 前态 | 主修复路径 | 收敛后真值 | 主要 acceptance |
|---|---|---|---|---|
| happy path | `pending + not_started + pending webhook/payout in flight` | 正常 webhook | `paid + granted + fulfilled + active grant + processed event` | [CheckoutWebhookOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/CheckoutWebhookOpsTenantVisibilityAcceptanceTest.php) |
| `post_commit_failed` | order truth 已成功，但 event 停在 `post_commit_failed` | replay repair | truth 与 diagnostics 一起收敛 | [PostCommitFailedRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PostCommitFailedRepairOpsTenantVisibilityAcceptanceTest.php) |
| `paid_no_grant` | `paid + not_started + paid_no_grant + no event` | paid-order repair | `paid + granted + fulfilled + active grant` | [PaidNoGrantRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaidNoGrantRepairOpsTenantVisibilityAcceptanceTest.php) |
| `ORDER_NOT_FOUND / orphan` | orphan event exists, order absent | replay repair | order rebuilt/attached 后完整收敛 | [SemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/SemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php) |
| mismatch pre-paid | `pending + not_started + rejected event` | replay repair | `paid + granted + fulfilled + processed/reprocessed` | [PrePaidMismatchRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PrePaidMismatchRepairOpsTenantVisibilityAcceptanceTest.php) |
| mismatch post-paid | `paid + not_started + paid_no_grant + rejected event` | replay repair | `paid + granted + fulfilled + processed/reprocessed` | [PostPaidAttemptMismatchRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PostPaidAttemptMismatchRepairOpsTenantVisibilityAcceptanceTest.php) |
| `SKU_NOT_FOUND` | pre-paid reject | replay repair | `paid + granted + fulfilled + processed/reprocessed` | [CatalogSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/CatalogSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php) |
| `BENEFIT_CODE_NOT_FOUND / ATTEMPT_REQUIRED` | post-paid reject, 前态 `paid_no_grant` | replay repair as acceptance success path | `paid + granted + fulfilled + processed/reprocessed` | [AttemptRequiredSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/AttemptRequiredSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php) |

## Acceptance Index

### A. 直接锁住 visibility 闭环的 acceptance

- [CheckoutWebhookOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/CheckoutWebhookOpsTenantVisibilityAcceptanceTest.php)
- [PostCommitFailedRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PostCommitFailedRepairOpsTenantVisibilityAcceptanceTest.php)
- [PaidNoGrantRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaidNoGrantRepairOpsTenantVisibilityAcceptanceTest.php)
- [SemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/SemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php)
- [PrePaidMismatchRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PrePaidMismatchRepairOpsTenantVisibilityAcceptanceTest.php)
- [PostPaidAttemptMismatchRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PostPaidAttemptMismatchRepairOpsTenantVisibilityAcceptanceTest.php)
- [CatalogSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/CatalogSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php)
- [AttemptRequiredSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/AttemptRequiredSemanticRejectRepairOpsTenantVisibilityAcceptanceTest.php)

### B. 锁住 read contract 的测试

- [OrderPaymentReadContractTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Ops/OrderPaymentReadContractTest.php)
- [TenantOrderReadContractTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Tenant/TenantOrderReadContractTest.php)
- [OrderCommerceLinkageTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Ops/OrderCommerceLinkageTest.php)

这些测试确保：

- Ops 不把 `orders.status` 当 payment truth
- Ops 不把 `webhookStatus` 当 payment truth
- Tenant 不从 lifecycle 反推 payment truth
- lookup / Ops / Tenant 断言口径一致

### C. 锁住 webhook / repair engine 的测试

- [PaymentRepairEngineTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaymentRepairEngineTest.php)
- [PaymentWebhookProcessorContractTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/Webhook/PaymentWebhookProcessorContractTest.php)
- [PaymentWebhookTrustBoundaryTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/PaymentWebhookTrustBoundaryTest.php)
- [BigFiveWebhookIdempotencyTest.php](/Users/rainie/Desktop/GitHub/fap-api/backend/tests/Feature/Commerce/BigFiveWebhookIdempotencyTest.php)

这些测试负责锁住：

- webhook reject / contract 行为
- replay repair 是否真实收敛 DB truth
- trust boundary 与幂等
- semantic reject canonical 落库语义

## 为什么现在可以说主链已闭环

在当前仓库责任边界内，这句话已经成立：

> 用户网页支付一笔订单后，系统能够在 public lookup、Ops、Tenant 三个可见性面上同步显示正确的支付/解锁结果；若订单卡在已知可修复异常上，系统也能通过既定 repair 路径把同一 `order_no` 收敛回一致 truth。

成立原因：

1. webhook ingress、processor、entitlement、repair 命令、lookup、Ops、Tenant 都已经有实测覆盖。
2. 所有 in-scope 主链家族都已经有 acceptance 或 repair/contract 组合覆盖。
3. read contract 已经明确禁止 truth 混用。
4. automatic replay、paid-order repair、manual reprocess 三条路径已分清边界。

## 不应再开的 PR

以下内容不应再作为 payment 主链 PR 开启：

- 再做一张 broad semantic reject 大扫除
- 再做一张 payment truth 读层统一 PR
- 再做一张 mismatch / catalog / attempt-required acceptance PR
- 把 browser E2E 或仓库外 CMS 接进这个闭环结论

## 后续只剩什么

如果还需要后续动作，只应是：

- reviewer 读这份文档并确认主链已收口
- 按需在 release / runbook 中引用本页

也就是说，当前 payment 主链在仓库内责任边界上已经 **functionally closed**。
