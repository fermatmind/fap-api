# Article15 批次 B 内容包 QA

- 批次：ARTICLE15-BATCH-B-20260826
- 状态：content_package_only
- 目标：5/5，顺序固定为 INFJ、IQ、爱情三角、专业不理想、MBTI narrative
- 基线 commit：6f172f279a02ef1b6ac57f59ed88cf99e6235793
- Batch A manifest SHA：48a79cdcfc5b10766b58658aadb601e0682257af56d4fd108ed920a2ed6fa20a
- Top100 source SHA：e4c9788d6fadff53bc33170299971ab57dbff1810ad1fcdb4f97f5f4ee94d150
- Article15 target-set SHA：9ccd8cab8b44f10409e0ef3ced944ba663376c84a5032bd74ddba143d88cd35a
- batch manifest SHA：91156cf83eba46b2f687e527aaf3dc1694a63c4bd660ee3ea5e8d82c9d6bc96a

## 包身份

| # | slug | article / revision | package SHA | FAQ | reading_minutes | 计划 / 正文唯一链接 |
|---:|---|---|---|---:|---:|---:|
| 1 | are-infj-men-rare-or-socially-silenced | 11 / 436 | 0bebdf6a606709b834184eb2e538a67612be8c80b35f69ab6d6fecdee8150149 | 5 | 5 | 2 / 1 |
| 2 | iq-test-score-and-limits-explained | 50 / 57 | 21b1edec935f9298cfb316a712ef1ebde2e9846a9910fbf8f37d371ddd20631f | 7 | 10 | 11 / 11 |
| 3 | which-love-script-fits-you-best | 16 / 435 | 701d3391a8d23cb19566e9a31ab61039a1e6d2f887c27878776211b97825e3c8 | 4 | 5 | 1 / 1 |
| 4 | unwanted-major-repeat-or-stay-riasec-decision-checklist | 74 / 94 | 3418ba5d13ce8f7944ada923a27f7c043215c9b6f76a6b822bbb702fd8ba2aef | 7 | 12 | 1 / 1 |
| 5 | mbti-narrative-portrait | 10 / 440 | dd17e189f9a2bdeeced01595448c967822dc4b9d90391efe9f6088dfdec8ec3e | 5 | 5 | 3 / 2 |

## QA 结论

- identity 与线上 revision 锁：PASS。
- Batch A/B 不重叠：0；累计覆盖 Article15：10/15。
- title、H1、SEO title、description 与各页指定 KEEP 字段逐字一致：PASS。
- INFJ 与 MBTI narrative 正文 SHA 保持不变：PASS。
- FAQ 正文、package FAQ 与 answer surface 逐项一致，数量 5/7/4/7/5：PASS。
- 每页唯一 primary CTA；爱情三角使用 content_continue，related_test_slug 保持 null：PASS。
- 完整 Markdown 与 link plan 合并后的 17 个唯一站内链接均返回 200 且 self-canonical：PASS。
- forbidden INFJ detail route 出现次数：0。
- private/result/order/payment/share/token URL：0。
- 隐藏查询推断、诊断、能力、职业、录取、关系预测类越界主张：0。
- query-owner registry、INFJ registry、slug、canonical、schema、hreflang、sitemap、llms、Search Channel、publication 写入：0。
- reading_minutes、related_test_slug、FAQ answer surface 的 revision-bound adapter 尚未支持；全部 import_ready=false。
- JSON/Markdown、独立 SHA 复算、reading-minutes、classifier/workflow 44/44、git diff 与 changed-file scope：PASS；分类为 docs_rules_tests_only，deploy=false。
