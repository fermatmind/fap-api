# PR17 Verify — AssessmentEngine + Drivers (v0.3)

Date: 2026-01-29

## 本机验收命令（可复制）
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
composer install
php artisan migrate --force
php artisan db:seed --class=Pr16IqRavenDemoSeeder
php artisan db:seed --class=Pr17SimpleScoreDemoSeeder
php artisan test --filter=V0_3
bash scripts/pr17_verify_assessment_engine.sh

cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

## 关键预期输出（示例）
- simple_score_demo submit
  - `result.raw_score` 和 `result.final_score` 为数值（示例：15）
- IQ_RAVEN submit
  - `result.breakdown_json.time_bonus` 为正数（示例：3）
  - `result.final_score` >= `result.raw_score`
- report
  - `locked=false`

## curl 验收（示例）
```bash
# start
curl -s -X POST http://127.0.0.1:18002/api/v0.3/attempts/start \
  -H 'Content-Type: application/json' \
  -d '{"scale_code":"SIMPLE_SCORE_DEMO"}'

# submit
curl -s -X POST http://127.0.0.1:18002/api/v0.3/attempts/submit \
  -H 'Content-Type: application/json' \
  -d '{"attempt_id":"<ID>","duration_ms":120000,"answers":[{"question_id":"SS-001","code":"5"}]}'

# result
curl -s http://127.0.0.1:18002/api/v0.3/attempts/<ID>/result

# report
curl -s http://127.0.0.1:18002/api/v0.3/attempts/<ID>/report
```
