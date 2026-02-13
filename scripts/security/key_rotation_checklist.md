# Key Rotation Checklist (Pre-Release Sign-off)

- 日期：
- 发布批次：
- 变更单号：

1. APP_KEY 已轮换并验证。
2. Stripe API/Webhook Secret 已轮换并验证。
3. Billing Webhook Secret（含 legacy）已轮换并验证。
4. Redis/DB/Queue 凭据已轮换并验证。
5. 历史泄露 key 已作废。
6. 回滚预案确认：仅回滚代码，不回滚旧 key。

- 安全负责人签字：
- SRE 负责人签字：
- 发布负责人签字：
