# AI 内容工作流 prompts 套件

本文件定义 AI 内容工作流的 prompts 套件规范。所有字段命名与目录结构必须与任务包 8 的 schema/目录对齐；下列字段为“必须映射/必填或可选”的最小集合，具体字段名以任务包 8 为准。

## 通用约定（适用于全部类型）

- 输出必须为 JSON，且可直接保存为内容包产物。
- 所有事实性声明（含比例、分布、排名、对比）必须来自 norms/whitepaper（任务包 6/9）并明确口径；否则必须标注“推断/一般经验”。
- 禁区与风格遵循各类型小节要求，且不得输出诊断或医疗建议。

### 通用输入字段（与任务包 8 对齐）

- `task_id`：任务唯一 ID（与任务包 8 目录一致）
- `content_id` / `slug`：内容标识（固定且可追溯）
- `locale`：语言/地区（默认 `zh-CN`）
- `version`：prompt 套件版本
- `subject`：目标画像（如 MBTI 类型/轴向位置/基础画像）
- `axis_scores`：轴向分数或区间（若无则为空）
- `constraints`：字数范围、禁词、格式限制
- `source_refs`：允许引用的 norms/whitepaper 文档列表

> 注：字段名需与任务包 8 的实际字段一致；上述为必须映射的含义说明。

---

## axis cards

### 输入字段（与任务包 8 对齐）

- `axis_key`：轴向标识（如 `E-I`）
- `axis_label`：轴向名称（如 “外向-内向”）
- `axis_position`：被试位置（如 `E` 或区间/比例）
- `evidence_snippets`：来自 norms/whitepaper 的证据片段（可空）
- `context`：情境/场景补充（可空）

### 输出 JSON 结构

```json
{
  "type": "axis_card",
  "axis_key": "E-I",
  "title": "轴向标题",
  "summary": "1-2 句中性摘要",
  "bullets": ["要点1", "要点2", "要点3"],
  "evidence": [
    {
      "source": "norms|whitepaper",
      "ref": "doc_id#section",
      "basis": "口径说明",
      "claim": "事实性声明"
    }
  ],
  "inference": ["推断/一般经验说明"],
  "disclaimer": "非诊断、仅供参考"
}
```

### 禁区

- 禁词：绝对化/标签化（如“必然”“注定”“唯一”）
- 诊断/治疗/病理化描述
- 虚构统计、虚构人群分布

### 风格

- 客观可解释、少 AI 味
- 避免夸张和情绪化措辞

---

## role_card

### 输入字段（与任务包 8 对齐）

- `role_key`：角色标识（固定 slug）
- `role_label`：角色名称
- `traits`：特质列表（来源或推断需标注）
- `scenes`：典型场景（可空）
- `actions`：建议行动方向（可空）

### 输出 JSON 结构

```json
{
  "type": "role_card",
  "title": "角色标题",
  "points": ["要点1", "要点2", "要点3"],
  "actions": ["行动建议1", "行动建议2", "行动建议3"],
  "evidence": [
    {
      "source": "norms|whitepaper",
      "ref": "doc_id#section",
      "basis": "口径说明",
      "claim": "事实性声明"
    }
  ],
  "inference": ["推断/一般经验说明"],
  "disclaimer": "非诊断、仅供参考"
}
```

### 禁区

- 绝对化结论或道德评判
- 诊断性措辞或医疗建议
- 未注明来源的统计/比例

### 风格

- 观点克制、语义清晰
- 可落地、可解释

---

## strategy_card

### 输入字段（与任务包 8 对齐）

- `scenario`：场景描述
- `trigger`：触发条件
- `strategy_pool`：策略候选（可为空）
- `one_min_action`：1 分钟行动（可为空）

### 输出 JSON 结构

```json
{
  "type": "strategy_card",
  "title": "策略标题",
  "scene": "场景说明",
  "trigger": "触发条件",
  "strategies": ["策略1", "策略2", "策略3"],
  "one_min_action": "1 分钟可执行动作",
  "evidence": [
    {
      "source": "norms|whitepaper",
      "ref": "doc_id#section",
      "basis": "口径说明",
      "claim": "事实性声明"
    }
  ],
  "inference": ["推断/一般经验说明"],
  "disclaimer": "非诊断、仅供参考"
}
```

### 禁区

- 绝对化保证（如“必能改变”“一定成功”）
- 泛化到人群的虚构统计
- 诊断或干预建议

### 风格

- 清晰、短句、可执行
- 语气平实、避免鸡汤化

---

## reads

### 输入字段（与任务包 8 对齐）

- `topic`：阅读主题
- `lead_hint`：导语提示
- `outline`：结构提纲
- `key_points`：必须覆盖的论点
- `examples`：允许的例子或比喻

### 输出 JSON 结构

```json
{
  "type": "reads",
  "title": "阅读标题",
  "lead": "导语",
  "body": ["正文段落1", "正文段落2", "正文段落3"],
  "summary": "小结",
  "actions": ["行动建议1", "行动建议2"],
  "evidence": [
    {
      "source": "norms|whitepaper",
      "ref": "doc_id#section",
      "basis": "口径说明",
      "claim": "事实性声明"
    }
  ],
  "inference": ["推断/一般经验说明"],
  "disclaimer": "非诊断、仅供参考"
}
```

### 禁区

- 医疗/心理诊断或暗示
- 绝对化/宿命论断
- 虚构引用或统计

### 风格

- 逻辑清楚、信息密度适中
- 以解释与行动建议为主，少 AI 味

