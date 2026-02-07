# PR41 Recon

- Keywords: PaymentWebhookProcessor|Cache::lock|insertOrIgnore

## 相关入口文件
- backend/app/Services/Commerce/PaymentWebhookProcessor.php

## 目标
- webhook 并发幂等：Cache::lock + transaction 内 insertOrIgnore + lockForUpdate
- 规避竞态：锁作用域覆盖事务，事务提交后再释放锁
- busy 状态可观测：LockTimeoutException -> WEBHOOK_BUSY

