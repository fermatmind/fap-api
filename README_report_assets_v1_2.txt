MBTI Report Asset Skeletons (v1.2)

Package: MBTI-CN-v0.2.1-TEST

Generated files:
- content_packages/MBTI-CN-v0.2.1-TEST/report_identity_cards.json
- content_packages/MBTI-CN-v0.2.1-TEST/report_highlights.json
- content_packages/MBTI-CN-v0.2.1-TEST/report_borderline_notes.json
- content_packages/MBTI-CN-v0.2.1-TEST/report_recommended_reads.json

Notes:
- All 4 files have top-level: {schema, engine, rules, items}
- items contains 32 typeCode keys.
- highlights + recommended_reads values are arrays (may be empty []).
- identity_cards + borderline_notes values are objects (placeholders).

Optional generator:
- tools/generate_report_assets_v1_2.js
  Run: node tools/generate_report_assets_v1_2.js
