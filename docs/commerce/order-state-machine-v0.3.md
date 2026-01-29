# Order State Machine v0.3

## 状态机
- 主链路：`created → pending → paid → fulfilled`
- 异常状态：`failed` / `canceled` / `refunded`
- 合法迁移：
  - created → pending / paid / failed / canceled / refunded
  - pending → paid / failed / canceled / refunded
  - paid → fulfilled

## Webhook 幂等
- 幂等键：`payment_events.provider_event_id`（UNIQUE）
- 处理顺序：
  1) 先写 payment_events
  2) 若 insert 失败（重复回调）直接返回 ok=1
  3) insert 成功再推进订单、发放权益

## Wallet / Ledger / Consumption / Grant 一致性
- `benefit_wallets`：`UNIQUE(org_id, benefit_code)`
- `benefit_wallet_ledgers`：`idempotency_key UNIQUE`，账本不可变
- `benefit_consumptions`：`UNIQUE(org_id, benefit_code, attempt_id)`，确保同 attempt 只消耗一次
- `benefit_grants`：保持 `org_id` 收口；grant 重复写入需去重（按 org + benefit_code + scope_key）

## attempt_submit 消耗时点
- 在 submit 成功路径内执行 consume
- 同 attempt 重放 submit 不应二次扣减（依赖 benefit_consumptions 唯一约束）

## 错误码
- `INSUFFICIENT_CREDITS`
- `ORDER_NOT_FOUND`
- `SKU_NOT_FOUND`

## 回滚策略
- 仅回滚代码，数据表保留
- 禁止 destructive migration（不 drop 生产表）
