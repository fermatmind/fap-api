# Article15 GSC 基线与全站查询所有权（2026-08-26）

状态：`VERIFIED_WITH_ONE_AUTHORITY_REGISTRY_GAP`。GSC 终日 2026-08-23 已满足 America/Los_Angeles 三日 finalization lag；所有数值来自只读 Search Analytics API。

## 执行边界与证据

- Top100 source SHA：`e4c9788d6fadff53bc33170299971ab57dbff1810ad1fcdb4f97f5f4ee94d150`；冻结文件未改。
- Article15 target-set SHA：`9ccd8cab8b44f10409e0ef3ced944ba663376c84a5032bd74ddba143d88cd35a`；顺序、locale 与名单固定为 12 个 zh-CN、3 个 en。
- GSC property：`sc-domain:fermatmind.com`；Search type=`web`；country/device 不限；窗口为 2026-07-27..2026-08-23 与 2026-05-26..2026-08-23。
- 页面总量与 page×query 明细分别请求；差额是 GSC 隐藏/匿名化覆盖缺口，未补 0。
- 全站 owner 依据当前 backend/CMS/scale-catalog authority、公开 HTTP 与锁定意图规则；未写入任何外部或运行时系统。

## 15 页表现

| # | Locale | Canonical | 28d C/I/CTR/Pos | 90d C/I/CTR/Pos | 90d query coverage gap |
|---:|---|---|---:|---:|---:|
| 1 | zh-CN | [/zh/articles/mbti-basics](https://fermatmind.com/zh/articles/mbti-basics) | 0 / 618 / 0.00% / 13.17 | 4 / 2344 / 0.17% / 14.17 | C 2, I 1822 |
| 2 | zh-CN | [/zh/articles/big-five-tool-guide](https://fermatmind.com/zh/articles/big-five-tool-guide) | 8 / 356 / 2.25% / 9.29 | 20 / 2326 / 0.86% / 7.89 | C 12, I 2037 |
| 3 | zh-CN | [/zh/articles/are-infj-men-rare-or-socially-silenced](https://fermatmind.com/zh/articles/are-infj-men-rare-or-socially-silenced) | 9 / 228 / 3.95% / 7.95 | 17 / 536 / 3.17% / 9.60 | C 13, I 458 |
| 4 | zh-CN | [/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist](https://fermatmind.com/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist) | 0 / 528 / 0.00% / 7.42 | 6 / 4300 / 0.14% / 7.33 | C 6, I 4288 |
| 5 | zh-CN | [/zh/articles/holland-career-interest-test-can-and-cannot-tell-you](https://fermatmind.com/zh/articles/holland-career-interest-test-can-and-cannot-tell-you) | 0 / 23 / 0.00% / 8.74 | 0 / 27 / 0.00% / 8.59 | C 0, I 23 |
| 6 | zh-CN | [/zh/articles/riasec-holland-career-interest-test-explained](https://fermatmind.com/zh/articles/riasec-holland-career-interest-test-explained) | 0 / 260 / 0.00% / 6.42 | 4 / 1516 / 0.26% / 7.80 | C 4, I 1359 |
| 7 | zh-CN | [/zh/articles/iq-test-score-and-limits-explained](https://fermatmind.com/zh/articles/iq-test-score-and-limits-explained) | 0 / 140 / 0.00% / 6.17 | 0 / 541 / 0.00% / 8.04 | C 0, I 524 |
| 8 | zh-CN | [/zh/articles/which-love-script-fits-you-best](https://fermatmind.com/zh/articles/which-love-script-fits-you-best) | 0 / 200 / 0.00% / 7.99 | 1 / 420 / 0.24% / 10.30 | C 1, I 406 |
| 9 | zh-CN | [/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist](https://fermatmind.com/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist) | 0 / 41 / 0.00% / 6.15 | 0 / 207 / 0.00% / 10.26 | C 0, I 185 |
| 10 | zh-CN | [/zh/articles/mbti-narrative-portrait](https://fermatmind.com/zh/articles/mbti-narrative-portrait) | 1 / 106 / 0.94% / 10.26 | 1 / 164 / 0.61% / 8.88 | C 1, I 141 |
| 11 | zh-CN | [/zh/articles/enneagram-personality-test-explained](https://fermatmind.com/zh/articles/enneagram-personality-test-explained) | 3 / 668 / 0.45% / 6.67 | 8 / 2034 / 0.39% / 9.39 | C 6, I 1937 |
| 12 | zh-CN | [/zh/articles/eq-test-tool-guide](https://fermatmind.com/zh/articles/eq-test-tool-guide) | 1 / 85 / 1.18% / 7.26 | 1 / 570 / 0.18% / 13.16 | C 1, I 553 |
| 13 | en | [/en/articles/big-five-emotional-stability-stress-recovery-communication](https://fermatmind.com/en/articles/big-five-emotional-stability-stress-recovery-communication) | 0 / 29 / 0.00% / 16.55 | 0 / 325 / 0.00% / 13.37 | C 0, I 291 |
| 14 | en | [/en/articles/mbti-vs-holland-code-career-choice](https://fermatmind.com/en/articles/mbti-vs-holland-code-career-choice) | 1 / 45 / 2.22% / 7.42 | 3 / 207 / 1.45% / 9.09 | C 3, I 205 |
| 15 | en | [/en/articles/mbti-full-report-career-relationship-communication](https://fermatmind.com/en/articles/mbti-full-report-career-relationship-communication) | 0 / 36 / 0.00% / 14.11 | 0 / 81 / 0.00% / 12.56 | C 0, I 80 |

## 全站 query owner 矩阵

| Cluster | Primary owner | Hashes | 28d C/I/CTR/Pos | 90d C/I/CTR/Pos | Confidence | Conflict |
|---|---|---:|---:|---:|---|---|
| `big_five_emotional_stability_en` | [/en/articles/big-five-emotional-stability-stress-recovery-communication](https://fermatmind.com/en/articles/big-five-emotional-stability-stress-recovery-communication) | 1 | 0 / 0 / 0.00% / Unknown | 0 / 3 / 0.00% / 10.67 | high | `aligned_or_no_competing_page` |
| `big_five_model_overview_en` | [/en/personality/big-five](https://fermatmind.com/en/personality/big-five) | 3 | 0 / 16 / 0.00% / 50.19 | 0 / 33 / 0.00% / 27.03 | high | `observed_competing_pages` |
| `big_five_model_overview_zh-CN` | [/zh/personality/big-five](https://fermatmind.com/zh/personality/big-five) | 11 | 4 / 38 / 10.53% / 11.53 | 5 / 198 / 2.53% / 9.21 | medium | `observed_competing_pages` |
| `big_five_test_zh-CN` | [/zh/tests/big-five-personality-test-ocean-model](https://fermatmind.com/zh/tests/big-five-personality-test-ocean-model) | 5 | 5 / 56 / 8.93% / 9.29 | 6 / 104 / 5.77% / 10.06 | high | `observed_competing_pages` |
| `brand_navigation_en` | [/en](https://fermatmind.com/en) | 2 | 0 / 320 / 0.00% / 57.06 | 5 / 781 / 0.64% / 33.62 | high | `aligned_or_no_competing_page` |
| `enneagram_model_explainer_zh-CN` | [/zh/articles/enneagram-personality-test-explained](https://fermatmind.com/zh/articles/enneagram-personality-test-explained) | 6 | 1 / 58 / 1.72% / 11.12 | 2 / 103 / 1.94% / 15.56 | high | `observed_competing_pages` |
| `enneagram_test_zh-CN` | [/zh/tests/enneagram-personality-test-nine-types](https://fermatmind.com/zh/tests/enneagram-personality-test-nine-types) | 2 | 5 / 264 / 1.89% / 7.80 | 6 / 399 / 1.50% / 8.28 | high | `aligned_or_no_competing_page` |
| `enneagram_type_9_wing_1_zh-CN` | [/zh/personality/enneagram/wings/9w1](https://fermatmind.com/zh/personality/enneagram/wings/9w1) | 1 | 0 / 2 / 0.00% / 7.00 | 0 / 5 / 0.00% / 14.80 | high | `observed_competing_pages` |
| `enneagram_type_9_zh-CN` | [/zh/personality/enneagram/type-9](https://fermatmind.com/zh/personality/enneagram/type-9) | 1 | 0 / 0 / 0.00% / Unknown | 0 / 3 / 0.00% / 36.00 | high | `owner_not_observed_as_top_page` |
| `eq_test_zh-CN` | [/zh/tests/eq-test-emotional-intelligence-assessment](https://fermatmind.com/zh/tests/eq-test-emotional-intelligence-assessment) | 1 | 0 / 24 / 0.00% / 8.63 | 0 / 42 / 0.00% / 9.38 | high | `aligned_or_no_competing_page` |
| `gaokao_major_adjustment_zh-CN` | [/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist](https://fermatmind.com/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist) | 2 | 0 / 0 / 0.00% / Unknown | 0 / 11 / 0.00% / 11.27 | high | `aligned_or_no_competing_page` |
| `generic_personality_test_zh-CN` | [/zh/tests](https://fermatmind.com/zh/tests) | 7 | 0 / 97 / 0.00% / 9.64 | 4 / 214 / 1.87% / 9.72 | medium | `observed_competing_pages` |
| `infj_men_visibility_zh-CN` | [/zh/articles/are-infj-men-rare-or-socially-silenced](https://fermatmind.com/zh/articles/are-infj-men-rare-or-socially-silenced) | 6 | 2 / 33 / 6.06% / 8.58 | 4 / 64 / 6.25% / 8.41 | high | `aligned_or_no_competing_page` |
| `iq_result_limits_zh-CN` | [/zh/articles/iq-test-score-and-limits-explained](https://fermatmind.com/zh/articles/iq-test-score-and-limits-explained) | 2 | 0 / 3 / 0.00% / 7.67 | 0 / 8 / 0.00% / 8.25 | high | `aligned_or_no_competing_page` |
| `love_script_triangle_zh-CN` | [/zh/articles/which-love-script-fits-you-best](https://fermatmind.com/zh/articles/which-love-script-fits-you-best) | 2 | 0 / 3 / 0.00% / 9.67 | 0 / 6 / 0.00% / 12.83 | high | `aligned_or_no_competing_page` |
| `mbti_definition_zh-CN` | [/zh/articles/mbti-basics](https://fermatmind.com/zh/articles/mbti-basics) | 5 | 0 / 18 / 0.00% / 11.00 | 0 / 84 / 0.00% / 12.35 | high | `aligned_or_no_competing_page` |
| `mbti_model_overview_en` | [/en/topics/mbti](https://fermatmind.com/en/topics/mbti) | 2 | 0 / 8 / 0.00% / 40.50 | 0 / 15 / 0.00% / 24.53 | medium | `observed_competing_pages` |
| `mbti_model_overview_zh-CN` | [/zh/topics/mbti](https://fermatmind.com/zh/topics/mbti) | 30 | 7 / 463 / 1.51% / 14.83 | 12 / 811 / 1.48% / 16.93 | medium | `observed_competing_pages` |
| `mbti_result_use_zh-CN` | [/zh/articles/mbti-narrative-portrait](https://fermatmind.com/zh/articles/mbti-narrative-portrait) | 1 | 0 / 3 / 0.00% / 5.67 | 0 / 4 / 0.00% / 4.75 | high | `observed_competing_pages` |
| `mbti_test_zh-CN` | [/zh/tests/mbti-personality-test-16-personality-types](https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types) | 11 | 18 / 1257 / 1.43% / 8.75 | 29 / 2880 / 1.01% / 8.58 | high | `aligned_or_no_competing_page` |
| `mbti_type_infj_zh-CN` | [/zh/personality/infj](https://fermatmind.com/zh/personality/infj) | 1 | 0 / 11 / 0.00% / 10.36 | 0 / 11 / 0.00% / 10.36 | high | `authority_registry_gap` |
| `riasec_model_result_explainer_en` | [/en/articles/what-is-riasec-holland-code-career-interest-test](https://fermatmind.com/en/articles/what-is-riasec-holland-code-career-interest-test) | 1 | 1 / 79 / 1.27% / 47.78 | 1 / 263 / 0.38% / 42.68 | high | `aligned_or_no_competing_page` |
| `riasec_model_result_explainer_zh-CN` | [/zh/articles/riasec-holland-career-interest-test-explained](https://fermatmind.com/zh/articles/riasec-holland-career-interest-test-explained) | 5 | 1 / 3 / 33.33% / 11.00 | 1 / 16 / 6.25% / 12.81 | high | `aligned_or_no_competing_page` |
| `riasec_test_zh-CN` | [/zh/tests/holland-career-interest-test-riasec](https://fermatmind.com/zh/tests/holland-career-interest-test-riasec) | 5 | 8 / 274 / 2.92% / 8.24 | 10 / 356 / 2.81% / 8.63 | high | `aligned_or_no_competing_page` |
| `unwanted_major_repeat_or_stay_zh-CN` | [/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist](https://fermatmind.com/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist) | 2 | 0 / 1 / 0.00% / 7.00 | 0 / 19 / 0.00% / 7.58 | high | `aligned_or_no_competing_page` |

每个 active cluster 恰有一个 primary owner；支持 URL、禁止抢占标签、query hash 集合、两窗口完整指标和 owner 证据见结构化 JSON。

## 冲突与污染

- `/zh/personality/infj` 为公开 200 self-canonical，但当前动态 URL Truth authority source 未收录；该唯一 owner 保留，cluster 标记 `authority_registry_gap`，后续不得在未修复 registry 前做 owner 写入。
- `observed_competing_pages` cluster 数：9；这表示 90 天 GSC 在多个公开页面观察到同一 query family，owner 规则仍保持唯一。
- 现有 query-owner registry 只读检查：PASS，family=0，conflict=0；本基线没有将文档矩阵写回 registry。

| Masked query | 90d impressions | Reason | Observed target |
|---|---:|---|---|
| `u************业` | 87 | `obvious_mismatch` | `/zh/articles/riasec-holland-career-interest-test-explained` |
| `i****嗎` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/iq-test-score-and-limits-explained` |
| `心********b` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/mbti-narrative-portrait` |
| `吗*` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/enneagram-personality-test-explained` |
| `r************业` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/riasec-holland-career-interest-test-explained` |
| `r***********业` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/riasec-holland-career-interest-test-explained` |
| `心*******型` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/enneagram-personality-test-explained` |
| `e**定` | 2 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/eq-test-tool-guide` |
| `j***人` | 1 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/mbti-basics` |
| `m*****格` | 1 | `obvious_mismatch_or_unclassifiable` | `/zh/articles/mbti-basics` |

## 下一批内容优化优先级

1. **高考调剂 checklist**：90d 4,300 impressions、position 7.33、CTR 0.14%；先做 title/meta 与首屏意图假设，因 query coverage gap 极高，不据隐藏查询扩写主题。
2. **Big Five tool guide**：90d 2,326 impressions、20 clicks、position 7.89；把测试意图明确交给 test landing、模型总览交给 Big Five Hub，本页只强化结果使用。
3. **MBTI basics**：90d 2,344 impressions、position 14.17、CTR 0.17%；保留“MBTI 是什么”例外 owner，收紧测试/具体类型抢占。
4. **Enneagram explainer**：90d 2,034 impressions、position 9.39、CTR 0.39%；强化“九型人格是什么”，将测试与具体 type/wing 意图导向各自 canonical。
5. **RIASEC explainer**：90d 1,516 impressions、position 7.80、CTR 0.26%；分离 test、模型/结果解释和 capability/limits，并排除已识别的明显失配污染。

INFJ 男性页已有 90d CTR 3.17%，当前以保持 owner 清晰和补齐 `/zh/personality/infj` authority registry 为主；三个英文目标先继续积累 D28 数据，不据低可见 query 量重写。

## 零写入与后续使用

CMS writes=0；DB writes=0；GSC writes=0；Search writes=0；publication=0；sitemap/llms=0；Top100 source changes=0。原 Top100 HOLD 状态不变；本资产只作为后续 Article15 内容批次的只读决策输入。

结构化明细：[ownership JSON](generated/article15-query-ownership-20260826.v1.json)；查询基线：[page-query CSV](generated/article15-gsc-page-query-baseline-20260826.v1.csv)。
