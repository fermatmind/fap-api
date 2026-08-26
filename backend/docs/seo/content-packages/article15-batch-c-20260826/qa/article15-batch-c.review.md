# Article15 批次 C 内容包 QA

- 批次：ARTICLE15-BATCH-C-20260826
- 状态：content_package_only
- 目标：5/5，顺序固定为 Holland zh-CN、EQ zh-CN、Big Five EN、MBTI vs Holland EN、MBTI Full Report EN
- 基线 commit：6f172f279a02ef1b6ac57f59ed88cf99e6235793
- Batch A manifest SHA：48a79cdcfc5b10766b58658aadb601e0682257af56d4fd108ed920a2ed6fa20a
- Batch B manifest SHA：91156cf83eba46b2f687e527aaf3dc1694a63c4bd660ee3ea5e8d82c9d6bc96a
- Top100 source SHA：e4c9788d6fadff53bc33170299971ab57dbff1810ad1fcdb4f97f5f4ee94d150
- Article15 target-set SHA：9ccd8cab8b44f10409e0ef3ced944ba663376c84a5032bd74ddba143d88cd35a
- batch manifest SHA：d1813ce799ead9cfad90701ecd7fe716855b128e0d12bea0a7b882b7d2e0273a

## 包身份

| # | locale / slug | article / revision | package SHA | FAQ | reading_minutes | 计划 / 正文唯一链接 |
|---:|---|---|---|---:|---:|---:|
| 1 | zh-CN / holland-career-interest-test-can-and-cannot-tell-you | 31 / 36 | 4cc742709545d3bc2ad952fae64078d9d8b4d8275a6d055c0823e28ae242a9ef | 4 | 8 | 3 / 3 |
| 2 | zh-CN / eq-test-tool-guide | 4 / 58 | ee1f35c23b57863505a2620acc7440f53a195a452a2d760fad38309e09c610ea | 7 | 11 | 11 / 11 |
| 3 | en / big-five-emotional-stability-stress-recovery-communication | 79 / 99 | e58e9742f92651bbf8465a4387b051aa95643bd8c47dae9ec261c65b0316d3a2 | 6 | 23 | 1 / 1 |
| 4 | en / mbti-vs-holland-code-career-choice | 39 / 44 | 043dae5246afe27b47b3bf337f80792f81693a570d9b6dea7f36f6707963060b | 5 | 19 | 3 / 0 |
| 5 | en / mbti-full-report-career-relationship-communication | 61 / 81 | 78428063f356b0955cff9574da8fc87ff964edeaf4a9fa21519a62e89884ca35 | 8 | 26 | 2 / 2 |

## QA 结论

- identity 与线上 revision 锁：PASS。
- Batch A/B/C 两两不重叠：0；累计覆盖 Article15：15/15。
- title、H1、SEO title、description 与指定 KEEP 字段逐字一致：PASS。
- 仅 Holland 正文替换一次非 canonical Big Five 路径；其余 4 篇正文 SHA 不变：PASS。
- FAQ 正文、package FAQ 与 proposed answer surface 逐项一致，数量 4/7/6/5/8：PASS。
- 每页唯一 primary CTA；MBTI vs Holland 的 MBTI/Big Five 仅为非行动型 context links：PASS。
- English 三页 reader-visible CJK=0、locale=en、中文路径=0：PASS。
- private/result/order/payment/share/token URL 与诊断、职业保证、招聘、能力、关系预测越界：0。
- query-owner registry、slug、canonical、schema、hreflang、sitemap、llms、Search Channel、publication 写入：0。
- reading_minutes、related_test_slug、FAQ answer surface 的 revision-bound adapter 尚未支持；全部 import_ready=false。
- 完整 Markdown/raw path/CTA/link plan 共 18 个唯一站内目标均为 200 self-canonical：PASS。
- JSON/Markdown、独立 SHA 复算、reading-minutes、classifier/workflow 44/44、git diff 与 changed-file scope：PASS；分类为 docs_rules_tests_only，deploy=false。
