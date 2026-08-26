# Article15 批次 A 内容包 QA

- 批次：ARTICLE15-BATCH-A-20260826
- 状态：content_package_only
- 目标：5/5，顺序固定为高考调剂、Big Five、MBTI、Enneagram、RIASEC
- 基线 commit：6f172f279a02ef1b6ac57f59ed88cf99e6235793
- Top100 source SHA：e4c9788d6fadff53bc33170299971ab57dbff1810ad1fcdb4f97f5f4ee94d150
- Article15 target-set SHA：9ccd8cab8b44f10409e0ef3ced944ba663376c84a5032bd74ddba143d88cd35a
- batch manifest SHA：48a79cdcfc5b10766b58658aadb601e0682257af56d4fd108ed920a2ed6fa20a

## 包身份

| # | slug | article / revision | package SHA | FAQ | reading_minutes 建议 |
|---:|---|---|---|---:|---:|
| 1 | gaokao-major-adjustment-unacceptable-major-checklist | 58 / 78 | ea75bf63659b9738153a4d0895242d03b9ae937b2f91eb065a256ebe6900650d | 4 | 13 |
| 2 | big-five-tool-guide | 3 / 383 | ccc0b4c617171307eaacf0b659886b57907c2a0d9664e42aab8b0cb72e8c04d4 | 5 | 9 |
| 3 | mbti-basics | 8 / 72 | 20b570e5269deca9644f8e2f7f1a37669c192fe77bcdf65e54960ab90d381138 | 5 | 10 |
| 4 | enneagram-personality-test-explained | 51 / 73 | cf15980428ae6f9338f7aa0de9f4a4723ff45ce2085a6ba6df7cb180290dbc5e | 6 | 15 |
| 5 | riasec-holland-career-interest-test-explained | 40 / 69 | e5a4f0482cdde5c23918262fcc05054fb6fe1bde7ffbdc973e6860ada2ea8c8d | 6 | 10 |

## QA 结论

- identity、baseline、package SHA、字段状态：PASS。
- slug、canonical、publication、schema、hreflang、sitemap、llms、Search Channel：HOLD / 无变化。
- 正文可见 FAQ 与 answer_surface_v1 草稿：PASS。
- 每篇唯一主 CTA：PASS。
- 包内正式链接集合：11/11 返回 200 且 self-canonical。
- 高考内容未根据隐藏查询扩写地区、院校或录取政策：PASS。
- 历史别名字面值计数：0。
- private URL、禁止主张、claim boundary：PASS。
- importer / CMS / DB / query-owner registry / INFJ registry / public page 写入：0。
- reading_minutes、related_test_slug、FAQ answer surface 的 revision-bound adapter：后续任务必需；本批次 import_ready=false。
- git diff --check 与最终 changed-file scope：PASS。
