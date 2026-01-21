# ContentGraph（recommended_reads）规范 v1

## 1) 背景与目标
- 目标：为 report 输出增加 `recommended_reads`（3–6 条），由 content pack 驱动。
- 约束：不影响主链路；可随时回滚；默认关闭。
- 范围：仅定义 schema 与确定性推荐规则（任务包 8.3）。

## 2) Feature Flags
- `CONTENT_GRAPH_ENABLED=0/1`（默认 0）
  - `0`：完全禁用 recommended_reads 计算与输出。
  - `1`：启用 content_graph 推荐逻辑。
- `CONTENT_GRAPH_PACK_PIN=...`（可选）
  - 指定 content pack 版本/目录名；未设置则使用默认 pack。
- manifest capability：`content_graph=true`
  - 当 pack 的 `manifest.json` 未声明或为 `false` 时，视为不支持。

## 3) 数据结构（写死 schema）

### 3.1 Node types
> 这些节点文件存在于 content pack 内对应目录下，均为 JSON。

#### A) read
- 路径：`reads/*.json`
- 字段：
  - `id`（string，必填，唯一，不可变）
  - `type`（string，固定为 `read`）
  - `title`（string，必填）
  - `slug`（string，必填，唯一，不可变）
  - `status`（string，`active|inactive`，必填）
  - `tags`（array<string>，可选，用于规则匹配）
  - `type_codes`（array<string>，可选，用于 type_code 匹配，如 `INTJ`）
  - `axis_states`（array<string>，可选，如 `E/I`, `N/S`, `T/F`, `J/P`）
  - `trait_buckets`（array<string>，可选，如 `low_empathy`, `high_conscientiousness`）

#### B) role_card
- 路径：`role_cards/*.json`
- 字段：
  - `id`（string，必填，唯一，不可变）
  - `type`（string，固定为 `role_card`）
  - `title`（string，必填）
  - `slug`（string，必填，唯一，不可变）
  - `status`（string，`active|inactive`，必填）
  - `type_codes`（array<string>，必填，至少 1 个）

#### C) strategy_card
- 路径：`strategy_cards/*.json`
- 字段：
  - `id`（string，必填，唯一，不可变）
  - `type`（string，固定为 `strategy_card`）
  - `title`（string，必填）
  - `slug`（string，必填，唯一，不可变）
  - `status`（string，`active|inactive`，必填）
  - `axis_states`（array<string>，可选）
  - `trait_buckets`（array<string>，可选）

> 约束：`id`/`slug` 不允许重命名；下线使用 `status=inactive`。

### 3.2 rules/mapping：content_graph.json
- 路径：`content_graph.json`
- 字段：
  - `version`（string，必填，例如 `1`）
  - `rules`（object，必填）
    - `type_code`（object，必填）
      - `role_card`（array<object>，必填，按序匹配）
        - `type_code`（string，必填，如 `INTJ`）
        - `ids`（array<string>，必填，长度=1）
      - `reads`（array<object>，必填，按序匹配）
        - `type_code`（string，必填）
        - `ids`（array<string>，必填，长度 2–3）
    - `axis_state_or_trait_bucket`（array<object>，必填，按序匹配）
      - `axis_states`（array<string>，可选）
      - `trait_buckets`（array<string>，可选）
      - `strategy_card_ids`（array<string>，必填，长度 1–2）
      - `read_ids`（array<string>，可选，长度 0–2）
- 规则约束：
  - 同一 `id` 不得跨节点类型复用。
  - `ids` 必须指向 `status=active` 的节点。
  - 未命中任何规则时允许空集合。

## 4) 输出 contract（report JSON）
- `recommended_reads[]`：
  - `id`（string）
  - `type`（string，`read|role_card|strategy_card`）
  - `title`（string）
  - `slug`（string）
  - `why`（string，简述命中规则，例如 `type_code:INTJ`）
  - `show_order`（number，1-based）

## 5) 推荐算法（确定性规则，写死执行顺序）
1. 输入：`type_code`、`axis_state`、`trait_bucket`（来自测评结果）
2. 先命中 `type_code → role_card`（必有 1 条）
3. 再命中 `type_code → reads`（2–3 条）
4. 再命中 `axis_state/trait_bucket → strategy_card`（1–2 条）
5. 去重（按 `id` 全局唯一）
6. 排序（稳定）：
   - 先按步骤顺序（role_card → reads → strategy_card）
   - 同步骤内按 `content_graph.json` 中规则定义顺序
7. 截断：
   - `recommended_reads` 最终条数 3–6
   - 超出上限按顺序截断，不足则保留已有
8. 稳定性要求：同版本、同输入必须输出一致（不依赖 DB 顺序/随机数）

## 6) 资产最小量与目录约定（任务包 8.3/8.4）
- 数量要求：
  - `role_cards` 32 齐
  - `strategy_cards` ≥ 6
  - `reads` ≥ 60
- 目录约定（content pack 内）：
  - `reads/*.json`
  - `role_cards/*.json`
  - `strategy_cards/*.json`
  - `content_graph.json`
  - `manifest.json`（声明 `capability: { content_graph: true }`）

## 7) 验收（对应 8.3）+ 最小示例
验收口径（3 条）：
1. 在 `CONTENT_GRAPH_ENABLED=1` 且 pack 支持 `content_graph=true` 时，report 含 `recommended_reads`。
2. 同一输入重复执行多次，`recommended_reads` 顺序与内容一致。
3. 关闭开关或 pack 不支持时，report 不包含 `recommended_reads`（或为空），主链路不受影响。

最小示例（report JSON 片段）：
```json
{
  "recommended_reads": [
    {
      "id": "rc_intj_01",
      "type": "role_card",
      "title": "架构师",
      "slug": "role-architect",
      "why": "type_code:INTJ",
      "show_order": 1
    },
    {
      "id": "read_intj_02",
      "type": "read",
      "title": "如何做决策",
      "slug": "how-to-decide",
      "why": "type_code:INTJ",
      "show_order": 2
    },
    {
      "id": "sc_focus_01",
      "type": "strategy_card",
      "title": "聚焦与执行",
      "slug": "focus-execution",
      "why": "trait_bucket:high_conscientiousness",
      "show_order": 3
    }
  ]
}
```

## 8) 回滚与降级策略
- 关 `CONTENT_GRAPH_ENABLED=0`：完全禁用推荐逻辑。
- 或 pin 到旧包 `CONTENT_GRAPH_PACK_PIN=...`：回退到旧版 pack。
- 或 `capability=false`：视为不支持 content_graph。
- 缺数据或规则未命中：`recommended_reads` 为空/不返回，不影响 report 主路径。

## 9) 兼容与稳定性测试建议
- 同输入重复跑多次输出一致。
- 不依赖 DB 顺序/随机数。
- `id`/`slug` 不允许重命名；下线使用 `status=inactive`。
