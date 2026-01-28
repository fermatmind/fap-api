# PR13 验收指引

## 预置
确保 migrations 已执行，并完成 seeded 数据：
```
cd backend
php artisan migrate
php artisan db:seed --class=QuantifiedSelfSeeder
```

## 验收命令（复制即可）
```
cd backend && composer install && composer audit
cd backend && php artisan migrate && php artisan db:seed --class=QuantifiedSelfSeeder
cd /Users/rainie/Desktop/GitHub/fap-api/backend
PORT=18030 bash scripts/pr13_verify_ingestion.sh
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

## 预期关键输出
`backend/artifacts/pr13/summary.txt` 中应包含：
- `batch_id=...`
- `skipped_repeat` > 0
- `replay_inserted=0`
- `webhook_second` 返回 `status=duplicate`
- tables 列表包含 `integrations,ingest_batches,sleep_samples,health_samples,screen_time_samples,idempotency_keys`

日志位置：
- `backend/artifacts/pr13/logs/server.log`
