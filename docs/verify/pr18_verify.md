# PR18 Verify (B2B Org Isolation v0.3)

## 一键验收命令
```bash
cd backend
composer install
php artisan migrate --force
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan test --filter=V0_3
bash scripts/pr18_verify_org_isolation.sh
```

## 预期输出关键样例
### 1) 跨 org 访问 404
```json
{
  "ok": false,
  "error": "ATTEMPT_NOT_FOUND"
}
```

### 2) /api/v0.3/orgs/me 返回结构
```json
{
  "ok": true,
  "items": [
    {
      "org_id": 123,
      "name": "Org Two",
      "role": "member",
      "owner_user_id": 45
    }
  ]
}
```

### 3) org1 可见、org2 不可见
- org1：`/api/v0.3/attempts/{id}/result` 返回 200 + result
- org2：同一 attempt_id 返回 404（error 见上）
