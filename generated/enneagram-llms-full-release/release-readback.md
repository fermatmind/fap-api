# ENNEAGRAM-LLMS-FULL-RELEASE-01

- Checked at: `2026-07-12T15:49:51.204248Z`
- Conclusion: `COMPLETED_PRODUCTION_READBACK`
- fap-web cache ops run: `29198788071`
- fap-web workflow head SHA: `54eae3332bfbd4374ea0fc3da1ff4eb6751ed786`
- remote frontend SHA: `11826dc0034ad111a0456f397a104a332c9b8b08`
- PM2 after reload: `4/4` online

## Feed readback

- `/llms.txt` unique Enneagram canonical URLs: `116/116`
- `/llms-full.txt` unique Enneagram canonical URLs: `116/116`
- `/llms-full.txt` mode: `complete`
- Locale distribution: `{'en': 58, 'zh': 58}`
- Entity distribution: `{'hub': 2, 'center': 6, 'core_type': 18, 'wing': 36, 'instinctual_subtype': 54}`

## Safety counters

- `/llms.txt` blocking duplicate canonical: `0`
- `/llms-full.txt` blocking duplicate canonical: `0`
- `/llms-full.txt` raw duplicate advisory URLs: `116`
- malformed URLs: `llms.txt=0`, `llms-full.txt=0`
- non-apex hosts: `llms.txt=0`, `llms-full.txt=0`
- forbidden/private/internal hits: `llms.txt=0`, `llms-full.txt=0`
- unknown entity routes: `llms.txt=0`, `llms-full.txt=0`

## Side effects

- deploy: `0`
- CMS writes: `0`
- eligibility writes: `0`
- Search Queue writes: `0`
- IndexNow submissions: `0`
- sitemap/index mutation: `0`
- secrets/permissions mutation: `0`

## Notes

- The fap-web cache-only ops artifact is incorporated by SHA256: `83bcf833bd77b19566de62b3cec05f4726485bc6fb771e196e26a7371f8eaf88`.
- `/llms-full.txt` is an enriched feed; raw repeated canonical mentions are recorded as advisory evidence, not a release blocker. The blocking canonical duplicate counter is `0`.
- This PR does not deploy, mutate CMS content, modify llms eligibility, submit Search, call IndexNow, or warm backend cache.
