# Overrides Hotfix Spec（运营热修规范：report_overrides.json）

目标：允许内容/运营在 **不改代码** 的前提下，通过 `report_overrides.json` 快速止血（替换/禁用/降级），同时做到：
- 可追踪（谁改的、为什么、影响范围）
- 可回滚（最快 1 次 revert）
- 可验收（verify_mbti / CI 必过）
- 可到期撤销（避免热修“永久挂着”）

---

## 0. 适用范围（Scope）

本规范仅适用于：
- REGION：`CN_MAINLAND`
- LOCALE：`zh-CN`
- 目标内容包（示例）：`MBTI-CN-v0.2.1-TEST`

### 0.1 唯一允许修改的 Overrides 文件（Canonical）
✅ 只允许改这一份：
- `content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/report_overrides.json`

### 0.2 禁止修改的路径（历史对照 / 回溯用）
🚫 任何情况下都不允许改：
- `content_packages/_deprecated/**`
- 尤其禁止改：
  - `content_packages/_deprecated/MBTI/GLOBAL/en/**`
  - `content_packages/_deprecated/MBTI/CN_MAINLAND/**`

> 原则：**default/** 才是线上生效入口；`_deprecated/` 只用于回溯对照。

---

## 1. Overrides 的职责边界（必须写死）

Overrides 只做“止血热修”，不做“长期内容建设”。

✅ 可以做：
- 禁用一张卡/一条物料（例如出现敏感表达、错误内容、崩坏展示）
- 用指定 id 替换（同 kind 同语义的安全替换）
- 临时降低某条规则命中（避免空结果/错误结果）

🚫 禁止做：
- 新增/重构 section 结构
- 新增 kind / 自创新字段
- 把 overrides 当成长期配置中心
- 通过 overrides 绕过 verify_mbti 的硬闸

---

## 2. 变更流程（必须按这个来）

### 2.1 分支与范围
- 分支名固定：`hotfix/overrides-YYYYMMDD` 或 `hotfix/overrides-<ticket>`
- **变更范围强约束**：PR 中只允许改
  - `.../report_overrides.json`
  - （可选）docs/ 里的说明文字（不建议夹带）

### 2.2 PR 描述必须包含（模板）
PR 必填字段（复制这个模板）：

- 原因（WHY）：（例如：某卡文案错误/敏感/崩坏）
- 影响范围（WHO）：（哪些 section/kind/type_code 受影响）
- 具体动作（WHAT）：（禁用/替换/降级，目标 id 列表）
- 验收证据（VERIFY）：
  - `bash backend/scripts/ci_verify_mbti.sh`（或 CI checks 通过）
  - 禁止信号未命中：`GLOBAL/en` / `fallback to GLOBAL` / `_deprecated`
- 回滚方案（ROLLBACK）：（Revert 该 commit / 恢复原 overrides）
- 到期撤销时间（EXPIRES_AT）：YYYY-MM-DD（必须写）

---

## 3. 文件内容规范（report_overrides.json）

> 这里不强行规定你们 JSON 的 schema（以 pack 内 contract 为准），但运营热修必须满足以下“工程可验收”原则。

### 3.1 必须有可追踪字段（建议写入 meta）
建议 `report_overrides.json` 顶层或 meta 中包含（如你们 schema 支持）：
- `change_id`：例如 `HOTFIX-20260112-01`
- `reason`：一句话原因
- `owner`：负责人（content_owner/qa_owner）
- `created_at`：YYYY-MM-DD
- `expires_at`：YYYY-MM-DD（必须）
- `ticket`：可选（Jira/飞书/issue 链接）

> 如果 schema 不支持 meta，也至少在 PR 描述里写全（2.2 模板）。

### 3.2 禁用/替换的基本规则（硬性建议）
- 替换必须“同 kind/同语义”：例如 action 替换 action、blindspot 替换 blindspot
- 禁用后必须仍满足 verify_mbti 的 highlights 数量范围（例如 3~4）及 kind 覆盖（blindspot+action）
- 不允许把缺口推给 “随机兜底/不可解释 generated_” 路径

---

## 4. 验收硬闸（必须过）

### 4.1 本地验收（推荐）
在仓库根目录执行：

```bash
# 1) 去掉尾随空格
sed -i '' -E 's/[[:space:]]+$//' content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/report_overrides.json
git diff --check

# 2) 跑 CI E2E（包含 self-check / MVP check / verify_mbti / overrides D 验收）
bash backend/scripts/ci_verify_mbti.sh
```

### 4.2 CI 验收（必须通过）
CI 会至少保证：
- `fap:self-check` 通过
- `MVP check` 通过（templates + reads）
- `verify_mbti.sh` 通过
- `accept_overrides_D.sh` 验收通过（D-1/D-2/D-3）
- 禁止信号不出现：`GLOBAL/en` / `fallback to GLOBAL` / `_deprecated`

---

## 5. 回滚（必须 1 分钟能做）

回滚标准动作：
- GitHub 直接点 PR 的 `Revert`（优先）
- 或本地 revert：
```bash
git revert <hotfix_commit_sha>
git push
```

回滚后必须再次跑：
```bash
bash backend/scripts/ci_verify_mbti.sh
```

---

## 6. 到期撤销（必须做）

每个 hotfix 必须写 `expires_at`。
到期前必须：
- 要么把修复下沉到 L1/L2（内容库/规则库的长期修复）
- 要么撤销 overrides（revert）

> 原则：Overrides 只能短期存在，不能“长期挂着”。

---

## 7. 常见风险清单（写给运营/内容）

- ❗改错路径：改到了 `_deprecated/` → 视为重大事故（立即 revert）
- ❗禁用导致缺卡：highlights 数量不足 / kind 缺失 → verify_mbti 会 FAIL
- ❗试图绕过硬闸：CI 已硬闸 MVP + verify_mbti + overrides D，不要赌
- ❗热修长期不撤：必须设置 expires_at + 到期清理

---

## 8. 你要改哪一个文件（再次强调）

✅ 只能改：
- `content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/report_overrides.json`

🚫 禁止改：
- `content_packages/_deprecated/**`
