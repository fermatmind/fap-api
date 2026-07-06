# llms-full Presence Readback

Collected on 2026-07-06 with unauthenticated public reads only.

## Surface Result

| Surface | Method | HTTP | Downloaded bytes | Result |
| --- | --- | ---: | ---: | --- |
| `https://fermatmind.com/llms-full.txt` | urllib | n/a | 596615 read before exception | `IncompleteRead`; not used as final proof |
| `https://fermatmind.com/llms-full.txt` | bounded `curl` | 200 | 599710 | success; used as final proof |

## Route Presence

| Route | `llms-full.txt` presence |
| --- | --- |
| `/zh/tests/mbti-personality-test-16-personality-types` | present |
| `/en/tests/mbti-personality-test-16-personality-types` | present |
| `/zh/tests/big-five-personality-test-ocean-model` | present |
| `/en/tests/big-five-personality-test-ocean-model` | present |
| `/zh/tests/enneagram-personality-test-nine-types` | present |
| `/en/tests/enneagram-personality-test-nine-types` | present |
| `/zh/tests/holland-career-interest-test-riasec` | present |
| `/en/tests/holland-career-interest-test-riasec` | present |
| `/zh/tests/iq-test-intelligence-quotient-assessment` | present |
| `/en/tests/iq-test-intelligence-quotient-assessment` | present |
| `/zh/tests/eq-test-emotional-intelligence-assessment` | present |
| `/en/tests/eq-test-emotional-intelligence-assessment` | present |

## Boundary

This card verifies presence only. It does not change or validate the full semantic quality of each entry, does not mutate `llms-full.txt`, and does not authorize any sitemap/llms/schema generation changes.
