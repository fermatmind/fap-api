# Runtime Guardrail Readback

Captured at: `2026-07-05T11:04:12Z`

## API Lookup

Endpoint:

`https://api.fermatmind.com/api/v0.3/scales/lookup?slug=mbti-personality-test-16-personality-types&locale=zh`

Observed:

- HTTP status: `200`
- Cache header: `x-fastcgi-cache: BYPASS`
- `content_i18n_json.zh.faq` count: `8`

FAQ questions:

1. MBTI 测试免费吗？
2. MBTI 完整结果能看到什么？
3. MBTI 测试一般多久？
4. MBTI 能决定职业吗？
5. MBTI 是心理诊断吗？
6. 16 型人格结果会变吗？
7. MBTI 和大五人格有什么区别？
8. 做完 MBTI 后下一步看什么？

## Canonical Public Page

URL:

`https://www.fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

Observed:

- Final URL: `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- HTTP status: `200`
- `x-proxy-cache: HIT`
- `cache-control: public, s-maxage=60, stale-while-revalidate=300`
- Expected visible FAQ question hits: `8`
- FAQPage JSON-LD mainEntity count: `8`
- FAQPage JSON-LD questions match expected API questions: `true`

This confirms D0 measurement was taken after the public canonical page had stabilized on the 8-entry FAQ state.
