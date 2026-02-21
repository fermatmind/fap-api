# Psychometrics (v0.3)

本规范描述 PR11 引入的 psychometrics 输出：z / percentile / stanine / SE / CI，以及质量控制与报告输出口径。

## 1. 指标定义

- **z-score**：`z = (x - mean) / sd`。当 `sd=0` 或缺失时，z 退化为 0。
- **percentile (0-100)**：从常模 CDF 分桶插值得到；输入为维度分数（本 PR 为 0..100 的 percent）。
- **stanine (1-9)**：由 percentile 映射：
  - 1: <4
  - 2: 4..10
  - 3: 11..22
  - 4: 23..39
  - 5: 40..59
  - 6: 60..76
  - 7: 77..88
  - 8: 89..95
  - 9: >=96
- **SE (standard error)**：`SE = sd / sqrt(n)`，当 `n<=0` 或 `sd<=0` 时返回 null。
- **CI (confidence interval)**：`x ± z * SE`。本 PR 默认置信度 0.95，`z=1.96`。

## 2. 分桶常模与 CDF

- norms.json 以 **bucket** 组织常模，bucket 的 `keys` 至少包含 region/locale，允许叠加年龄/性别。
- bucket 的 `cdf` 为分段点列：`[{score, cdf}, ...]`。
- percentile 计算：
  - 若 score 小于最小点，取最小 cdf；大于最大点，取最大 cdf。
  - 位于两点之间时线性插值。
  - cdf 可为 0..1 或 0..100，最终输出 0..100。

## 3. 质量控制 (Quality Checks)

当前支持的 check 类型：

- `min_answer_count`：最少答题数。
- `max_same_option_ratio`：同选项占比阈值。
- `reverse_pair_mismatch_ratio`：反向题对不一致占比。

grade 规则：
- `A` → 最优
- `B` → 轻度异常
- `C` → 明显异常
- `D` → 无效/高风险

## 4. 输出口径

### 4.1 attempts snapshot（冻结）

`attempts.calculation_snapshot_json` 结构：

```json
{
  "norm": {
    "norm_id": "...",
    "version": "...",
    "checksum": "...",
    "bucket_keys": ["region", "locale", "gender", "age_group"],
    "bucket": {"id": "...", "keys": {"region": "CN_MAINLAND", "locale": "zh-CN"}}
  },
  "scoring": {"spec_version": "...", "rules_checksum": "..."},
  "pack": {"pack_id": "...", "dir_version": "...", "version_checksum": "..."},
  "stats": {"confidence": 0.95, "dimensions": {"EI": {"z": 0.2, "percentile": 52, "stanine": 5, "se": 0.34, "ci": {"lower": 48.3, "upper": 51.7}}}},
  "quality": {"grade": "A", "checks": [{"id": "min_answers", "passed": true, "score": 100}]},
  "computed_at": "2026-01-28T00:00:00Z"
}
```

### 4.2 API

- `GET /api/v0.3/scales/{scale}/norms`：列出可用常模版本、bucket keys。
- `GET /api/v0.3/attempts/{id}/stats`：返回 snapshot.stats。
- `GET /api/v0.3/attempts/{id}/quality`：返回 grade + checks。
- `GET /api/v0.3/attempts/{id}/report?include=psychometrics`：report 内追加 `psychometrics` 字段。

### 4.3 auth 口径

- norms/stats/quality/report 都走 `FmTokenOptional`（匿名可读，若带 token 则注入 fm_user_id）。

## 5. 解释边界

- psychometrics 输出只用于 **解释性参考**，不用于诊断或医学结论。
- 常模仅代表 norms.json 的样本域；跨地域/语言/时间的外推应谨慎。
- quality grade 是风控参考，不应单独作为否定用户体验的依据。
