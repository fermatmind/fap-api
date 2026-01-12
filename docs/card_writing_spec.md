# Card Writing Spec（卡片写作规范：report_cards_* / highlights templates / reads）

目标：让内容同学写卡（JSON）时做到：
- 不改代码也能上线
- 文案一致、可读、可执行
- 不触发敏感/夸张/医疗承诺风险
- 可被规则稳定选中（tags/维度正确）
- 可通过 verify_mbti / CI 验收

> 不可变项（section/kind/ID 命名）先读：`docs/content_immutable.md`
> 库存门槛/MVP 口径：`docs/content_inventory_spec.md`
> 热修：`docs/overrides_hotfix_spec.md`

---

## 0. 适用范围（Scope）

- REGION：`CN_MAINLAND`
- LOCALE：`zh-CN`
- 文件类型：
  - 章节卡：`report_cards_traits.json` / `report_cards_relationships.json` / `report_cards_career.json` / `report_cards_growth.json`
  - 章节兜底：`report_cards_fallback_*.json`
  - Highlights 模板：`report_highlights_templates.json`
  - Reads：`report_recommended_reads.json`（items + rules 同文件）

---

## 1. 统一写作原则（所有卡都遵守）

### 1.1 一张卡必须回答 3 个问题
1) **你在说什么**（一句话结论，避免空泛）
2) **为什么**（给出可理解的理由/机制/观察线索）
3) **怎么做**（给出 1~3 条可执行建议，动作化）

> 建议结构：结论（1 句）→ 解释（2~3 句）→ 行动（1~3 条 bullet）

### 1.2 语言风格
- 用“你/我们”直呼，少用“人格类型标签化定性”
- 避免绝对化：用“更可能/倾向/在…时”替代“你一定”
- 建议可执行：动词开头（“尝试…/列出…/设置…”）
- 不用灌鸡汤：每条建议必须能在 1 天内试一次

### 1.3 风险红线（必须避开）
🚫 禁止：
- 医疗/诊断暗示（“你有抑郁/焦虑症”）
- 夸张承诺（“100% 改变/立刻治愈/保证成功”）
- 敏感歧视（性别/地域/职业刻板印象）
- 指导违法/危险行为
- 人身攻击/羞辱

✅ 建议：
- 用“自我观察/练习/沟通策略”替代“诊断/治疗”
- 如果涉及压力/睡眠/情绪：用“建议寻求专业帮助”做边界语句（不下诊断）

---

## 2. ID / kind / tags（写卡时最容易写错的地方）

### 2.1 ID 不可变
- 已上线 id 永不改名；修文案只改字段内容
- 版本演进：只增 `vN`，不改旧 id（旧的可下线/禁用）

### 2.2 kind 不可自创
- kind 必须来自系统允许列表（见 `docs/content_immutable.md`）
- highlights 的结果 kind（strength/blindspot/action）由引擎/规则决定：模板库不要混写成结果库

### 2.3 tags 必须可机器统计
tags 是运营化的“维度标签”，必须满足：
- 前缀在允许列表里（以 pack rules/contract 为准）
- 一个维度一个值（不要一张卡同时写两个 strategy 或两个 axis）
- tag 的值字典固定（别自创新 key）

---

## 3. 各类卡片的写法规范

## 3.1 章节卡（report_cards_*.json）

### 3.1.1 字段完整性（通用建议）
（以你们 schema 为准；这里列“常见必需信息”）
- `id`：稳定唯一
- `kind`：系统认可
- `title`：短标题（≤14 字）
- `body` / `content`：主体（建议 80~180 字）
- `bullets` / `actions`：1~3 条行动建议（可选但强烈建议）
- `tags`：用于规则选择（role/axis/topic 等）

### 3.1.2 文案模板（建议）
- 标题：结果导向（“把冲突变成对齐”“在压力下恢复节律”）
- 主体：
  - 第 1 句：你在说什么（结论）
  - 第 2~3 句：为什么（可理解机制）
  - 最后：怎么做（行动）

### 3.1.3 兜底卡（fallback）额外要求
- 不能依赖 role/axis 才成立（必须“通用可用”）
- 避免过于笼统（“多沟通”这种不算）
- 每张兜底至少给 2 条“可执行动作”
- 写作口径：**长期可用**（不会过时、不会引战、不会敏感）

---

## 3.2 Highlights Templates（report_highlights_templates.json）

### 3.2.1 真实维度（必须遵守）
templates 的维度是：`dim × side × level`
- dim：EI/SN/TF/JP/AT
- side：E/I, S/N, T/F, J/P, A/T
- level：clear/strong/very_strong（MVP 口径）

> 注意：templates 不是 strength/blindspot/action 的“结果库”；它是“轴向文案物料库”。

### 3.2.2 模板写作要求
- 每条模板必须能独立成段（不依赖上下文）
- 避免绝对化，强调“在…情境下”
- 给出“一个可执行建议”（哪怕只有一句）

建议结构：
- 1 句洞察（你更倾向…）
- 1 句影响（因此在…会…）
- 1 句建议（你可以尝试…）

### 3.2.3 覆盖门槛（运营必须知道）
- MVP：dim×side 在 level≥clear 必须命中（10/10 true）
- 变强：每个 dim×side 清晰/强/很强各≥1

---

## 3.3 Reads（report_recommended_reads.json）

### 3.3.1 Reads 的“卡片”应该是什么
reads 本质是“推荐阅读/练习/资源卡”，比章节卡更偏：
- 练习（5~10 分钟可做）
- 复盘模板（可复制步骤）
- 沟通脚本（可直接说的话）
- 书/文章/关键词（可检索）

### 3.3.2 strategy / role / axis / topic 的标注规则
- 放进 `by_strategy.<KEY>[]` 的 item 必须带 `strategy:<KEY>`
- 放进 `by_role.<KEY>[]` 的 item 必须带 `role:<KEY>`
- 放进 `by_top_axis["axis:<DIM>:<SIDE>"][]` 的 item 必须带同一个 `axis:<DIM>:<SIDE>`
- topic 必须来自 `catalog.topics`（别自创新 slug）
- fallback items：必须通用可用，不依赖任何维度

### 3.3.3 MVP 最低门槛（写卡时要对齐）
- 去重后总量 `reads.total_unique ≥ 7`
- fallback `≥ 2`
- 非空 strategy 桶 `≥ 2`

---

## 4. 质量自检清单（写完一张卡就能自查）

### 4.1 内容质量
- [ ] 结论明确（1 句能说清）
- [ ] 解释可理解（不是玄学）
- [ ] 建议可执行（1 天内可试）
- [ ] 没有医疗诊断/夸张承诺/歧视刻板印象

### 4.2 机器可用性
- [ ] id 唯一且符合命名规则
- [ ] kind 合法
- [ ] tags 前缀合法，值字典合法
- [ ] 放入的 bucket 与 tags 一致（reads 特别容易错）

### 4.3 上线前验收（建议每次 PR 跑）
```bash
# 去尾随空格
sed -i '' -E 's/[[:space:]]+$//' content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/*.json
git diff --check

# MVP 统计
PACK_DIR="content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST"
bash backend/scripts/mvp_check.sh "$PACK_DIR"

# 全链路硬闸
bash backend/scripts/ci_verify_mbti.sh
```

---

## 5. 常见错误（最容易踩）

- ❗写了新 tag key（规则/统计不认识）→ 规则选不中 / 盘点失真
- ❗reads 放进 bucket 但没带对应 strategy/role/axis tag → “看起来在库里，实际选不中”
- ❗兜底卡写得依赖某类型 → fallback 反而变成“错用”
- ❗模板只写情绪化口号 → 不可解释、不可行动
- ❗一张卡同时写两个 strategy（或两个 axis）→ 统计与选择都会混乱

---

（完）
