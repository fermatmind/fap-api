# PR20 验收说明

## 1) 必跑命令
```bash
cd backend && composer install
cd backend && php artisan migrate --force
cd backend && php artisan fap:scales:seed-default
cd backend && php artisan fap:scales:sync-slugs
cd backend && php artisan test --filter=V0_3
cd backend && bash scripts/pr20_verify_report_paywall.sh
```

## 2) 关键预期输出
- `GET /api/v0.3/attempts/{id}/report` 未购：
  - `locked=true`
  - `access_level=free`
  - `upgrade_sku=MBTI_REPORT_FULL`
- 已购（payment webhook 或 credit consume）：
  - `locked=false`
  - `access_level=full`
- paid 前后 diff：`paid` vs `paid_after_update` **diff=0**

## 3) artifacts 文件清单
- `backend/artifacts/pr20/summary.txt`
- `backend/artifacts/pr20/routes.txt`
- `backend/artifacts/pr20/curl_report_unpaid.json`
- `backend/artifacts/pr20/curl_report_paid.json`
- `backend/artifacts/pr20/curl_report_paid_after_update.json`

## 4) 说明
- verify 脚本会写入 `backend/artifacts/pr20/`，并做路径脱敏（替换为 `<REPO>`）。
