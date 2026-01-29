# PR15 验收说明（Scale Registry + Slug Lookup v0.3）

## 一键验收（推荐）
```bash
cd backend
bash scripts/pr15_verify_scale_registry.sh
```

## 手动验收（等价）
```bash
cd backend
composer install
php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan test --filter=V0_3
```

## 关键输出预期
- scales 列表返回 ok=true 且包含 MBTI
- lookup 返回 scale_code=MBTI
- lookup 返回 dir_version=MBTI-CN-v0.2.1-TEST

示例（节选）：
```json
{
  "ok": true,
  "scale_code": "MBTI",
  "primary_slug": "mbti-test",
  "dir_version": "MBTI-CN-v0.2.1-TEST"
}
```
