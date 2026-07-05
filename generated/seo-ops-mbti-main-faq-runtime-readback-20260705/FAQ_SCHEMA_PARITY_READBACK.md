# FAQ Schema Parity Readback

Result:

- FAQPage JSON-LD detected: yes
- FAQPage `mainEntity` count: 4
- Visible FAQ count: 4
- Visible questions equal JSON-LD questions: yes

JSON-LD questions:

1. 费马的 MBTI免费测试会收费吗？
2. 这份 16型人格完整结果/报告包含哪些内容？
3. MBTI免费测试结果可以作为职业或心理诊断吗？
4. 完成测试后还能重新查看或重复测试吗？

Decision:

`MBTI-MAIN-FAQ-SCHEMA-PARITY-REPAIR-01` is skipped for now. The conditional repair only applies if visible FAQ = 8 but JSON-LD is not 8 or questions differ. Current production evidence is visible FAQ = 4 and JSON-LD = 4.
