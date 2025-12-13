# FAP v0.2 · Stage 2 Skeleton-level Acceptance Checklist  
(CN Mainland-first · MBTI minimal core online)

> Stage 2 goal (Skeleton-level):  
> Turn the Stage 1 specs into a **running minimal backend skeleton** that can:
> - serve MBTI via `/api/v0.2/...`
> - store attempts & results
> - log key events
> - support weekly KPI review (D1)
> even if the implementation is still very simple.

---

## 0. Scope

Stage 2 covers **implementation skeleton** only:

- Repo: `fap-api` (backend service)
- Current product: MBTI scale only
- Region: CN_MAINLAND
- Locale: zh-CN
- Pricing: FREE (all MBTI tests in Stage 2)

Out of scope:

- Full multi-scale platform
- Complex auth system
- Full analytics pipeline

---

## 1. Target Repos & Environments

### 1.1 Repos

Required:

- `fap-specs`: documentation (A/B/C/D lines, Stage1-acceptance)
- `fap-api`: backend implementation (this checklist)

### 1.2 Environments (minimum)

At least **two** logical environments exist:

- `local` (developer laptop / test VM)
- `production` (online server you will actually use)

Each environment MUST have:

- its own `.env` file or equivalent config
- its own DB connection
- its own base URL (even if only domain/port differs)

---

## 2. Minimal API Surface Implemented

Stage 2 requires a **working** minimal API that matches A2 spec (even简化 payload也可以)。

### 2.1 Health

- `GET /api/v0.2/health`  
  - Returns JSON with at least:
    - `status` = "ok"
    - `services.php/mysql/redis` (even if values are stubbed)

### 2.2 Scale Meta

- `GET /api/v0.2/scales/MBTI`  
  - Returns:
    - `scale_code` = "MBTI"
    - `title` (e.g., "MBTI v2.5")
    - `question_count`
    - `content_package` (e.g., "MBTI-CN-v0.2")
    - default `region/locale`

### 2.3 Questions

- `GET /api/v0.2/scales/MBTI/questions`  
  - Returns at least:
    - `scale_code`
    - `attempt_id` (new or existing)
    - `questions[]` with:
      - `question_id`
      - `text`
      - `options[]` (`option_id`, `text`)

Source of questions can be:

- DB table, or
- static JSON / JSONL file on disk (allowed in Stage 2)

### 2.4 Submit Attempt

- `POST /api/v0.2/attempts`  
  - Accepts:
    - `anon_id`
    - `scale_code`
    - `answers[]` (question_id + option_id)
  - Creates a record in storage (DB or file)
  - Returns:
    - `attempt_id`
    - `status` = "submitted"

### 2.5 Fetch Result

- `GET /api/v0.2/attempts/{attempt_id}/result`  
  - Returns:
    - `attempt_id`
    - `scale_code`
    - `type_code` (e.g., ENFJ-A)
    - `dimensions` (EI/SN/TF/JP scores)
    - `content_version` (e.g., "ENFJ-A-CN-v0.2-r1")

Stage 2 可以直接用「简化版打分逻辑」：

- 例如从 answers 里粗算一个 type_code，或直接根据测试页现有逻辑移植。

---

## 3. Minimal Storage Design

目标：**能记账**，哪怕结构简单。

### 3.1 DB / Storage Choice

- Stage 2 default：MySQL（推荐）  
  - 也可以是 SQLite（本地快速测试），只要结构可迁移。

### 3.2 Required Tables (Minimum)

1. `attempts`  
   - `id / attempt_id`
   - `anon_id`
   - `scale_code`
   - `answers_json` (可先整体存 JSON 串)
   - `started_at`
   - `submitted_at`
   - `status`
   - `region`
   - `locale`
   - `source`
   - `channel`

2. `results`  
   - `id / result_id`
   - `attempt_id`
   - `scale_code`
   - `type_code`
   - `dimensions_json` (EI/SN/TF/JP)
   - `content_version`
   - `created_at`
   - `region`
   - `locale`

3. `events`（埋点最小表）  
   - `id`
   - `event_name`
   - `ts`
   - `anon_id`
   - `user_id` (nullable)
   - `scale_code` (nullable)
   - `attempt_id` (nullable)
   - `type_code` (nullable)
   - `region`
   - `locale`
   - `source`
   - `channel`
   - `payload_json` (扩展字段)

注意：字段命名尽量与 A1 / A3 词典保持一致。

---

## 4. Config & Environment Boundary

### 4.1 `.env` Separation

- `APP_ENV=local` vs `APP_ENV=production`
- 不同环境的：
  - `DB_HOST / DB_NAME / DB_USER / DB_PASS`
  - `REDIS_HOST`（如有）
  - `APP_URL` / `API_BASE_URL`

### 4.2 No Hard-coded Secrets in Code

- 数据库密码、API 密钥等只出现在 `.env` 或服务器配置中，不写死在 PHP/代码里。

---

## 5. Event Logging (A3 → 实际落地)

至少有 4–6 个事件真正写入 `events` 表或日志：

必选：

- `scale_view`
- `test_start`
- `test_submit`
- `result_view`

建议加上：

- `share_generate`
- `share_click`

要求：

- 每条事件至少带上：
  - `event_name`
  - `ts`
  - `anon_id`
  - `scale_code`
  - （有的话）`attempt_id`
  - `region`
  - `locale`
  - `source`
  - `channel`

---

## 6. Weekly KPI Review Flow (D1 实战)

目标：**用真实数据填一次 D1 模板**。

### 6.1 数据出口

- 你有一种方法可以把一周的数据拉出来：
  - 简单 SQL 查询，或
  - 导出 CSV，再手动汇总也可以。

### 6.2 对应 D1 的 8 个指标

至少能根据 `attempts` + `events` 算出：

1. New Users / Active Users（可以先用 `distinct anon_id` 近似）
2. Attempt Starts（`test_start` 事件）
3. Attempt Completion Rate（`test_submit / test_start`）
4. Result View Rate（`result_view / test_submit`）
5. Share Rate（`share_generate / result_view`）
6. 1–2 个 Quality 信号（比如 `api_error` 比例）
7. Monetization 意向（暂时可以为 0，但字段要能统计）

### 6.3 模板填表

- 至少用真实一周数据，把 `D1-8-kpi-weekly-template-v0.2` 填满一次：
  - 指标数值
  - 简短备注
  - 下周行动 3 条

---

## 7. End-to-end Dry Run (From Mini Program to API)

一次完整“人工验收路径”：

1. 打开微信小程序 / H5 测试页。
2. 实际做完一套 MBTI 测试。
3. 确认：
   - 前端调用的是 `/api/v0.2/...` 接口；
   - 后端成功写入 `attempts` / `results` / `events`。
4. 用 DB 查询或日志确认：
   - 能找到这次 `attempt_id`；
   - 能找到对应的 `result` 记录；
   - 至少 3–4 个关键 events 被写入。

---

## 8. Checklist (Mark when done)

> 使用方式：每完成一项，就把 `[ ]` 改成 `[x]`。

- [ ] 1. Repos & environments are clearly defined (local + production)
- [ ] 2. `/api/v0.2/health` is implemented and returns basic status
- [ ] 3. `/api/v0.2/scales/MBTI` returns meta data (scale_code, title, question_count, content_package, region/locale)
- [ ] 4. `/api/v0.2/scales/MBTI/questions` can return at least one full question list with options
- [ ] 5. `POST /api/v0.2/attempts` can create an attempt and return `attempt_id`
- [ ] 6. `GET /api/v0.2/attempts/{attempt_id}/result` can return a type_code and dimensions for that attempt
- [ ] 7. Minimal DB tables (`attempts`, `results`, `events`) exist and are connected to the API
- [ ] 8. `.env` separation between local and production is working (different DB/config)
- [ ] 9. At least 4 key events (`scale_view`, `test_start`, `test_submit`, `result_view`) are actually logged to storage
- [ ] 10. One real-week D1 KPI sheet has been filled using data from `fap-api`
- [ ] 11. One end-to-end dry run from Mini Program/Web to `fap-api` has been manually verified

---

End of Document  
Stage2-skeleton-checklist-v0.2