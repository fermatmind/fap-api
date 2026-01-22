# 支付权益规则（benefits）

## 定义
- 权益（benefit）：因支付或赠送产生的可消费能力或访问权限。
- 归属主体：以 `user_id`（手机号主账号）为唯一主归属；匿名态仅以 `anon_id` 暂存。
- 事件标识：每次发放必须关联 `order_id` + `payment_event_id`（或 `internal_event_id`）。
- benefit_type 枚举示例：`report_unlock`、`content_pack_unlock`、`vip_days`、`vip_month`、`vip_year`。

## 规则
1) 归属规则（写死）
- 已登录：权益直接归属 `user_id`。
- 匿名支付：权益先归属 `anon_id`，在首次绑定手机号后，必须一次性迁移到对应 `user_id`。
- 迁移规则：同一 `anon_id` 只能迁移一次；迁移后 `anon_id` 视为已消费且不可再次绑定。

2) 发放规则（写死默认）
- 发放时机：订单状态进入 `fulfilled` 时发放权益；若系统采用“支付即发放”，仍必须落 `fulfilled` 以形成确定性。
- 幂等：同一 `order_id` + `benefit_type` 仅允许发放一次；重复事件必须返回已发放结果。
- 退款撤销：默认“数字内容不撤销但记录撤销事件”。可选：开启“撤销访问”策略（需产品明确）。

3) 不变量（必须满足）
- 已发放权益可追溯到 `order_id` + `payment_event_id`（或 `internal_event_id`）。
- 权益归属必须唯一映射到 `user_id`（匿名态仅为临时过渡）。

## 示例
- 付费解锁报告：
  - `benefit_type = report_unlock`
  - 订单 `paid` 后进入 `fulfilled`，生成 1 条权益记录。
  - 若支付回调重复 3 次，权益仍只有 1 条。
- 匿名用户购买内容包：
  - 先为 `anon_id=tp11a` 发放。
  - 用户绑定手机号后，权益迁移到 `user_id=U123`。

## 异常处理
- 事件重复：返回“已处理”并保持权益不变。
- 迁移冲突：若 `anon_id` 已迁移或绑定多个 `user_id`，进入人工审核，不自动发放。
- 退款后再次回调：若订单已 `refunded`，继续保持“数字内容不撤销但记录”策略，不二次发放。

## 验收
- 任意权益均可从 `order_id` + `payment_event_id`（或 `internal_event_id`）追溯到支付事件。
- 匿名态购买在绑定后权益能完整迁移且不重复。
- 重放支付回调不产生重复权益。
