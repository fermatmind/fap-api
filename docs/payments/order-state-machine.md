# 订单状态机（payments order state machine）

## 定义
- 标准流转：`created` → `pending` → `paid` → `fulfilled`。
- 异常状态：`failed`、`canceled`。
- 售后状态：`refunded`（全额/部分）。
- 赠送状态：`gifted`。

## 规则
1) 状态迁移表（写死）

| 当前状态 | 目标状态 | 触发方 | 触发时机 | 可否逆 | 备注 |
| --- | --- | --- | --- | --- | --- |
| created | pending | 客户端/服务端 | 创建支付单并发起支付 | 否 | 进入待支付 |
| pending | paid | 支付服务 | 支付平台确认成功 | 否 | 仅支付确认触发 |
| paid | fulfilled | 服务端 | 权益发放成功 | 否 | 权益确认后落盘 |
| pending | failed | 支付服务 | 平台失败回调 | 否 | 失败后不可回退 |
| created/pending | canceled | 用户/服务端 | 用户取消或超时取消 | 否 | 取消即终态 |
| paid/fulfilled | refunded | 服务端 | 退款成功（部分/全额） | 否 | 售后终态 |
| created | gifted | 服务端/运营 | 赠送订单创建 | 否 | 不经过支付 |
| gifted | fulfilled | 服务端 | 权益发放成功 | 否 | 赠送发放完成 |

2) 幂等约束（写死）
- 同一订单同一事件重复处理，不改变最终状态。
- `paid`、`fulfilled`、`refunded` 均为单次落盘状态，不允许回滚。

3) 并发约束（写死）
- 同一订单的状态更新必须串行处理：使用数据库事务 + 行锁（或唯一约束）保证单写入。
- 任意写入失败必须回滚，不允许写入部分状态。

## 示例
- 正常支付：`created` → `pending` → `paid` → `fulfilled`。
- 支付失败：`created` → `pending` → `failed`。
- 用户取消：`created` → `canceled`。
- 退款：`paid` → `refunded`（部分/全额）。
- 赠送：`created` → `gifted` → `fulfilled`。

## 异常处理
- 重复回调：按幂等规则返回当前状态，不再变更。
- 乱序事件：若收到 `fulfilled` 前置事件缺失，进入异常队列并拒绝自动推进。
- 状态冲突：检测到非法迁移（如 `failed` → `paid`）应直接拒绝并告警。

## 验收
- 任意订单状态均可追溯到触发事件与责任方。
- 乱序与重复事件不会造成状态倒退或重复落盘。
- 并发更新不会导致双写或状态分叉。
