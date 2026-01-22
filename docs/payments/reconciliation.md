# 支付对账与异常队列（reconciliation）

> 口径写死；不接主链路；脚本默认关闭（`RECONCILIATION_ENABLED=0`）。

## 一、统一口径（写死）
- 时区：`Asia/Shanghai`（脚本内写死）。
- 日期：`DATE=YYYY-MM-DD`；未传则取当前日期（按上面的时区）。
- 时间窗口：`[DATE 00:00:00, DATE+1 00:00:00)`。
- 主要数据源：`orders` / `payment_events` / `benefit_grants`。

## 二、对账输出口径（payments_reconcile_daily.sh）
### 订单数（按状态）
```
orders_by_status = orders where paid_at in window
group by orders.status
```

### 成功数（两套）
```
success_paid.count = orders where paid_at in window
success_fulfilled.count = orders where fulfilled_at in window
```

### 退款数与金额
```
refunds.count = orders where refunded_at in window
refunds.amount_total = sum(orders.amount_refunded) in refunded_at window
```

### 净收入
```
paid_amount = sum(orders.amount_total) in paid_at window
refund_amount = sum(orders.amount_refunded) in refunded_at window
net_revenue = paid_amount - refund_amount
```

### 支付事件（补充统计）
```
payment_events.total = payment_events where created_at in window
payment_events.by_type = group by event_type in window
```

## 三、异常定义（payments_anomalies.sh）
### 1) paid 但未发放权益
```
orders.status = paid
AND paid_at in window
AND (
  benefit_grants 不存在
  OR fulfilled_at 为空且 paid_at <= (now - ANOMALY_FULFILL_LAG_MINUTES)
)
```
说明：
- `ANOMALY_FULFILL_LAG_MINUTES` 默认 `30`。

### 2) 发放但未 paid
```
benefit_grants created_at in window
AND (
  orders.status NOT IN (paid, fulfilled, gifted)
  OR order 记录不存在
)
```

### 3) signature_ok = false
```
payment_events where signature_ok = false and created_at in window
```

## 四、输出与归档
- 输出：脚本打印 JSON 到 stdout，可直接 grep。
- 归档（可选）：`WRITE_ARTIFACT=1` 时写入 `backend/artifacts/payments/*`。

## 五、处理 SOP（写死）
1) 拿到脚本输出中的 `order_id` / `request_id` / `grant_id` / `event_id`。
2) 查订单：
```
orders.id = order_id
orders.request_id = request_id
```
3) 查权益：
```
benefit_grants.source_order_id = order_id
benefit_grants.id = grant_id
```
4) 查支付事件：
```
payment_events.order_id = order_id
payment_events.provider_event_id / payment_events.request_id
```
5) 关联日志与回调：
```
request_id / provider_event_id 在日志中定位回调与处理链路
```
