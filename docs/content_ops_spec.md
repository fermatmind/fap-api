# Content Ops Spec（内容运营规范 / MBTI 内容包）

> 目标：让内容团队 **不改代码、不发版**，仅通过改 JSON 完成日常迭代；同时保证 **可控、可验收、可回滚**。

---

## 0. 目标与边界（强约束）

### 0.1 目标
- **可运营**：内容团队仅改 JSON（cards / rules / overrides / reads），完成替换推荐、修文案、补库存、活动热修。
- **可控**：不允许 silent fallback；每次修改必须能被 CI/verify_mbti/selfcheck 验收。
- **可回滚**：每次内容更新可通过 Git 回滚到上一 commit / tag。
- **可度量**：每张卡/每条 reads 可追踪（id、tags、版本、上线时间、负责人）。

### 0.2 边界（强约束）
- **替换文案**：只动文案字段（title/desc/body 等），**不动 id / kind / type / 结构**。
- **替换推荐**：只动 rule/priority/weight（不改 schema/contract，不跨层“硬引用”写长文）。
- **临时热修**：只走 overrides（且 overrides 必须写范围/优先级/有效期/原因/负责人）。
- **禁止直推 main**：main 为保护分支，所有修改必须走 PR。

---

## 1. 术语与对象（Glossary）

- **Pack（内容包）**：`content_packages/default/<REGION>/<LOCALE>/<PACK_ID>/`
- **Cards（库存层）**：可复用内容单元（highlights/reads/cards）。
- **Templates（模板层）**：结构化版式，渲染依赖；一般冻结。
- **Rules（规则层）**：选择逻辑（从哪些池取、怎么选、数量/去重/配额），不承载长文案。
- **Overrides（热修层）**：覆写/禁用/补丁，优先级最高，必须可追踪且可过期。
- **Bucket（桶）**：按维度切分的集合（by_type/by_role/by_strategy/by_top_axis/fallback…）。
- **Fallback（兜底）**：缺卡时才走，必须在日志/summary 可见（禁止 silent fallback）。

---

## 2. 内容包分层设计（让运营只改 JSON）

### 2.1 四层职责
1) **Cards（库存层）**  
   - 内容同学主要改这里（新增卡/改文案/下线）。
2) **Templates（模板层）**  
   - 影响渲染/排版；改动需要更严格审核（通常冻结）。
3) **Rules（规则层）**  
   - 运营可调（条件/优先级/权重/配额）；必须通过 CI 验收。
4) **Overrides（热修层）**  
   - 只用于紧急替换/临时活动/敏感词修正；必须带 expires_at。

### 2.2 “可运营”的判定
- 内容同学只改 JSON 就能完成：替换推荐、修文案、补库存、临时热修（overrides）。
- 修改后跑：`selfcheck + mvp_check + ci_verify_mbti` 全部 PASS。

---

## 3. 不可变常量（上线即冻结）

> 一旦进入主包（对外），只允许“追加/废弃”，不允许“重命名/改意义”。

### 3.1 固定 section 列表（示例，按报告最终为准）
- identity_card
- strengths
- blindspots
- actions
- relationships
- career
- stress_recovery
- growth_plan
- reads

> 若变更 section：视为协议变更，需要产品/研发评审，并更新 selfcheck/CI。

### 3.2 固定 kind 列表（最小集合）
- strength
- blindspot
- action
- read

### 3.3 ID 命名规范（强制）

统一格式（推荐）：
- highlights：`hl.<kind>.<scope>.<theme>.<vN>`
- reads：`read.<scope>.<theme>.<vN>`
- tool/exercise/test 可扩展：`tool.<scope>.<theme>.<vN>` 等

其中 `<scope>` 必须是以下之一（与 tags 前缀保持一致）：
- `axis:EI:E` / `axis:SN:N` / `axis:TF:T` / `axis:JP:P` / `axis:AT:A`
- `role:NT` / `role:NF` / `role:SJ` / `role:SP`
- `strategy:EA` / `strategy:ET` / `strategy:IA` / `strategy:IT`
- `type:ENTJ-A`（或你的 type_code）

约束：
- **id 全局唯一**（同包内 + 同仓库历史）。
- **id 上线后不改**（只能下线/弃用，不可重命名）。
- 不允许临时名：`test1/new/tmp` 进入主包。
- 版本 `vN` 递增（建议从 v1 起）。

---

## 4. 文件位置与改动范围（File Map）

运营只需要知道“改哪个 JSON”（以 MBTI CN 主包为例）：
- `report_highlights_templates.json`（模板层）
- `report_highlights_rules.json`（规则层）
- `report_highlights_pools.json`（库存/池）
- `report_recommended_reads.json`（reads：by_type/by_role/by_strategy/by_top_axis/fallback）
  - **约定**：当前“通用 reads”使用 `items.fallback` 作为通用兜底池（如未来拆分 `items.general`，需在本规范中写死并更新验收脚本）。
- `overrides/*.json`（热修）

受控范围（谨慎修改，需要评审）：
- `manifest.json`（内容包契约/能力声明；变更视为协议升级）
- `contracts/*.json`（Contract / Schema 约束；变更需同步 selfcheck/CI 规则）

禁止范围：
- `backend/` 业务代码（除非研发任务）

---

## 5. 库存量指标（最小库存与缺口定义）

> 运营核心：**缺口可视化** + **每周补库节奏**。

### 5.1 Highlights（strength/blindspot/action）最低库存（建议）
| section | 通用卡（general） | role 卡 | axis 卡（每轴） | fallback |
|---|---:|---:|---:|---:|
| strengths | ≥10 | ≥4（每 role≥1） | ≥3（每轴每侧至少1） | ≥5 |
| blindspots | ≥10 | ≥4 | ≥3 | ≥5 |
| actions | ≥10 | ≥4 | ≥3 | ≥5 |

### 5.2 Reads 最低库存（建议）
| bucket | 最低数量 |
|---|---:|
| 通用（fallback 或 general） | ≥10 |
| by_role（NT/NF/SJ/SP） | 每 role ≥7（可调整） |
| by_strategy（EA/ET/IA/IT） | 每 strategy ≥6（可调整） |
| by_top_axis（每轴每侧） | 每 key ≥2 |
| by_type（32 types） | 每 type ≥2 |

---

## 6. 卡片写作规范（Cards）

### 6.1 必填字段（交付物）
每张卡必须具备：
- `id`：唯一且稳定
- `section`：固定 section 之一
- `kind`：strength / blindspot / action / read
- `title`：≤18 字（建议）
- `body`：2–4 段；至少 1 条可执行动作（对 action/reads 强制）
- `tags`：用于检索与组合（role/axis/topic/stage/channel…）
- `tone`：gentle/direct/humor/neutral（建议枚举）
- `scene`：work/love/family/self（建议枚举）
- `version`：内容版本（v1/v2…）
- `owner`：负责人/团队
- `published_at / updated_at`：可追踪

### 6.2 文案红线
- 禁止绝对化诊断：如“你永远/你就是/你肯定有病”
- 禁止人身攻击、歧视、羞辱
- 建议必须可执行：给 1–2 个具体动作，不要空话
- 同一 section 的通用卡不得“换同义词灌水”（重复度要控）
- **禁止** `/reads/coming-soon` 入库；占位必须使用 `status: coming_soon` + 统一 landing（`url`/`canonical_url` 指向同一 landing）（PR #76）

### 6.3 卡片模板（内容同学复制填空）
```json
{
  "id": "hl.action.axis:EI:E.execution_starter.v1",
  "section": "actions",
  "kind": "action",
  "title": "先启动，再迭代",
  "body": [
    "当你能量在外放时，最怕的是想太多拖住启动。",
    "把第一步压到 10 分钟：只做能产生新信息的一件小事。",
    "今天就做：写下下一步最小动作，并设一个 10 分钟计时器。"
  ],
  "tags": ["axis:EI:E", "topic:growth", "scene:work", "tone:direct"],
  "tone": "direct",
  "scene": "work",
  "version": "v1",
  "owner": "content_team",
  "status": "active",
  "published_at": "2026-01-xx",
  "updated_at": "2026-01-xx"
}
```

---

## 7. 规则编写规范（Rules）

### 7.1 规则目标
- 稳定、可解释、可控
- 命中时稳定产出；不命中时允许 fallback，但必须可见

### 7.2 每条规则建议字段
- `id`
- `scope`：作用的 section/kind（或 reads）
- `when`：条件（region/locale/type_code/axis_state/score_delta/tags）
- `pick`：从哪些池取（general/role/axis/type/fallback）
- `strategy`：top1 / priority_desc / weighted / diversify
- `constraints`：数量、必须包含哪些 kind、主题不重复、去重规则
- `priority`
- `note`：人类可读说明（必填）

### 7.3 不允许的规则写法
- 规则里塞长文案（文案应在 Cards）
- silent fallback（看不出为什么走 fallback）
- 破坏去重（同主题/同 canonical_id 重复堆叠）

---

## 8. Overrides 热修规范（Overrides）

### 8.1 允许使用场景
- 敏感词/合规修正
- 事实错误/措辞不当的紧急替换
- 临时活动（节日/热点）
- 短期 A/B（若启用）

### 8.2 overrides 必填字段（强制）
- `id`
- `reason`：为什么改（必填）
- `scope`：范围（region/locale/section/kind/type_code/axis）
- `match`：命中条件（card_id/tag/rule_id）
- `action.type`：`replace` / `disable` / `patch_field`
- `action` 载荷规范（必须符合其 type）：
  - `replace`：**整卡替换**（用 `card` 提供完整的新卡对象；必须包含 `id` 且与被替换目标一致）
    - 允许：修正文案、替换标题/正文/描述/封面/CTA 等
    - 禁止：借 replace 偷换 kind/type/结构（除非明确写在 scope 与 reason 中并通过评审）
  - `patch_field`：**字段级补丁**（用 `patch` 指定要修改的字段路径与新值；仅修改列出的字段）
  - `disable`：**禁用命中目标**（命中后该卡不再出现在最终结果中；需说明替代方案/回滚方案）
- `priority`
- `expires_at`：到期自动失效（强烈建议；默认必须填）
- `owner`

### 8.3 overrides 模板
```json
{
  "id": "ovr.202601xx.sensitive_word_fix.v1",
  "reason": "合规：替换敏感词",
  "scope": { "region": "CN_MAINLAND", "locale": "zh-CN" },
  "match": { "card_id": "hl.blindspot.role:NT.some_theme.v1" },
  "action": {
    "type": "patch_field",
    "patch": {
      "body[1]": "（已修正文案）"
    }
  },
  "priority": 900,
  "expires_at": "2026-02-01",
  "owner": "content_team"
}
```

---

## 9. 工作流 SOP（内容同学照单执行）

### 9.1 日常迭代（Cards/Rules/Reads）
1. 新建分支：`content/<topic>-<date>`
2. 修改 JSON（只改 `content_packages/` 下）
3. 本机校验：
   - `bash backend/scripts/mvp_check.sh "$PACK_DIR"`
   - `bash backend/scripts/ci_verify_mbti.sh`
   - 端口冲突时（多人并行）：**若脚本支持 `PORT` 环境变量**，可用  
     `PORT=18001 bash backend/scripts/ci_verify_mbti.sh`（以脚本实际支持为准）
4. 提 PR（必须写清：改动范围、影响 buckets、是否 fallback、是否 overrides）
5. PR checks 全绿 → merge
6. main 上再跑一次 `ci_verify_mbti.sh`（或 workflow_dispatch）确认

### 9.2 紧急热修（Overrides）
同上，但 PR 描述必须写：
- scope（影响范围）
- reason（原因）
- expires_at（下线时间）
- rollback（回滚方案：撤回 override 或 revert commit）

---

## 10. 验收标准（什么叫可上线）

必须全部满足：
1. selfcheck PASS（manifest/assets/schema）
2. mvp_check PASS（模板覆盖 + reads 统计）
3. ci_verify_mbti PASS（verify_mbti OK）
4. bucket 数量满足最低线（或在 PR 中说明缺口计划）
5. 人工抽检（建议每次抽 2–3 个典型类型）：
   - 强 E / 强 I
   - AT 边界（A/T）
   - 任一策略（EA/ET/IA/IT）

---

## 11. 常见失败与排查

- push main 被拒绝：main 保护分支，必须走 PR（不要直推 main）。
- jq 报 `Cannot index ... with string "items"`：通常是 jq 输入被管道覆盖/变量指向错误；先 `jq -e . "$READS"` 确认文件是对象不是数组。
- fallback 触发异常：检查缺卡桶、tags 是否合规、dedupe 是否过严。
- locale/region 不一致：verify_mbti 会 fail，按日志定位 pack_id/dir。

---

## 12. 版本与变更记录（Changelog）

- 每次合并 PR 必须在 PR 描述里写：
  - 影响的 buckets（by_type/by_role/by_strategy/by_top_axis/fallback）
  - 增量统计（例如：reads.total_unique: 135 -> 151）
  - 是否引入/修改 overrides（含 expires_at）

---

## 13. 每周内容运营节奏（Weekly SOP）

> 目标：让内容同学每周按固定流程补库/修文案/热修，**只改 JSON**，并且每次都能被 CI 验收与回滚。

### 13.1 每周固定流程（周一/周二任选一天）

1) **拉最新 main**
```bash
git switch main
git pull --ff-only
git fetch -p
```

2) **跑一次总验收（确保基线干净）**
```bash
lsof -ti tcp:18000 | xargs kill -9 2>/dev/null || true
bash backend/scripts/ci_verify_mbti.sh
```

3) **跑“库存总览统计”（用于周报/PR comment）**
```bash
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
POOLS="$PACK_DIR/report_highlights_pools.json"
READS="$PACK_DIR/report_recommended_reads.json"

jq -r '
  def items(pool): (.pools[pool].items // []);
  def stat(pool):
    {
      total: (items(pool)|length),
      general: (items(pool) | map(select((.tags//[])|index("universal"))) | length),
      role: (items(pool) | map(select((.tags//[])|map(select(startswith("role:")))|length>0)) | length),
      axis: (items(pool) | map(select((.tags//[])|map(select(startswith("axis:")))|length>0)) | length),
      fallback: (items(pool) | map(select((.tags//[])|index("fallback"))) | length)
    };
  [
    ("strengths  " + (stat("strength")  | "total=\(.total) general=\(.general) role=\(.role) axis=\(.axis) fallback=\(.fallback)")),
    ("blindspots " + (stat("blindspot") | "total=\(.total) general=\(.general) role=\(.role) axis=\(.axis) fallback=\(.fallback)")),
    ("actions    " + (stat("action")    | "total=\(.total) general=\(.general) role=\(.role) axis=\(.axis) fallback=\(.fallback)"))
  ] | .[]
' "$POOLS"

jq -r '.items.fallback|length| "reads.fallback=" + tostring' "$READS"
```

4) **对照缺口表，选本周“唯一目标”**
- 优先级建议：  
  1) reads.fallback / highlights.fallback（兜底池）  
  2) general（通用池）  
  3) axis/side（细粒度补库）  
  4) role（四象限补库）  
- 如果“数量都达标”，本周就做 **质量替换**：  
  - **只改文案字段**（title/desc/body 等），不改 id / canonical_id / type / 结构  
  - 目标：减少重复表达、提升可读性、补充场景差异

### 13.2 标准工作流（内容同学照单执行）

1) **开分支**
```bash
BR="content/<topic>-$(date +%Y%m%d)"
git switch -c "$BR"
```

2) **只改 JSON（禁止改 backend 代码）**
- highlights：`report_highlights_pools.json` / `report_highlights_rules.json` / `overrides/*.json`
- reads：`report_recommended_reads.json`

3) **本机校验（必须全绿）**
```bash
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
bash backend/scripts/mvp_check.sh "$PACK_DIR"
bash backend/scripts/ci_verify_mbti.sh
```

4) **提交 / 推送 / PR**
```bash
git status
git add <changed_files>
git commit -m "content: <short summary>"
git push -u origin HEAD
```
- PR 按 `.github/pull_request_template.md` 填完勾选项
- 把 **“库存总览统计输出”** 粘到 PR comment / 描述里，作为验收凭证

5) **merge 后 main 再验**
```bash
git switch main
git pull --ff-only
lsof -ti tcp:18000 | xargs kill -9 2>/dev/null || true
bash backend/scripts/ci_verify_mbti.sh
```

6) **清理分支（可选）**
```bash
git fetch -p
git branch --merged main | egrep 'content/|docs/' | xargs -n 1 git branch -d 2>/dev/null || true
```

### 13.3 每周收口（必须做）

- 更新 `docs/content_inventory_gap.md`：把本周变更对应的行标记为 Done（写 PR 号 + 关键统计）
- 把“库存总览统计输出”贴到当周日报/周报（作为里程碑收口）

---

## 14. 模板区（Templates）—— 直接复制粘贴使用

> 目的：给内容同学“照抄即可改”的最小模板集合。  
> 使用方式：复制模板 → 改 `id/owner/日期/文案/标签` → 走 SOP 校验（mvp_check + ci_verify_mbti）。

---

### 14.1 Highlight Card Template（strength）

```json
{
  "id": "hl_s_ei_E_3",
  "pool": "strength",
  "group_id": "ei_energy",
  "priority": 66,
  "title": "（strength）标题：一句话优势",
  "body": "（strength）正文：2–3 句，描述优势如何在实际场景里发挥作用；避免空话，尽量给具体表现。",
  "tags": ["axis:EI", "side:E"]
}
```

---

### 14.2 Highlight Card Template（blindspot）

```json
{
  "id": "hl_b_ei_E_3",
  "pool": "blindspot",
  "group_id": "ei_energy",
  "priority": 66,
  "title": "（blindspot）标题：一句话盲点",
  "body": "（blindspot）正文：2–3 句；避免羞辱/绝对化表达；最好写出“在压力下更容易出现什么”，并暗含一个可调整方向。",
  "tags": ["axis:EI", "side:E", "stress"]
}
```

---

### 14.3 Highlight Card Template（action）

```json
{
  "id": "hl_a_ei_E_3",
  "pool": "action",
  "group_id": "ei_energy",
  "priority": 66,
  "title": "（action）标题：一句话可执行动作",
  "body": "（action）正文：必须可执行（至少 1–2 个具体步骤）；建议用“下一步最小动作/清单/截止点/小实验”等结构写出来。",
  "tags": ["axis:EI", "side:E", "habit"]
}
```

---

### 14.4 Rules Template ×1（规则层模板）

> 说明：规则字段以你当前项目真实 schema 为准；这里给的是“运营可读 + 可落地”的最小结构模板（重点是 note/when/pick/constraints/priority）。

```json
{
  "id": "rule.highlights.axis_side_pick.v1",
  "scope": {
    "region": "CN_MAINLAND",
    "locale": "zh-CN",
    "sections": ["strengths", "blindspots", "actions"]
  },
  "when": {
    "any": [
      { "axis": "EI", "side": "E", "min_level": "clear" },
      { "axis": "EI", "side": "I", "min_level": "clear" }
    ]
  },
  "pick": {
    "order": ["axis", "role", "general", "fallback"],
    "axis": { "tags": ["axis:EI", "side:E"], "count": 1 },
    "role": { "tags_prefix": "role:", "count": 1 },
    "general": { "tags": ["universal"], "count": 1 },
    "fallback": { "tags": ["fallback"], "count": 0 }
  },
  "constraints": {
    "dedupe_by": ["group_id"],
    "must_include": ["strength", "blindspot", "action"],
    "max_items": 3
  },
  "priority": 500,
  "note": "示例：当 EI 轴侧向清晰时，从 axis/side 池优先取 1 张；再补 role/general；缺卡才考虑 fallback。"
}
```

---

### 14.5 Overrides Template ×3（热修层模板）

#### 14.5.1 override：patch_field（字段级补丁，最常用）

```json
{
  "id": "ovr.20260113.patch_wording.v1",
  "reason": "合规/措辞优化：修正文案表述",
  "scope": { "region": "CN_MAINLAND", "locale": "zh-CN" },
  "match": { "card_id": "hl_b_ei_E_1" },
  "action": {
    "type": "patch_field",
    "patch": {
      "title": "（已修正）标题",
      "body": "（已修正）正文：更温和、更可执行"
    }
  },
  "priority": 900,
  "expires_at": "2026-02-01",
  "owner": "content_team"
}
```

#### 14.5.2 override：replace（整卡替换，谨慎使用）

```json
{
  "id": "ovr.20260113.replace_card.v1",
  "reason": "事实/结构错误：整卡替换（保持同 id）",
  "scope": { "region": "CN_MAINLAND", "locale": "zh-CN" },
  "match": { "card_id": "hl_a_tf_T_2" },
  "action": {
    "type": "replace",
    "card": {
      "id": "hl_a_tf_T_2",
      "pool": "action",
      "group_id": "tf_decision",
      "priority": 58,
      "title": "（替换后）标题",
      "body": "（替换后）正文：给出明确步骤与下一步动作。",
      "tags": ["axis:TF", "side:T", "habit"]
    }
  },
  "priority": 910,
  "expires_at": "2026-02-01",
  "owner": "content_team"
}
```

#### 14.5.3 override：disable（禁用卡，需写替代/回滚）

```json
{
  "id": "ovr.20260113.disable_card.v1",
  "reason": "紧急下线：存在争议/敏感，先禁用；后续用新卡替换",
  "scope": { "region": "CN_MAINLAND", "locale": "zh-CN" },
  "match": { "card_id": "hl_s_core_strength_9" },
  "action": { "type": "disable" },
  "priority": 920,
  "expires_at": "2026-01-20",
  "owner": "content_team",
  "rollback": "revert 本 override 或到期自动失效；替代方案：新增同主题更温和卡并在 pools 中补足"
}
```
