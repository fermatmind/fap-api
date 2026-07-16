# Big Five V2 中文首批 6 页审核与来源权威包

本包只覆盖一个受控 cohort：中文 Big Five Hub 与开放性、尽责性、外向性、宜人性、神经质 5 个维度页。它保留逐页编辑预审与真实人工审核证据，同时按锁定 PR05 source ledger 对公开 claim 权威 fail closed。

## 当前结论

- 6/6 页完成逐页编辑深读与自动边界检查，未发现诊断、招聘/录取筛选、确定性预测、私人结果链接或未经支持的准确性声明。
- Hub 的 3 个公开学术来源与 claim 映射通过锁定 PR05 ledger，因此当前来源权威完成度是 **1/6**。
- 5 个维度页原先只有 Goldberg 与 BFI-2 两个来源，却展示与 IPIP/NEO 传统相近的每维 6 个、合计 30 个侧面。BFI-2 自身是每维 3 个、合计 15 个侧面的模型，不能单独替 30 侧面命名背书。
- 候选内容为每个维度页增加 IPIP 官方 NEO 侧面对照表作为第 3 个可见来源，并明确写出：30 侧面导航不等于 BFI-2 的 15 个侧面、不等于专有 NEO 量表分数，也不是所有大五工具的通用分类。
- 5 个维度页同时映射了 `claim.big_five.taxonomies_not_interchangeable`；锁定 PR05 ledger 将该 claim 标为 `allowed_as_public_claim=false`，且不包含 `domain` page family。因此这 5 页全部处于 `blocked_by_locked_source_ledger`，不得 promotion、CMS 绑定或发布。
- CMS 管理员 `admin_user:1` 于 `2026-07-16T07:42:19Z` 确认的 6 个候选正文 SHA 与审核记录 SHA256 `3ea9a9052e54c9595bda7c1289606f0d31269e728455a4f2124a9f7e0e58daa9` 原样保留。人工审核确认内容与审核时看到的映射，但不能覆盖锁定 source ledger 的公开授权限制，也不能把 1/6 来源权威提升为 6/6。

## 精确 cohort

1. `/zh/personality/big-five`
2. `/zh/personality/big-five/openness`
3. `/zh/personality/big-five/conscientiousness`
4. `/zh/personality/big-five/extraversion`
5. `/zh/personality/big-five/agreeableness`
6. `/zh/personality/big-five/neuroticism`

候选正文及每页 SHA256 位于：

`generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/candidate-package.json`

## Source authority decision

本 cohort 的来源使用只允许：书目信息、公开链接、简短事实说明与原创转述。不得复制问卷题目、表格、图像、摘要或大段原文；不得把来源存在改写成对费马测试分数、信效度、常模、诊断或个体结果的验证。可访问、可引用的来源不自动等于某个 claim 已获公开授权；每个 claim 还必须同时满足 `allowed_as_public_claim=true`、page-family 适用与 source-id 映射约束。

| 来源 | 本 cohort 支持范围 | 明确限制 |
| --- | --- | --- |
| Goldberg (1990), DOI `10.1037/0022-3514.59.6.1216` | 宽泛大五因子结构 | 不验证费马测试分数，不定义通用侧面体系 |
| Soto & John (2017), DOI `10.1037/pspp0000096` | 五维度与层级描述；BFI-2 的 15 侧面实例 | 不得与 30 侧面导航或其他模型默认互换 |
| Roberts, Walton & Viechtbauer (2006), DOI `10.1037/0033-2909.132.1.1` | Hub 中群体均值随生命历程变化的限定表述 | 不预测或保证具体个体会改变 |
| International Personality Item Pool, `https://ipip.ori.org/newNEO_FacetsTable.htm` | 5 个维度页所用 30 侧面导航的名称与维度归属 | 不代表专有 NEO 工具、BFI-2 侧面或费马测试分数与其等同 |

DOI 元数据于 2026-07-16 通过 Crossref REST 核验；IPIP 页面于同日通过其官方公开站点核验。IPIP 是维度页第 3 个公开来源，不替换 Hub 中用于变化边界的 Roberts 等来源。

当前锁定 ledger 下，IPIP 与 BFI-2 来源可以作为候选内容的可见参考，但不能使 `claim.big_five.taxonomies_not_interchangeable` 在 domain 页面获得公开 claim 权威。后续必须由独立、明确授权的 authority 修订决定完成：要么使用 PR05 已允许且适用于 domain 的 claim 重新映射并重新进行真实人工审核，要么先独立修订 source ledger 的 claim 决策；本修复不擅自选择任何一条路径。

## 人工审核签核要求

真实人工审核者必须阅读 `candidate-package.json` 中锁定的 6 个 `content` 对象，并确认：

1. 中文表达、场景和行动建议可公开呈现；
2. claim 与 visible source/limitation 的映射可接受；
3. 30 侧面、BFI-2 与专有 NEO 的分类边界清楚；
4. 非诊断、非筛选、非确定性预测边界完整；
5. 对 6 个 `candidate_content_sha256` 所代表的内容作同一批次批准。

签核记录绑定真实正整数 `reviewer_admin_user_id`、ISO 8601 `reviewed_at` 和最终审核记录的 SHA256。缺任一字段时继续 fail closed；不得只填写姓名、角色文本或由自动化代签。

历史签核使用的操作者确认语句：

> 我已阅读并批准 `big_five_v2_zh_cn_hub_plus_five_domains_01` 中 6 个锁定候选页，认可其中文内容、来源映射和边界说明，并同意把我的 CMS 管理员身份与本次审核记录绑定。媒体授权、CMS 写入、发布和索引状态不在本次批准范围内。

该历史签核只证明人工实际审阅过锁定候选内容；在 source-ledger blocker 被独立修复、内容重新哈希并再次精确签核之前，不得复用该语句或审核记录作为 promotion 授权。

## 运行与发布边界

- CMS/backend 仍是公开内容权威；候选包不是运行时权威。
- 本包不改历史生成物，不执行 CMS/数据库或生产写入。
- 本包不改变 published/content_ready、indexability、sitemap、llms、schema 或前端渲染。
- 媒体授权、运行时 revision 绑定、rollback 准备与受控 promotion 明确保留，不能因来源与人工审核完成而自动解锁。
- 5 个维度页新增显式 `source_authority_blocked_by_locked_ledger` promotion blocker；人工审核记录不能移除此 blocker。

## 验证

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/build-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/validate-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/validate-attestation.mjs
cd backend && php artisan test tests/Feature/SEO/BigFiveAuthorityV2Zh6ReviewSourceCohortTest.php --no-ansi
git diff --check
```

## Repository rule impact

无内容权威或发布流程变更。本修复只纠正 generated-contract-only 候选包的 authority disposition，并增加对锁定 PR05 source ledger 的可执行校验；CMS/backend 继续负责公开内容、人工身份、审核账本、媒体、发布和索引状态。
