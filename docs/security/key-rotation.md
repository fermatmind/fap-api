# Key Rotation Runbook

## 1) Laravel APP_KEY
- 负责人：
- 计划时间：
- 执行：
- 验证：
- 作废旧 key 时间：

## 2) Stripe Secrets
- Stripe API Key 轮换：
- Stripe Webhook Secret 轮换：
- 负责人：
- 验证：

## 3) Billing Secrets
- Billing Webhook Secret 轮换：
- Legacy Webhook Secret 轮换：
- 负责人：
- 验证：

## 4) Redis/DB/Queue Credentials
- Redis：
- DB：
- Queue：
- 负责人：
- 验证：

## 5) 作废与回滚策略
- 旧 key 必须作废，不允许恢复启用。
- 允许回滚代码版本，不允许回滚到旧密钥。
- 如需应急恢复，必须使用新签发密钥并记录审批单号。
