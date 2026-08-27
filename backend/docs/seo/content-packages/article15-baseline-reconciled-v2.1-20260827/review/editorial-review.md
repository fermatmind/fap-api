# Article15 v2.1 Editorial Review

## 结论

- 最终结果：`approved=15`、`revise=0`、`hold=0`。
- 原 v2 首轮结果：`approved=10`、`revise=5`、`hold=0`；未向原 v2 写入批准状态。
- v2.1 复用 10 个原 package，仅替换 ID 50、16、74、31、4；原 v2 永久保留。
- 最终 manifest declared SHA：`726d6be0fd2ff77e7504b366daaabdc49d8fb753801735d16826c79b8907ff61`；原始文件 SHA：`d9ff0465d84721492ba687b3fa4f25d5611fb118ff244b644e36a0b793d639b0`。
- 精确 package 集合 SHA：`8cb9b3ab3016b567f400df8c711c2de59c66be4a843345467c6001a6ec0790d5`。
- review artifact SHA：`970cf5e9e43793c3f895f642f7c21597a5d0488769fd9f9a5e9e31383bbbb9d2`。

## v2 首轮修订项

- ID 50：`faq_visible_body` 的 current/proposed 数组相同，但字段状态错误标为 `CHANGE`。v2.1 将该字段状态改为 `KEEP`；current/proposed Markdown 字节未变。
- ID 16：原 `effective_primary` 指向另一篇文章，不满足“主 CTA 必须为公开 canonical 测试入口”，且 `faq_visible_body` 在数组未变化时误标为 `CHANGE`。v2.1 改为 canonical EQ 测试入口、将 related test 对齐为 EQ，并把 FAQ 字段改为 `KEEP`；current/proposed Markdown 字节未变，仍仅新增计划中的一个段落。
- ID 74、31：`faq_visible_body` 的 current/proposed 数组相同，但字段状态错误标为 `CHANGE`。v2.1 均改为 `KEEP`；current/proposed Markdown 字节未变。
- ID 4：EQ 指南原 `effective_primary` 指向 MBTI 测试，与正文和 related test 意图不一致。v2.1 改为 canonical EQ 测试入口；正文 SHA 未变，`body_write_plan.write_count=0`，无 proposed body。

## 全量复审

- 15 个唯一 article ID / locale / slug identity 均与锁定目标集一致；KEEP 分组为 `11、10、4、79、39、61`，CHANGE 分组为 `58、3、8、51、40、50、16、74、31`。
- 6 个 KEEP 页正文零写入、无 proposed body；ID 16 只新增一个段落；8 个保守 CHANGE 页 `unplanned_live_block_deletions=0`。
- 15 页字段状态、FAQ/answer surface、单一主 CTA、reading minutes、related test、SEO 长度与搜索意图、query-owner/支持页边界、claim/disclaimer 与私有 URL 禁止项均通过。
- 34 个正文/包内链线上返回 200；15 个文章页均为 200、self-canonical 且 locale 正确；九型只使用 `/zh/personality/enneagram` 与 `/zh/tests/enneagram-personality-test-nine-types`。
- 中文逐页检查自然性与具体性；英文逐页检查无直译痕迹。MBTI、Big Five、RIASEC、IQ、EQ 均保持证据、诊断、招聘、录取、职业与关系结果边界。

## 写入边界

本次只新增 v2.1 内容包、review 与 approval 文件。CMS、数据库、publication、cache、sitemap、llms、Search Channel 写入均为 0；`import_ready=false`、`publish_allowed=false`。未修改公共 API、CMS schema、adapter、v1 execution manifest 或 query ownership registry。
