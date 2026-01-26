# Content Ops Playbook (PR7)

This playbook describes the minimal content release loop: upload, publish, probe, and rollback.

## Local publish (dev)

1) Migrate:
```
cd backend && php artisan migrate
```

2) Run server (use a free port):
```
cd backend && php artisan serve --host=127.0.0.1 --port=18020
```

3) Zip an existing pack:
```
cd /Users/rainie/Desktop/GitHub/fap-api
zip -r /tmp/mbti_pack.zip content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST
```

4) Upload:
```
curl -sS -X POST "http://127.0.0.1:18020/api/v0.2/admin/content-releases/upload" \
  -H "X-FAP-Admin-Token: dev_admin_token_123" \
  -F "file=@/tmp/mbti_pack.zip" | jq
```

5) Publish:
```
curl -sS -X POST "http://127.0.0.1:18020/api/v0.2/admin/content-releases/publish" \
  -H "Content-Type: application/json" \
  -H "X-FAP-Admin-Token: dev_admin_token_123" \
  -d '{"version_id":"<VERSION_ID>","region":"CN_MAINLAND","locale":"zh-CN","dir_alias":"MBTI-CN-v0.2.1-TEST","probe":true}' | jq
```

6) Probe:
```
curl -sS "http://127.0.0.1:18020/api/v0.2/content-packs" | jq
curl -sS "http://127.0.0.1:18020/api/v0.2/scales/MBTI/questions?region=CN_MAINLAND&locale=zh-CN" | jq '.ok==true' -e
```

7) Verify chain:
```
cd backend && PORT=18020 bash scripts/ci_verify_mbti.sh
```

## Production publish (workflow)

Use the manual workflow `.github/workflows/publish-content.yml`.

Inputs:
- env: production or staging
- region / locale / dir_alias
- file_path (default: content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST)
- probe: true/false

The workflow zips the folder, uploads it, publishes it, and prints release_id + probes.

## Rollback

Call rollback:
```
curl -sS -X POST "https://<BASE_URL>/api/v0.2/admin/content-releases/rollback" \
  -H "Content-Type: application/json" \
  -H "X-FAP-Admin-Token: <FAP_ADMIN_TOKEN>" \
  -d '{"region":"CN_MAINLAND","locale":"zh-CN","dir_alias":"MBTI-CN-v0.2.1-TEST","probe":true}' | jq
```

After rollback, re-run content probes and the MBTI verify chain.

## Canary release

1) Publish to staging.
2) Probe and run `ci_verify_mbti.sh`.
3) If green, publish to production with the same inputs.
4) Keep the audit chain in `content_pack_releases` for traceability.
