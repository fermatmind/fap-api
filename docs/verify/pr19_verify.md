# PR19 验收说明

## 1) 必跑命令
```bash
cd backend && composer install
cd backend && php artisan migrate --force
cd backend && php artisan db:seed --class=Pr19CommerceSeeder
cd backend && php artisan test --filter=V0_3
cd backend && bash scripts/pr19_verify_commerce_v2.sh
cd .. && bash backend/scripts/ci_verify_mbti.sh
```

## 2) 关键检查点
- `php artisan route:list` 中包含 v0.3 commerce/webhooks/wallets 路由
- 订单 webhook 重放 10 次，`payment_events=1` 且 ledger/topup 只写一次
- submit 成功后，wallet 余额 -1 且 consumptions 不重复

## 3) artifacts 输出
- `backend/artifacts/pr19/summary.txt`
- `backend/artifacts/pr19/routes.txt`
- `backend/artifacts/pr19/verify.log`
- `backend/artifacts/pr19/server.log`
- `backend/artifacts/pr19/curl_*.json`

## 4) 预期关键输出（示例）
- summary.txt
  - balance_before=100
  - balance_after=99
  - ledger_topup_count=1
  - ledger_consume_count=1
  - consume_count=1
