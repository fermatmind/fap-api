# PR64 Recon

- Keywords: AttemptsController|IDOR|404
- Branch: chore/pr64-attemptscontroller-fix-idor-and-
- Artifacts: backend/artifacts/pr64
- Serve port: 1864

## Related Entry Files
- /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Controllers/API/V0_3/AttemptsController.php
- /Users/rainie/Desktop/GitHub/fap-api/backend/routes/api.php
- /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/ResolveOrgContext.php
- /Users/rainie/Desktop/GitHub/fap-api/backend/app/Http/Middleware/FmTokenAuth.php

## Route Scope
- POST `/api/v0.3/attempts/start`
- POST `/api/v0.3/attempts/submit`
- GET `/api/v0.3/attempts/{id}/result`
- GET `/api/v0.3/attempts/{id}/report`

## DB Scope
- `attempts`
- `results`
- `organization_members`
- `fm_tokens`

## Planned Fixes
- member/viewer 在 SQL 查询层追加 `where('user_id', $currentUserId)`。
- admin/owner 保留 org 级访问（`org_id + id`）。
- 不存在与无权访问统一落 404。
- 新增 `AttemptOwnershipAnd404Test` 覆盖反向用例。
- 新增 `pr64_accept.sh / pr64_verify.sh` 完成本机验收闭环。
