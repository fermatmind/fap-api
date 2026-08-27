# Article15 Baseline-Reconciled v2 内容包 QA

- 状态：content_package_only；requires_operator_review=true；import_ready=false；publish_allowed=false。
- 生产基线 active SHA：`095753fa1f0d6a2bd0a8d05e94250e4bd2c5fbb2`。
- target=15；CHANGE=9；KEEP=6；v1_package_mutations=0。
- revision_body_matches=15；public_body_matches=15；unresolved_body_authority=0。
- unplanned_live_block_deletions=0；ID 16 仅新增一个行动段落；6 个 KEEP 页正文写入计划为零。
- 7/14 天运营字段：Unknown。
- QA SHA-256：`ff6444c42cac67760ab409b8f1f93ca08a3bd14ec573e7069c6fe589663ece81`。
- Manifest SHA-256：`ced3fe9f2d71384d2a08cd47e1bab5b01ea7151fa70dfbd5523edf52ed0e3c54`。

- 生产快照中的 Markdown 双空格换行按原字节保留；`.gitattributes` 仅豁免对应两个快照文件，SHA-256：`8a32e20de1baf7e1b232e545a2e160110d43c1a71030cc20010e3d6c106bba3d`。

## Package SHA-256

01. `gaokao-major-adjustment-unacceptable-major-checklist` — `d1330c9ecb0835ab786fca15865e9977cafbfcd85020141c2f6116ad87a27bbe` (CHANGE)
02. `big-five-tool-guide` — `914fd0d2196b1e4e1e33672d5fc080fbc798b0bf36e1a84cb3a32776f782595a` (CHANGE)
03. `mbti-basics` — `4723e787a77f17df9ec76b7ae4ecb8b170fc8c809cbaa53a3adb6ac954cf9b4d` (CHANGE)
04. `enneagram-personality-test-explained` — `6fbca60d1781787411d0a207f0cb829844f0d854b0b8d2302dd6df022d0ca495` (CHANGE)
05. `riasec-holland-career-interest-test-explained` — `bdcdc9f2ac577548d8003d1de47e1129f5630b90dc9115387e5b738d960321bc` (CHANGE)
06. `are-infj-men-rare-or-socially-silenced` — `0b43686f354b86c3eed76786785327dac903bc21c622e3692af1818de0bdc048` (KEEP)
07. `iq-test-score-and-limits-explained` — `07cdc4f0e6354efa690d6a097db824d993aa1b9364957d18a8e3506c4f4b32f5` (CHANGE)
08. `which-love-script-fits-you-best` — `1b72b221dc75bf1f7760ca67718738cd6130d8ebc7193c4b9c7109a594163929` (CHANGE)
09. `unwanted-major-repeat-or-stay-riasec-decision-checklist` — `a374ca7ee136bc1e49cda7263df4b3893d1674d925d4c8a77cd8ed2a6b97132c` (CHANGE)
10. `mbti-narrative-portrait` — `69436dd83a59ce3c97b53a311d913ce2ae4fbf254816d29871789faf8d4effad` (KEEP)
11. `holland-career-interest-test-can-and-cannot-tell-you` — `1faadaae0847b577cc79304aed9ba2f8314eb89e312a4b75b178ac2b2f006cf5` (CHANGE)
12. `eq-test-tool-guide` — `26937e3e103785e6c9f4fbd20c0b900a1ec153a0ebdc5c7fac4122bea6591c30` (KEEP)
13. `big-five-emotional-stability-stress-recovery-communication` — `813b16953dd22679a8a53c2b668eaf6030e93d7bd5c15cb7517363b446b0a1ac` (KEEP)
14. `mbti-vs-holland-code-career-choice` — `65a9ad8f88b251b5481257b4021330e822dbd0a32fcd45439f97e75f8e64bbcb` (KEEP)
15. `mbti-full-report-career-relationship-communication` — `14d1803c342a3a9a2929843ef2a7c34a4362d560a74fb8ba64acdb515bd082b2` (KEEP)

## 边界

本集合不修改 adapter、公共 HTTP API、CMS schema 或 Article15 v1 文件，不授权 CMS/数据库/publication/cache/sitemap/llms/Search Channel 写入。后续 v2 adapter 双正文锁为独立任务。
