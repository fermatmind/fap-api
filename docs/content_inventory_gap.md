# Content Inventory Gap（内容库存缺口表）

> 口径说明  
> - **当前(Current)**：以内容包 JSON 为准统计（reads 已可完全自动化；highlights 若未统计则标为 TBD）。  
> - **目标(Target)**：按《docs/content_ops_spec.md》最低库存线。  
> - **缺口(Gap)** = max(Target - Current, 0)  
> - 本表仅覆盖 **highlights + reads** 两大类，后续可扩展到更多 section。

---

## 1) Reads（report_recommended_reads.json）

来源文件：
- `content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/report_recommended_reads.json`

### 1.1 总览（按 bucket）

| bucket | Target | Current | Gap | Notes |
|---|---:|---:|---:|---|
| fallback（通用池） | 10 | 10 | 0 | PR：#67（content: fill reads fallback to >=10，add 2 items）；reads.fallback=10 |
| by_role（NT/NF/SJ/SP） | 4×7=28 | 29 | 0 | NT=7, NF=7, SJ=7, SP=8 |
| by_strategy（EA/ET/IA/IT） | 4×6=24 | 30 | 0 | EA=9, ET=6, IA=7, IT=8 |
| by_top_axis（10 keys） | 10×2=20 | 20 | 0 | 每个 axis key 2 条 |
| by_type（32 types） | 32×2=64 | 64 | 0 | 每个 type 2 条 |
| **TOTAL (unique)** | — | 153 | — | 以脚本输出 `reads.total_unique=153` 为准 |

### 1.2 by_role（细分）

| role | Target | Current | Gap |
|---|---:|---:|---:|
| NT | 7 | 7 | 0 |
| NF | 7 | 7 | 0 |
| SJ | 7 | 7 | 0 |
| SP | 7 | 8 | 0 |

### 1.3 by_strategy（细分）

| strategy | Target | Current | Gap |
|---|---:|---:|---:|
| EA | 6 | 9 | 0 |
| ET | 6 | 6 | 0 |
| IA | 6 | 7 | 0 |
| IT | 6 | 8 | 0 |

### 1.4 by_top_axis（细分）

| axis key | Target | Current | Gap |
|---|---:|---:|---:|
| axis:EI:E | 2 | 2 | 0 |
| axis:EI:I | 2 | 2 | 0 |
| axis:SN:S | 2 | 2 | 0 |
| axis:SN:N | 2 | 2 | 0 |
| axis:TF:T | 2 | 2 | 0 |
| axis:TF:F | 2 | 2 | 0 |
| axis:JP:J | 2 | 2 | 0 |
| axis:JP:P | 2 | 2 | 0 |
| axis:AT:A | 2 | 2 | 0 |
| axis:AT:T | 2 | 2 | 0 |

### 1.5 by_type（断言）

目标：32 个 type_code，每个 **2 条**（Current=2）  
当前：已满足（所有 type_code 均为 2）

---

## 2) Highlights（report_highlights_*）

> 说明：此处先落“缺口口径 + 目标线”，当前数量可在补统计脚本/命令后回填。  
> 覆盖 sections：`strengths / blindspots / actions`

### 2.1 最低库存线（每个 section）

| section | general Target | role Target | axis Target | fallback Target |
|---|---:|---:|---:|---:|
| strengths | 10 | 4 | 3 | 5 |
| blindspots | 10 | 4 | 3 | 5 |
| actions | 10 | 4 | 3 | 5 |

> role：建议按 4 个 role（NT/NF/SJ/SP）“每 role ≥1”起步，逐步扩容。  
> axis：建议按 5 轴（EI/SN/TF/JP/AT）“每轴每侧至少 1”，目标线按“每轴 ≥3”推进。

### 2.2 strengths 缺口（Done）

> 来源：`report_highlights_pools.json` → `pools.strength.items[]`  
> 统计口径：general=tag 含 `universal`；role=tag 含 `role:*`；axis=tag 含 `axis:*`；fallback=tag 含 `fallback`  
> PR：#60（content: gapfill highlights strengths pools）

| bucket | Target | Current | Gap | Notes |
|---|---:|---:|---:|---|
| general | 10 | 15 | 0 | strengths.total=39 |
| role | 4 | 4 | 0 | 已覆盖 NT/NF/SJ/SP |
| axis | 3 | 20 | 0 | 覆盖 EI/SN/TF/JP/AT（按侧拆分更佳） |
| fallback | 5 | 5 | 0 | tag:fallback |

### 2.3 blindspots 缺口（Done）

> 来源：`report_highlights_pools.json` → `pools.blindspot.items[]`  
> 统计口径：general=tag 含 `universal`；role=tag 含 `role:*`；axis=tag 含 `axis:*`；fallback=tag 含 `fallback`  
> PR：#63（content: gapfill highlights blindspots pools）

| bucket | Target | Current | Gap | Notes |
|---|---:|---:|---:|---|
| general | 10 | 10 | 0 | blindspots.total=29 |
| role | 4 | 4 | 0 | 已覆盖 NT/NF/SJ/SP |
| axis | 3 | 10 | 0 | 覆盖 EI/SN/TF/JP/AT（按侧拆分更佳） |
| fallback | 5 | 5 | 0 | tag:fallback |

### 2.4 actions 缺口（Done）

> 来源：`report_highlights_pools.json` → `pools.action.items[]`  
> 统计口径：general=tag 含 `universal`；role=tag 含 `role:*`；axis=tag 含 `axis:*`；fallback=tag 含 `fallback`  
> PR：#65（content: gapfill highlights actions pools）

| bucket | Target | Current | Gap | Notes |
|---|---:|---:|---:|---|
| general | 10 | 10 | 0 | actions.total=29 |
| role | 4 | 4 | 0 | 已覆盖 NT/NF/SJ/SP |
| axis | 3 | 10 | 0 | 覆盖 EI/SN/TF/JP/AT（按侧拆分更佳） |
| fallback | 5 | 5 | 0 | tag:fallback |

---

## 3) TODO（下一步把 TBD 变成数字）

- [ ] 为 highlights 增加统计口径（按 general/role/axis/fallback + section）并回填 Current/Gap  
  - [x] strengths 已回填（PR #60）
  - [x] blindspots 已回填（PR #63）
  - [x] actions 已回填（PR #65）  
- [x] reads.fallback（通用池）补齐到 ≥10（PR #67；当前 10，缺口 0）  
- [ ] 将本表纳入内容同学的每周补库节奏（缺口优先级：fallback → general → axis → role）