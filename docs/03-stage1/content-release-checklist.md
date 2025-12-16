> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Release Checklist v0.2.1 (Stage 1 / v0.2-A)
> Related Docs:
> - docs/README.md
> - docs/03-stage1/README.md
> - docs/03-stage1/fap-v0.2-glossary.md
> - docs/03-stage1/api-v0.2-spec.md
> - docs/03-stage1/copywriting-no-go-list.md
> - docs/04-stage2/mbti-content-package-spec.md
> - docs/04-stage2/mbti-report-engine-v1.2.md

# 内容发布 / 回滚清单（Content Release Checklist）— v0.2.1（对齐 API & 合规）

版本：**v0.2.1**  
适用范围：FAP（Fermat Assessment Platform）MBTI 主流程相关 **内容资产** 的发布、灰度、回滚与验收。  
目标：让“内容包（Content Package）”可以 **可发布、可回滚、可追溯、口径一致**，且不破坏已生成的历史报告。

---

## 0. v0.2.1 本次变更摘要（必须写清楚）

### 0.1 新增字段（API / 数据侧已对齐）

- `content_package_version`（新增）  
  - 含义：本次报告装配使用的内容资产包版本号（用于回溯与回滚）
- `scores_pct`（新增）  
  - 含义：五轴百分比（0–100），用于“连续光谱/强度分档”
- `axis_states`（新增）  
  - 含义：五轴状态机输出（very_weak/weak/moderate/clear/strong/very_strong）
- `share_id`（新增）  
  - 含义：分享卡数据返回的内部追踪 ID（统计/排障）

### 0.2 新增接口（分享链路）

- `GET /api/v0.2/attempts/{attempt_id}/share`（新增）  
  - 作用：返回“分享卡模板渲染所需字段”（前端生成图片），生成成功后上报 `share_generate` 事件

### 0.3 合规要求（本次必须满足）

- 分享接口不得返回可逆/可重建作答明细（不得返回 answers 或 answer_summary 全量）
- 对外权益通道存在（见 `docs/03-stage1/compliance-basics.md v0.2.1`），并且内容发布不破坏删除/导出流程口径

---

## 1. 内容资产的权威范围（What is “Content” in v0.2.1）

本清单覆盖以下内容资产（均应被视为“可版本化、可回滚”的发布对象）：

- **TypeProfile**（32 型静态骨架）  
  - 例：`type_code=ENFJ-A` 的 intro/traits/career/growth/relationships/disclaimers
- **ShareAsset**（分享卡字段与文案片段）  
  - 例：tagline/rarity/keywords/short_summary 等
- **Assembly Policy（装配策略）**（若你已开始做动态装配）  
  - 例：Top-2 轴高亮、very_weak 触发提示等（v0.2.1 可先最小化）
- **内容包版本号**（`content_package_version`）  
  - 每次发布必须明确写入并可追溯

> 注意：本文件不要求你在 v0.2.1 一次性上线完整动态报告引擎；但要求你把“内容包发布/回滚”的工程与口径跑通。

---

## 2. 内容包版本规则（强制）

### 2.1 版本三件套必须同时明确

| 概念 | 字段 | 示例 | 作用 |
|---|---|---|---|
| 量表版本 | `scale_version` | `v0.2` | 题库与评分逻辑版本（影响算分） |
| 文案版本 | `profile_version` | `mbti32-v2.5` | 结果解释文案版本（TypeProfile/基础文案） |
| 内容包版本 | `content_package_version` | `MBTI-CN-v0.2.1` | 内容资产包整体版本（分享/装配策略/分档卡等） |

**硬性规则：**
- `scale_version` 不变时，允许升级 `profile_version`、`content_package_version`  
- 任一版本升级都必须：
  - **可回滚**
  - **不影响历史 attempt 的可读性**
- 同一条 `result` 记录中必须固化当时使用的：
  - `profile_version`
  - `content_package_version`

---

## 3. 发布前准备（Pre-Release）

### 3.1 内容资产清点（必须）

- [ ] 32 条 TypeProfile 是否齐全（32 个 type_code）
- [ ] 分享字段是否齐全（至少支持 `GET /attempts/{id}/share` 所需字段）
- [ ] 统一 disclaimers 是否存在且可复用（全类型通用）
- [ ] `content_package_version` 已确定（例如 `MBTI-CN-v0.2.1`）

### 3.2 字段对齐检查（对齐 API v0.2.1）

确保内容资产可支持 API 返回这些结构（不要求你代码实现，但内容必须可支撑）：

- [ ] `GET /attempts/{attempt_id}/result` 返回中包含：
  - [ ] `result.scores_pct`（新增）
  - [ ] `result.axis_states`（新增）
  - [ ] `result.content_package_version`（新增）
- [ ] `GET /attempts/{attempt_id}/share` 返回中包含：
  - [ ] `share_id`（新增）
  - [ ] `content_package_version`（新增）
  - [ ] `type_code`/`type_name`/`tagline`/`rarity`/`keywords`/`short_summary`

### 3.3 合规检查（对齐 compliance-basics v0.2.1）

- [ ] 分享接口（share）不返回 `answers` / `answers_summary_json` / 任何可逆信息
- [ ] 对外权益入口页已存在且有效（privacy/user-rights）
- [ ] 发布不会改变“删除/导出”口径（仍按 anon_id 定位）

---

## 4. 发布流程（Release Steps）

### 4.1 版本号与变更记录（必填）

- [ ] 在本次发布说明中写清：
  - [ ] `profile_version` 是否变更（从 A → B）
  - [ ] `content_package_version` 是否变更（从 A → B）
  - [ ] 变更范围（TypeProfile / ShareAsset / Policy）
  - [ ] 影响面（是否影响 share 文案、是否影响结果页展示字段）

建议你每次发布写一个最小 changelog（可写在 `docs/README.md` 或单独 `docs/releases/MBTI-CN-v0.2.1.md`）：

- 本次新增/修改了哪些类型/字段
- 是否需要灰度
- 回滚策略是什么

### 4.2 灰度策略（v0.2.1 最小可用）

如果你尚未实现复杂灰度，v0.2.1 可以先采用以下任一方式：

- **环境灰度**：仅在 staging 生效 → 验收通过后切 production
- **渠道灰度**：`channel=dev` 先使用新内容包
- **版本灰度**：配置项里切换默认 `content_package_version`（推荐）

灰度期间必须做到：
- [ ] 同一 `attempt_id` 多次打开 result，不改变其固化版本字段
- [ ] 只允许新 attempt 使用新 content_package_version

---

## 5. 验收清单（Acceptance / M1）

### 5.1 必测链路（必须全通）

用 Postman/curl 或小程序真机走一遍：

1) 拉题（如有）
- [ ] `GET /api/v0.2/scales/mbti` 成功返回，字段完整（含 profile_version）

2) 提交 attempt
- [ ] `POST /api/v0.2/attempts` 成功写入
- [ ] 响应中包含：
  - [ ] `attempt_id`
  - [ ] `type_code`
  - [ ] `scores_raw` 或 `scores`（若你保留）
  - [ ] `scores_pct`（新增）
  - [ ] `axis_states`（新增）
  - [ ] `profile_version`
  - [ ] `content_package_version`（新增）
- [ ] 事件：至少有一条 `test_submit`（可以由 attempts 内部写或 events 上报）

3) 查结果（Result + Profile）
- [ ] `GET /api/v0.2/attempts/{id}/result` 可多次打开
- [ ] 每次打开：
  - [ ] results 行数不增加
  - [ ] events 里只新增 `result_view`（若你有上报）
- [ ] 返回结构中 `content_package_version` 与本次发布一致

4) 获取分享数据（新增接口）
- [ ] `GET /api/v0.2/attempts/{id}/share` 成功返回
- [ ] 不包含 answers 或任何可逆作答信息
- [ ] 返回字段齐全：`share_id`、`type_code`、`type_name`、`tagline`、`rarity`、`keywords`、`short_summary`、`content_package_version`

5) 生成分享卡并上报事件
- [ ] 前端生成图片成功后，上报 `share_generate`
- [ ] `POST /api/v0.2/events` 写入成功
- [ ] events.meta_json 可包含：
  - [ ] `share_style`
  - [ ] `content_package_version`
  - [ ] `share_id`（可选，但建议记录以便排障）

---

## 6. 回滚策略（Rollback）

### 6.1 允许回滚的对象（内容侧）

- [ ] 回滚 `content_package_version` 默认指向（恢复到上一稳定版）
- [ ] 回滚分享文案/模板（ShareAsset）
- [ ] 回滚 TypeProfile 文案（profile_version 或内容包内对应资源）

### 6.2 不允许回滚/不得破坏的对象（数据侧）

- [ ] 已生成的 `results` 记录不重算、不覆盖
- [ ] 不改历史 `attempt_id` 的绑定关系
- [ ] 不删库不清表（除非合规删除请求）

### 6.3 回滚验证（必须）

回滚后必须验证：

- [ ] 新 attempt 使用旧 `content_package_version`
- [ ] 历史 attempt 打开 result 仍能读到其固化的 `content_package_version`
- [ ] share 接口仍可用（若版本切换导致字段缺失则必须修复或禁用入口）

---

## 7. 发布后监控（Post-Release Monitoring）

发布后 24 小时内（或你做一次真实投放当周）至少观察：

- 漏斗（events）：
  - [ ] `scale_view` / `test_start` / `test_submit` / `result_view` / `share_generate`
- 比例指标（建议）
  - [ ] `result_view / test_submit`
  - [ ] `share_generate / result_view`
- 异常
  - [ ] share 接口错误率（4xx/5xx）
  - [ ] result 接口错误率（RESULT_NOT_FOUND 等）

---

## 8. 最小“发布说明模板”（复制即用）

每次发布你都可以写一段固定结构（建议放在 PR 描述或 release 文档里）：

- 发布版本：`content_package_version = MBTI-CN-v0.2.1`
- 对应 API：`api-v0.2-spec.md v0.2.1`
- 对应合规：`compliance-basics.md v0.2.1`
- 变更范围：
  - TypeProfile：修改了哪些 type_code（如 ENFJ-A）
  - ShareAsset：新增/修改了哪些字段（tagline/short_summary）
  - 装配策略：是否调整（如 Top-2 高亮/very_weak 提示）
- 新增字段确认：
  - `scores_pct`：已返回
  - `axis_states`：已返回
  - `content_package_version`：已返回并落库
  - `share_id`：share 接口已返回
- 新增接口确认：
  - `GET /attempts/{id}/share`：已上线且不返回可逆作答信息
- 回滚方式：
  - 将默认内容包切回 `MBTI-CN-v0.2.0`（或上一版本号）

---