# MBTI 内容结构规范（Content Schema）— v0.2.1（对齐 API / 合规 / 发布）

> 适用范围：FAP `v0.2.x`（MBTI 主流程）内容资产的**权威规范源**。  
> 本版本：**v0.2.1**（新增：`content_package_version`、动态报告字段、分享模板协议、合规模块与写作约束对齐）

---

## 0. 总原则

1. **前端只渲染，不做规则**  
   - 轴强度分档（`axis_states`）、推荐卡片选择、语气滤镜等均由后端/内容包决定。  
2. **内容必须可版本化、可回滚**  
   - 所有内容资产必须属于某个 `content_package_version`。  
3. **同型不同百分比的差异化来自“动态模块”**  
   - 32 型只写“静态骨架”（TypeProfile），差异化主要靠 `scores_pct + axis_states + dynamic_cards`。  
4. **合规优先**  
   - 所有对外文案必须遵守 `docs/copywriting-no-go-list.md`；所有结果页/分享页必须包含合规模块或等效提示（见第 8 节）。  

---

## 1. 版本与目录约定（强制）

### 1.1 三类版本号

| 名称 | 字段 | 示例 | 含义 | 变更时机 |
|---|---|---|---|---|
| 题库/评分版本 | `scale_version` | `v0.2` | 题目与计分规则版本 | 改题/改评分必须升版本 |
| 类型骨架文案版本 | `profile_version` | `mbti32-v2.5` | 32 型长文/骨架结构版本 | 文案结构或叙事升级 |
| 内容资产包版本（权威） | `content_package_version` | `MBTI-CN-v0.2.1` | 动态模块/分享/免责声明/推荐等一揽子版本 | 任何可发布内容变化都应升版本或 hotfix |

> v0.2.1：**以 `content_package_version` 作为“发布/回滚”的最小单位**（对齐 `content-release-checklist.md`）。

### 1.2 目录结构（建议，但强烈推荐）

content_packages/
MBTI-CN-v0.2.1/
manifest.json
type_profiles/
ENFJ-A.json
ENFJ-T.json
…
share_templates/
wechat-moment-v1.json
axis_dynamics/
…（可从 v0.2.2 起逐步填充）
layer_profiles/
role.json
strategy.json
identity.json
disclaimers/
mbti-v0.2.1.json
content_graph/
nodes.json
rules.json

---

## 2. 内容包清单（Content Package Manifest）

### 2.1 manifest.json（内容包元信息）

**字段：**

- `content_package_version`（string，必填）
- `region`（string，必填，例：`CN_MAINLAND`）
- `locale`（string，必填，例：`zh-CN`）
- `scale_code`（string，必填，固定：`MBTI`）
- `scale_version`（string，必填，例：`v0.2`）
- `profile_version`（string，必填，例：`mbti32-v2.5`）
- `created_at` / `updated_at`（ISO8601）
- `notes`（string，可选）

**示例：**
```jsonc
{
  "content_package_version": "MBTI-CN-v0.2.1",
  "region": "CN_MAINLAND",
  "locale": "zh-CN",
  "scale_code": "MBTI",
  "scale_version": "v0.2",
  "profile_version": "mbti32-v2.5",
  "created_at": "2025-12-15T00:00:00Z",
  "updated_at": "2025-12-15T00:00:00Z",
  "notes": "v0.2.1: add share template protocol + dynamic report fields placeholders + compliance modules"
}


⸻

3. TypeProfile（32 型静态骨架）

TypeProfile 用于结果页的“主叙事骨架”。
写作约束：不得写死轴强度（避免与 scores_pct/axis_states 打架）。

3.1 TypeProfile 结构（每个 type_code 一份）

字段：
	•	type_code（string，必填，如 ENFJ-A）
	•	type_name（string，必填，如 主人公型）
	•	tagline（string，必填，一句话签名句）
	•	rarity（string，可选，如 约 2%（较为少见））
	•	keywords（string[]，必填，3–8 个）
	•	intro（string，必填，建议 120–180 字）
	•	traits（object，必填）
	•	career（object，必填）
	•	relationships（object，必填）
	•	growth（object，必填）
	•	disclaimers（object，必填，引用第 8 节合规模块）
	•	meta（object，可选：作者/更新时间/适用范围）

段落对象结构：
	•	title（string）
	•	paragraphs（string[]，1–6 段）

示例（节选）：

{
  "type_code": "ENFJ-A",
  "type_name": "主人公型",
  "tagline": "笃定型领路人",
  "rarity": "约 2%（较为少见）",
  "keywords": ["理想主义","高共情","号召力强","责任感","目标导向"],
  "intro": "你像一束方向感清晰的聚光灯：更关心怎样带着大家一起向前……",
  "traits": { "title": "人格特征", "paragraphs": ["……","……"] },
  "career": { "title": "学习与职业倾向", "paragraphs": ["……"] },
  "relationships": { "title": "关系中的你", "paragraphs": ["……"] },
  "growth": { "title": "成长建议", "paragraphs": ["……"] },
  "disclaimers": {
    "title": "重要说明",
    "items": ["本结果为倾向性分析，不构成临床诊断……"]
  }
}


⸻

4. 动态报告模块（Dynamic Report Modules）— v0.2.1 新增口径

v0.2.1 先把“结构与字段”定下来；内容可以逐步补齐。
对齐 api-v0.2-spec.md v0.2.1：后端返回 scores_pct、axis_states、highlights、sections.cards 等。

4.1 scores_pct（五轴百分比）
	•	字段名：scores_pct
	•	类型：object（key 为 EI/SN/TF/JP/AT，value 为 0–100 int）
	•	来源：后端计算结果（不来自内容包）
	•	用途：展示轴倾向 + 触发动态卡片选择

4.2 axis_states（五轴状态机输出）
	•	字段名：axis_states
	•	类型：object（key 为轴，value 为枚举）
	•	枚举：very_weak / weak / moderate / clear / strong / very_strong
	•	来源：后端按阈值配置计算（阈值属于内容包或配置资产）
	•	用途：动态卡片索引键之一

4.3 DynamicCard（动态卡片）

统一卡片字段（前端稳定渲染）：
	•	card_id（string，必填，内容资产唯一 ID）
	•	card_type（string，必填）
	•	v0.2.1 建议先支持：explain / action
	•	预留：behavior / pitfall
	•	title（string，必填）
	•	body（string|string[]，必填）
	•	tags（string[]，可选）
	•	meta（object，可选，用于埋点/AB/调试）

4.4 highlights（高亮卡）
	•	字段名：highlights
	•	类型：DynamicCard[]
	•	口径：Top-2 强度轴（或可配置）对应的关键解释 + 关键建议卡

4.5 sections（分区卡片）
	•	字段名：sections
	•	结构：
	•	traits.cards[]
	•	career.cards[]
	•	growth.cards[]
	•	relationships.cards[]
	•	说明：后端按组装策略（AssemblyPolicy）分发卡片，前端只展示。

v0.2.1：你可以先做到 highlights + identity_card 有内容，sections.* 先为空数组也可上线。

⸻

5. 分享资产协议（Share Template Protocol）— v0.2.1 必须对齐

对齐 api-v0.2-spec.md v0.2.1 新增接口：
GET /api/v0.2/attempts/{attempt_id}/share

5.1 SharePayload（后端返回给前端生成分享卡的最小字段）

字段（必填优先）：
	•	share_id（string，必填）：分享追踪 ID（不可当成用户身份证号对外展示）
	•	content_package_version（string，必填）
	•	type_code（string，必填）
	•	type_name（string，必填）
	•	tagline（string，必填）
	•	rarity（string，可选）
	•	keywords（string[]，必填，3–5 个）
	•	short_summary（string，必填，1–2 句）
	•	brand（object，可选：logo/slogan）
	•	meta（object，可选：用于埋点/模板版本）

示例：

{
  "share_id": "sh_2f1a8c...9d",
  "content_package_version": "MBTI-CN-v0.2.1",
  "type_code": "ENFJ-A",
  "type_name": "主人公型",
  "tagline": "笃定型领路人",
  "rarity": "约 2%（较为少见）",
  "keywords": ["理想主义","高共情","号召力强","责任感","目标导向"],
  "short_summary": "你更擅长把人心与目标拧成一股绳，做团队的稳定发动机。",
  "brand": { "name": "费马测试", "slogan": "心里有问，上费马测试" },
  "meta": { "template": "wechat-moment-v1" }
}

5.2 share_templates（模板资产）
	•	目录：share_templates/
	•	字段建议：
	•	template_id（如 wechat-moment-v1）
	•	aspect_ratio（如 9:16）
	•	required_fields[]（与 SharePayload 对齐）
	•	copy_rules（文案规则：不得医学化/恐吓/绝对化）
	•	fallbacks（缺字段时的兜底策略）

⸻

6. 合规模块（Compliance Modules）— v0.2.1 必须落地

对齐 compliance-basics.md v0.2.1：结果页/分享页/权益说明必须可引用同一套合规模块资产。

6.1 DisclaimerBlock（结果页免责声明块）
	•	字段：
	•	title（string）
	•	items（string[]）
	•	links（object，可选：指向权益说明页）

要求：
	•	必须包含“非诊断/非医疗建议”类提示（按禁区清单约束措辞）
	•	必须给出“用户权益入口”（如 /user-rights）

6.2 UserRightsSnippet（用户权益摘要块）
	•	字段：
	•	summary（string）
	•	contact（object：邮箱/客服）
	•	actions（string[]：删除/导出）
	•	rights_api（可选：若前端需要展示 API 文案，可由接口返回）

⸻

7. 组装策略（Assembly Policy）— v0.2.1 结构预留

v0.2.1 不强制你一次做完，但要把“规则入口”留好，避免未来推翻。

7.1 Policy 关键概念（术语对齐 glossary）
	•	top_strength_axes：强度最高的 Top-2/Top-3 轴
	•	weakest_axis：最弱轴（可用于边缘特质解释）
	•	identity_overlay：A/T 作为语气滤镜
	•	section_mapping：四大区块如何分发卡片类型
	•	tone_filter：根据 Identity 与强度状态调整措辞力度

⸻

8. 写作约束（必须遵守）

本节对齐 copywriting-no-go-list.md，用于内容审核与发布自检。

8.1 禁止项（摘要）
	•	不得出现“诊断/治愈/药物/替代治疗”等医疗化表达
	•	不得“恐吓式结论/绝对化断言/羞辱用户”
	•	不得宣称“权威认证/临床同等/官方背书”除非你有可公开证明材料
	•	不得暗示基于人格给出确定的人生结论（如“你一定会失败/你不适合恋爱”）

8.2 推荐写法（统一口径）
	•	用“倾向/更可能/常见表现/在压力下可能…”
	•	弱特质（接近 50）用“更灵活/更情境化/可能在两端切换”
	•	A/T 的差异只放到 Identity 或语气滤镜，不把它写死进类型骨架

⸻

9. 与 API 的字段/接口对齐清单（v0.2.1）

9.1 结果接口返回中，与内容相关的字段（必须）
	•	profile_version（用于加载 TypeProfile）
	•	content_package_version（用于加载动态模块/分享模板/免责声明）
	•	type_code
	•	scores_json（可保留）
	•	新增：scores_pct
	•	新增：axis_states
	•	新增（可渐进）：highlights[]
	•	新增（可渐进）：sections.{...}.cards[]
	•	新增（建议）：disclaimer_block（或由内容包注入）

9.2 分享接口（新增）
	•	GET /api/v0.2/attempts/{attempt_id}/share
	•	返回 SharePayload
	•	包含 share_id、content_package_version、模板所需字段
	•	前端生成图片后触发 share_generate 事件

9.3 用户权益相关接口（新增/补齐）
	•	GET /api/v0.2/user-rights
	•	返回权益说明摘要（可用于前端展示/落地页一致性）
	•	POST /api/v0.2/user-requests
	•	提交删除/导出请求（可配合事件：delete_request_submit / export_request_submit）

⸻

10. 最低可上线标准（v0.2.1）

只要你满足以下内容资产，就能稳定上线并支持后续扩展：
	•	✅ 内容包 manifest.json（含 content_package_version）
	•	✅ 32 条 TypeProfile（每条 intro + 四区块各 1–2 段 + disclaimers）
	•	✅ 1 套 share_template（字段协议固定）
	•	✅ 1 套 DisclaimerBlock（结果页可复用）
	•	✅ API 返回中包含：profile_version + content_package_version + scores_pct + axis_states

⸻

11. 变更与发布约定（对齐发布清单）
	1.	任何内容变更必须归属某个内容包版本
	2.	发布前自检：字段完整性 / 缺字段兜底 / 合规模块存在 / 分享字段齐全
	3.	回滚：只回滚 content_package_version 对应目录，不回滚代码实现逻辑（除非事故）

⸻


