# PR16 Verify — Assets schema + URL mapping (IQ_RAVEN)

## Preconditions
```bash
cd backend
php artisan migrate
php artisan db:seed --class=Pr16IqRavenDemoSeeder
```

## Self-check (strict assets)
```bash
cd backend
php artisan fap:self-check --strict-assets --pkg=default/CN_MAINLAND/zh-CN/IQ-RAVEN-CN-v0.3.0-DEMO
```

## End-to-end verify script
```bash
cd backend
bash scripts/pr16_verify_assets.sh
```

## Expected JSON fragment (example)
`/api/v0.3/scales/IQ_RAVEN/questions` should include an assets URL like:
```json
{
  "assets": {
    "image": "https://cdn.example.com/content/default/IQ-RAVEN-CN-v0.3.0-DEMO/assets/images/raven_opt_a.png"
  }
}
```

## Artifacts
- `backend/artifacts/pr16/verify.log`
- `backend/artifacts/pr16/summary.txt`
- `backend/artifacts/pr16/selfcheck.log`
- `backend/artifacts/pr16/seed.log`
