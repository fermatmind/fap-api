# Big Five V2 中文首批 6 页审核与来源权威包

本包只覆盖一个受控 cohort：中文 Big Five Hub 与开放性、尽责性、外向性、宜人性、神经质 5 个维度页。它修正基础来源映射并提供逐页编辑预审证据，供真实人工审核者对锁定内容做最终签核。

## 当前结论

- 6/6 页完成逐页编辑深读与自动边界检查，未发现诊断、招聘/录取筛选、确定性预测、私人结果链接或未经支持的准确性声明。
- Hub 已有 3 个公开学术来源，分别支持五维结构、层级模型和群体均值变化边界。
- 5 个维度页原先只有 Goldberg 与 BFI-2 两个来源，却展示与 IPIP/NEO 传统相近的每维 6 个、合计 30 个侧面。BFI-2 自身是每维 3 个、合计 15 个侧面的模型，不能单独替 30 侧面命名背书。
- 候选内容为每个维度页增加 IPIP 官方 NEO 侧面对照表作为第 3 个可见来源，并明确写出：30 侧面导航不等于 BFI-2 的 15 个侧面、不等于专有 NEO 量表分数，也不是所有大五工具的通用分类。
- 基础来源权威与真实人工签核均已完成。CMS 管理员 `admin_user:1` 于 `2026-07-16T07:42:19Z` 重新确认最终候选 SHA；有效审核记录 SHA256 为 `3ea9a9052e54c9595bda7c1289606f0d31269e728455a4f2124a9f7e0e58daa9`。此前因删除 4 处面向读者的“待审阅/草稿”流程措辞而失效的首次签核，继续以 `superseded_attestation` 保留，未被冒用到新正文。

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

本 cohort 的来源使用只允许：书目信息、公开链接、简短事实说明与原创转述。不得复制问卷题目、表格、图像、摘要或大段原文；不得把来源存在改写成对费马测试分数、信效度、常模、诊断或个体结果的验证。

| 来源 | 本 cohort 支持范围 | 明确限制 |
| --- | --- | --- |
| Goldberg (1990), DOI `10.1037/0022-3514.59.6.1216` | 宽泛大五因子结构 | 不验证费马测试分数，不定义通用侧面体系 |
| Soto & John (2017), DOI `10.1037/pspp0000096` | 五维度与层级描述；BFI-2 的 15 侧面实例 | 不得与 30 侧面导航或其他模型默认互换 |
| Roberts, Walton & Viechtbauer (2006), DOI `10.1037/0033-2909.132.1.1` | Hub 中群体均值随生命历程变化的限定表述 | 不预测或保证具体个体会改变 |
| International Personality Item Pool, `https://ipip.ori.org/newNEO_FacetsTable.htm` | 5 个维度页所用 30 侧面导航的名称与维度归属 | 不代表专有 NEO 工具、BFI-2 侧面或费马测试分数与其等同 |

DOI 元数据于 2026-07-16 通过 Crossref REST 核验；IPIP 页面于同日通过其官方公开站点核验。IPIP 是维度页第 3 个公开来源，不替换 Hub 中用于变化边界的 Roberts 等来源。

## 人工审核签核要求

真实人工审核者必须阅读 `candidate-package.json` 中锁定的 6 个 `content` 对象，并确认：

1. 中文表达、场景和行动建议可公开呈现；
2. claim 与 visible source/limitation 的映射可接受；
3. 30 侧面、BFI-2 与专有 NEO 的分类边界清楚；
4. 非诊断、非筛选、非确定性预测边界完整；
5. 对 6 个 `candidate_content_sha256` 所代表的内容作同一批次批准。

签核记录绑定真实正整数 `reviewer_admin_user_id`、ISO 8601 `reviewed_at` 和最终审核记录的 SHA256。缺任一字段时继续 fail closed；不得只填写姓名、角色文本或由自动化代签。

建议操作者确认语句：

> 我已阅读并批准 `big_five_v2_zh_cn_hub_plus_five_domains_01` 中 6 个锁定候选页，认可其中文内容、来源映射和边界说明，并同意把我的 CMS 管理员身份与本次审核记录绑定。媒体授权、CMS 写入、发布和索引状态不在本次批准范围内。

## 运行与发布边界

- CMS/backend 仍是公开内容权威；候选包不是运行时权威。
- 本包不改历史生成物，不执行 CMS/数据库或生产写入。
- 本包不改变 published/content_ready、indexability、sitemap、llms、schema 或前端渲染。
- 媒体授权、运行时 revision 绑定、rollback 准备与受控 promotion 明确保留，不能因来源与人工审核完成而自动解锁。

## 验证

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/build-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/validate-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/validate-attestation.mjs
git diff --check
```

## Repository rule impact

无内容权威或发布流程变更。新增的是 generated-contract-only 候选包与证据说明；CMS/backend 继续负责公开内容、人工身份、审核账本、媒体、发布和索引状态。
