# Article15 Baseline-Reconciled v2 内容包 QA

- 状态：content_package_only；requires_operator_review=true；import_ready=false；publish_allowed=false。
- 生产基线 active SHA：`64c386fa0e0fa5167c08c207befe33be75bb447d`。
- target=15；CHANGE=9；KEEP=6；v1_package_mutations=0。
- revision_body_matches=15；public_body_matches=15；unresolved_body_authority=0。
- unplanned_live_block_deletions=0；ID 16 仅新增一个行动段落；6 个 KEEP 页正文写入计划为零。
- 7/14 天运营字段：Unknown。
- QA SHA-256：`3daf4895d45ae2542cffa0e25635170778ae4ba7f0a0394103c3fc8e5f3f62a7`。
- Manifest SHA-256：`2f09c8fcfc9241459eb1aa25589609f840932a37c2cdc0acf9babbe489536bcd`。

- 生产快照中的 Markdown 双空格换行按原字节保留；`.gitattributes` 仅豁免对应两个快照文件，SHA-256：`8a32e20de1baf7e1b232e545a2e160110d43c1a71030cc20010e3d6c106bba3d`。

## Package SHA-256

01. `gaokao-major-adjustment-unacceptable-major-checklist` — `d876c3eee3cc85efc276ab6dd4d120d1a89c454ed9d6f99bdc8f96ae6046e74b` (CHANGE)
02. `big-five-tool-guide` — `4ddc140f416c060c7654fecfaf04f73bda6e24f6724d573621d076d7c3a8343f` (CHANGE)
03. `mbti-basics` — `33bbfdf7810030d6ecb9af529b92195e87bcf112487f82fe6f997636fa7b0fdf` (CHANGE)
04. `enneagram-personality-test-explained` — `4d7634187f54790edd21b57d155c5a0abf9e3d97b49b850ebb261a7f51269deb` (CHANGE)
05. `riasec-holland-career-interest-test-explained` — `fcdb61c7a19403f269655bc7776f92cb01fe8c6ac2800424b222b0fb8fa4b42b` (CHANGE)
06. `are-infj-men-rare-or-socially-silenced` — `315c96afc70dff6697c994045229d22aa1683b7489fa86546641a7c50fc1ee02` (KEEP)
07. `iq-test-score-and-limits-explained` — `612429382af1e4a6945ee60c6bfa605ec60ef45a38e13dbd625231be01dae529` (CHANGE)
08. `which-love-script-fits-you-best` — `de7a74412a15c3e760ebe18b254bdb1324e882ff57f32e0ac9f5fd547ecf7d44` (CHANGE)
09. `unwanted-major-repeat-or-stay-riasec-decision-checklist` — `7a3df1cab6e08316d18e406d522917f706d5ac71d3e3ff4d12a4975519b512d7` (CHANGE)
10. `mbti-narrative-portrait` — `cafac82948b9a4a7dcc20ad4d7c423c5ab3592b7c5cf4f4514f2958812c3a4ad` (KEEP)
11. `holland-career-interest-test-can-and-cannot-tell-you` — `3bf4dd6668500e2cdc7f01c7c4fce0c3c4dbcd84050700d841abe245590b31b9` (CHANGE)
12. `eq-test-tool-guide` — `d13cdb3baab11526d8cc8c6a0ffd3a74b86ea43bfe7e897b1f474d00c2ffcb35` (KEEP)
13. `big-five-emotional-stability-stress-recovery-communication` — `f5905df8b8038654a8e28b3535d6d0521585cf236e3ccb9c25a80dcbaa8ad847` (KEEP)
14. `mbti-vs-holland-code-career-choice` — `b6b280d89c4883a8a97ca9234961b91f3458c5ea66b0799516d522bfc9817e55` (KEEP)
15. `mbti-full-report-career-relationship-communication` — `767f2d7c222880ceb65c7a0e466b5392e104c044705cd11a680a1e2d590b701a` (KEEP)

## 边界

本集合不修改 adapter、公共 HTTP API、CMS schema 或 Article15 v1 文件，不授权 CMS/数据库/publication/cache/sitemap/llms/Search Channel 写入。后续 v2 adapter 双正文锁为独立任务。
