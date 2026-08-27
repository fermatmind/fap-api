# Article15 Baseline-Reconciled v2 内容包 QA

- 状态：content_package_only；requires_operator_review=true；import_ready=false；publish_allowed=false。
- 生产基线 active SHA：`c0a0adf016f1b9c4388bd09ebe5bfe1d773e0989`。
- target=15；CHANGE=9；KEEP=6；v1_package_mutations=0。
- revision_body_matches=15；public_body_matches=15；unresolved_body_authority=0。
- unplanned_live_block_deletions=0；ID 16 仅新增一个行动段落；6 个 KEEP 页正文写入计划为零。
- 相同 production lock 连续重建结果一致（deterministic_regeneration=PASS）。
- 7/14 天运营字段：Unknown。
- QA SHA-256：`ca660ed4a0617e37982f4681184a8f45878e73a610d143cd52d7def5aa77c431`。
- Manifest SHA-256：`9e70df20d75c92a3aa3f4b5e292a2ed2104b8553bea830ce47381f05dcfdd07f`。

- 生产快照中的 Markdown 双空格换行按原字节保留；`.gitattributes` 仅豁免对应两个快照文件，SHA-256：`8a32e20de1baf7e1b232e545a2e160110d43c1a71030cc20010e3d6c106bba3d`。

## Package SHA-256

01. `gaokao-major-adjustment-unacceptable-major-checklist` — `0ac68d0ba5057f4cd87ab892d9e4a565018bdc9fc93ddc615332d3b2943d6fe2` (CHANGE)
02. `big-five-tool-guide` — `649451eac2e487ce729455a98f4af7325298b4bf2379582d5452146e1753e0fc` (CHANGE)
03. `mbti-basics` — `8336d9fd83a38ba15e3f6d830a5dfe14e6eacc53ac70ed32f8e685c036d8c7e5` (CHANGE)
04. `enneagram-personality-test-explained` — `8b546af20420df80e37059f0ce7543d508767ea4514e99e97e37f23a93af99f0` (CHANGE)
05. `riasec-holland-career-interest-test-explained` — `4d1b8200ff423f3947e6b5a8c11eb615e350056285ba595f47f321c8de0b470b` (CHANGE)
06. `are-infj-men-rare-or-socially-silenced` — `d60a6b4926cc09f43f83ed022a55c3a0652c9d3dfef91525cead3155aff8be77` (KEEP)
07. `iq-test-score-and-limits-explained` — `673720212f65e13dcddfde89ba706ccbf0d4e51bd290d2c6b315bd7547db3f79` (CHANGE)
08. `which-love-script-fits-you-best` — `3e9e22a11413aa8851764d2cdb6bb9be92983e93921ed9f9098f673511003c7e` (CHANGE)
09. `unwanted-major-repeat-or-stay-riasec-decision-checklist` — `a942a82ab928f74735077079b318bffb9ed392437e257738b3d9838e300dec47` (CHANGE)
10. `mbti-narrative-portrait` — `2d5464f36cb1ab2665eef4f0d153d80a46874712f5115fb15624657ff141b024` (KEEP)
11. `holland-career-interest-test-can-and-cannot-tell-you` — `1ab66b33c22750e32532c94baed8b07254832e21e4f24ca8668c25c282a5269a` (CHANGE)
12. `eq-test-tool-guide` — `72c7af54a12e4ac6031cfb515f96a065fee50c586b258b0739f3556f8a8a8f56` (KEEP)
13. `big-five-emotional-stability-stress-recovery-communication` — `8c945bca8302182988aed4ec824ff21872ad1eabfeb67a4dfb2776a840b7c986` (KEEP)
14. `mbti-vs-holland-code-career-choice` — `77154855168683f598ba3caf7de3777ba4b907f80f2336e70b094b96fae5cffc` (KEEP)
15. `mbti-full-report-career-relationship-communication` — `e81a25e87a8782a945128500677c8f2128b6ea6e81f81e995cdcc60f7cd14846` (KEEP)

## 边界

本集合不修改 adapter、公共 HTTP API、CMS schema 或 Article15 v1 文件，不授权 CMS/数据库/publication/cache/sitemap/llms/Search Channel 写入。后续 v2 adapter 双正文锁为独立任务。
