# big5_source_of_truth_v1

版本：v1
状态：冻结基线（内容资产扩展前置文件）
适用范围：Big Five 结果页正文、section/block 入库、内容资产扩展、后续 registry 化
不适用范围：MBTI、Enneagram、支付/订单/商业逻辑、评分算法

---

## 1. 目的

本文件用于冻结 Big Five 内容资产扩展阶段的唯一写作与落位基线，解决以下问题：

1. 明确哪份文稿是叙事母版，哪份是 8 section 实现母版。
2. 明确哪些旧稿是参考样本，而不是并列“最终稿”。
3. 统一术语、语气、section 职责与长度下限。
4. 为后续 15 个 atomic、30 个 facet glossary、10–15 个 synergy、20–30 条 anomaly、4 个 scenario action files 提供统一真理源。

本文件的核心原则是：
**先冻结 source of truth，再扩内容资产。**

---

## 2. 真理源层级

### 2.1 Narrative master（叙事母版真理源）

当前唯一叙事母版为：

**《FermatMind｜Big Five 完整结果页全文（最终成稿）》**
样本主轴：高敏感 × 中高开放 × 克制进入 × 低续航推进。

这份母版承担以下职责：
- 规定 Big Five 正式正文的密度与层级
- 规定 trait-first → facet-second → compare → action 的阅读顺序
- 规定“反直觉发现”“动力耦合”“行动矩阵”的写法
- 规定 Big Five 中文正式报告的整体语气

### 2.2 Implementation master（8 section / block 入库真理源）

当前唯一实现母版为：

**《Big Five 完整结果页详情页文案（单一结果样本｜按现有 8 section 骨架重排）》**

这份母版承担以下职责：
- 规定 8 section 的职责边界
- 规定每个 section 允许承载的 block 类型
- 规定正文如何映射到现有结果页骨架
- 规定后续 content pack / registry / assembler 的结构参考

### 2.3 Supporting reference samples（参考样本，不并列为最终稿）

以下文档属于参考样本，用于补 archetype seed、风格校验与对照，不再作为并列“最终稿”：

1. **《Big Five 完整结果页内容文档（正式上线版）》**
   样本主轴：高外向 × 高情绪反应敏感度 × 低宜人性 × 中等开放性 × 中等尽责性

2. **《Big Five 完整结果页详情页文档（正式上线样稿）》**
   用于验证单一样本结果页厚度、阅读节奏与 section 深度

### 2.4 Baseline anti-target（反目标基线）

当前 compact 版 Big Five 线上样本仅作为反目标基线，用来明确“不能再回去”的写法：

- facet 不能再只剩 percentile list
- norms 不能再只剩一句“百分位怎么读”
- action 不能再只剩 1–3 句短建议
- section heading 不能再出现用户可见英文残留
- `A compact overview... / Focused read on domain-level strengths... / Near-term actions...` 这类英文导语不得继续作为用户可见正文基线

---

## 3. section 体系（冻结）

Big Five 结果页固定使用当前 8 section，不再新增并行 section 体系，也不再改 section key。

1. `hero_summary`
2. `domains_overview`
3. `domain_deep_dive`
4. `facet_details`
5. `core_portrait`
6. `norms_comparison`
7. `action_plan`
8. `methodology_and_access`

### section 职责总则

- **hero_summary**：在 20 秒内交代画像主轴、主要张力、阅读价值
- **domains_overview**：五维总览与阅读说明，只建立整体认知，不提前进入人生应用
- **domain_deep_dive**：逐 trait 展开定义、位置、双面性、现实表现，是正文主体
- **facet_details**：第二主干，负责反直觉发现、结构偏离与 30 facet 完整层
- **core_portrait**：收束为一个完整的人格总览与动力结构
- **norms_comparison**：专门做相对参照、percentile、参考样本解释
- **action_plan**：把理解转成现实动作，必须闭环
- **methodology_and_access**：建立专业感与边界感，不承载主体价值

---

## 4. 用户可见命名规范（冻结）

### 4.1 trait level 命名

- 内部资产 / JSON / registry 中允许使用：`H / M / L`
- 用户可见正文中优先使用：
  - `较高`
  - `中位`
  - `较低`

当需要更细腻表达时，允许使用：
- `偏高`
- `中位偏高`
- `中位偏低`
- `偏低`

**禁止**在中文正式结果页里反复机械出现：
- `HIGH / MID / LOW`
- `high-mid / low-mid`
- `H / M / L` 直接暴露给用户

### 4.2 “情绪性 / 神经质”命名规范

用户可见中文默认术语：

- **情绪性**

方法说明、研究注释、常模解释或括注中允许出现：

- **神经质（Neuroticism）**
- **Big Five 文献中的 Neuroticism 维度**

规则：
1. 正文 section 标题一律使用 **情绪性**
2. `Norms Comparison` 或 `Methodology` 中可在必要时补一句：
   - “本维度对应 Big Five 文献中的 Neuroticism”
3. 不允许在同一结果页里反复来回切换“情绪性 / 神经质”

### 4.3 Compare section 用户可见中文

内部 key 保持：
- `norms_comparison`

用户可见 section 标题统一为：

- **相对参照**

正文中允许使用：
- 参考样本
- 相对位置
- 百分位
- 相对突出
- 相对不突出

不建议继续用：
- `Norms Comparison` 作为用户可见标题
- 纯英文 compare 小标题

### 4.4 Methodology and Access 用户可见中文

内部 key 保持：
- `methodology_and_access`

用户可见标题统一为：

- **方法与边界说明**

如果页面需要更短标题，允许用：
- **方法说明**

但在正式长稿与入库基线中，统一使用：
- **方法与边界说明**

### 4.5 Action Matrix bucket 中文说法

内部 rule bucket 继续使用：
- `continue`
- `start`
- `stop`
- `observe`

用户可见默认中文统一为：

- `continue` → **继续放大**
- `start` → **开始尝试**
- `stop` → **停止减少**
- `observe` → **持续观察**

允许在工具性更强的场景里使用短版：
- 继续
- 开始
- 停止
- 观察

但 Narrative master 与 section/block 入库基线，一律优先用长版。

---

## 5. 语气规范（冻结）

### 5.1 总体语气

Big Five 正式文案必须满足：

- 专业
- 克制
- 证据导向
- 不命运论
- 不病理化
- 不夸张类型化
- 不用空泛鸡汤替代结构解释

推荐句式：

- 你的系统倾向于……
- 这种结构的优势是……
- 这种结构的代价是……
- 这并不等于……而更像……
- 你真正需要处理的，不是……而是……
- 更准确的理解方式是……

### 5.2 禁止表达

以下表达列为禁止或默认禁用：

1. 命运论
- 你天生注定……
- 你永远都会……
- 你只能这样活……

2. 病理化
- 你太玻璃心
- 你就是脆
- 你有问题
- 你不正常

3. 过度类型化
- 你就是某某型人
- 你注定不适合……
- 你一定会怎样

4. 评价先行
- 你最大的问题就是……
- 你就是不够努力
- 你就是懒

### 5.3 允许的“反直觉发现”写法

以下写法鼓励使用：

- 你不是没尽责，你更像秩序维护型，而不是长期推进型。
- 你不是社交差，你更像高筛选进入。
- 你不是脆，你更像向内高负荷。
- 你不是没有力量，而是力量更多来自感受、判断与环境质量。

---

## 6. section heading 风格规则（冻结）

### 6.1 中文化规则

所有用户可见 section heading / subtitle 默认中文化，不再允许下面这类英文直接暴露：

- `Profile Summary`
- `Domain Deep Dive`
- `Norms Comparison`
- `Methodology and Access`
- `A compact overview of your Big Five profile...`

### 6.2 标题风格

section 标题统一要求：

- 4–8 个字为宜
- 中文自然
- 不生硬翻译
- 不“标题党”

推荐标题集：
- 结果摘要
- 维度总览
- 五维深解
- 细分维度焦点
- 人格总览
- 相对参照
- 成长与下一步动作
- 方法与边界说明

### 6.3 subtitle 风格

subtitle 只做一件事：
- 告诉用户这一节为什么值得看

subtitle 不应：
- 重复标题
- 直接写英文导语
- 写成空泛 slogan

---

## 7. 建议长度下限（软约束，不是硬门禁）

这部分是**推荐下限**，用于内容治理与入库审稿，不是发布强门禁。

| section | 建议下限 |
|---|---|
| hero_summary | 220–450 字 |
| domains_overview | 150–250 字引导 + 5 条 trait row |
| domain_deep_dive | 每个 trait 180–320 字 |
| facet_details | 120–220 字 intro + top 3 anomaly cards + full 30-facet directory |
| core_portrait | 220–400 字 |
| norms_comparison | 180–320 字 |
| action_plan | 4 个 bucket 或 4 个 scenario 至少覆盖其一 |
| methodology_and_access | 120–220 字 |

说明：
- 这不是“越长越好”
- 重点是结构完整、解释到位、能支持阅读闭环
- 不允许以几句空泛文案凑长度

---

## 8. 反目标清单（Baseline anti-target）

以下写法或结构，视为回退到旧 baseline，不再允许作为正式稿目标：

1. `facet_details` 只剩 30 个百分位表，没有反直觉发现
2. `norms_comparison` 只剩“百分位怎么读”一句解释
3. `action_plan` 只剩 1–3 条泛化建议
4. section heading 残留英文
5. `hero_summary` 只有类型名 + 一句短摘要
6. 用“你就是……”式类型判断代替结构分析
7. 用过多抽象词汇，但不给现实表现与代价
8. 用 MBTI 式类型故事直接替代 Big Five 的 trait / facet / compare 结构

---

## 9. 这份真理源后续约束什么

本文件冻结后，后续以下资产都必须服从它：

1. `big5_canonical_profiles_v1.json`
2. `big5_atomic_trait_blocks_v1.json`
3. `big5_gradient_modifiers_v1.json`
4. `big5_synergy_library_v1.json`
5. `big5_facet_glossary_and_precision_v1.json`
6. `big5_action_matrix_v1.json`
7. `big5_lifecycle_copy_library_v1.json`

如后续资产与本文件冲突，以本文件为准，先回到本文件修订，再扩资产，不允许下游资产自行分叉。

---

## 10. 一句话收束

Big Five 内容资产扩展阶段的目标不是再写更多孤立长稿，而是：

**以一份 narrative master + 一份 section/block master 为上游真理源，稳定扩展成可复用、可组合、可治理的旗舰内容资产系统。**
