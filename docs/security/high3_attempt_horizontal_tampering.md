# High-3: Attempt Horizontal Tampering (Org Member/Viewer)

## Risk
Attempt submit/progress endpoints allow org users to tamper with other members' attempt by referencing `attempt_id` and bypassing ownership binding. Impact: integrity + privacy compliance.

## Scan Artifacts
- `/Users/rainie/Desktop/GitHub/fap-api/backend/artifacts/high3_scan/scan.txt`

## Findings
- [x] `AttemptProgressController::show/upsert` only used `attempt_id + org_id`, missing `user_id` ownership filter for org member/viewer.
- [x] `AttemptProgressController` returned `401 RESUME_TOKEN_REQUIRED` for anonymous requests; this leaks existence semantics.
- [x] `AttemptProgressService::canAccessDraft` allowed any logged-in org user when resume token is absent (`$userId !== null`) without checking `attempt.user_id`.
- [x] `AttemptProgressService` returned `401 RESUME_TOKEN_INVALID` on draft access failure (should collapse to `404`).
- [x] `ReportGatekeeper` and `ReportSnapshotStore` fetched attempt by `attempt_id + org_id` only; actor-binding missing in service layer (future footgun if reused directly).

## Fix Applied
- `AttemptProgressController`
  - org 登录用户查询 attempt 强制 `where('user_id', current_user_id)`。
  - 未登录且无 `X-Resume-Token` 直接 `404`。
  - service 返回 `401/403` 统一映射为 `404`。
- `AttemptProgressService`
  - token 为空且 userId 存在时，新增 `attempt.user_id == userId` 强绑定校验。
  - `canAccessDraft` 失败统一返回 `404`（不再返回 `401`）。
- `ReportGatekeeper`
  - 新增 actor-aware `ownedAttemptQuery(...)`，支持 role-aware ownership 约束。
- `ReportSnapshotStore`
  - 新增 actor-aware `ownedAttemptQuery(...)` 与 `org_role/user_id/anon_id` 安全默认。
  - 对 system/job/webhook 调用保持兼容（`system` 角色）。

## Post-fix Status
- [x] MemberB submit MemberA attempt returns `404`.
- [x] MemberB progress show/upsert MemberA attempt returns `404`.
- [x] Touched files do not contain `abort(403)`.
- [x] `backend/scripts/ci_verify_mbti.sh` remains green.
