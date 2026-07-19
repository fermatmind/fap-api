# MBTI-COMP-RUNTIME-46 Production Acceptance

## Result

MBTI-COMP-RUNTIME-46 is complete. Production runs the isolated backend revision
`bc0ed833bc9aae1473ab37f1dead2517e1aff618`, and all 16 Chinese A/T comparison
API and visible-page targets passed the exact nine-section acceptance contract.

The machine-readable evidence is
`backend/docs/seo/generated/mbti-comp-runtime-46-production-acceptance.v1.json`.

## Code-only deployment

- Workflow run: `29674638168`.
- Deploy mode: `code_only`.
- Previous active SHA: `781d1636b2c74f5852076a5864b681910a1e0e47`.
- Exact deployed SHA: `bc0ed833bc9aae1473ab37f1dead2517e1aff618`.
- Active release: `mbti-comp-runtime-46-20260719-r3-29674638168-1`.
- Deployed revision, health, contract smoke, and Ops entry smoke passed.
- The deployment performed no CMS or database content write.

## Single-record INTP revision

The separately authorized mutation was limited to `intp-a-vs-intp-t` and exact
package `mbti-comp-runtime-46-intp-revision-2026-07-19-r1`:

- payload SHA: `10b306f2dbac4f9a801a7718ec5584d84f56f6de601ada0f8f677bcb163f960e`
- promotion package SHA: `5b8afeec191d348dbb888c6cb4a63ea1e167e1a004bf35e41c1e64399f0c8369`
- promotion authorization SHA: `c9b3c3fa7f68a73e946f6bbc0a3f02ea6a95f3cbf5e9d3141778dd7d6408e03d`
- promoted revision: `212`
- expected canonical nine-section SHA: `6f7148e9787127ce128e19f0a37832be78119c7f1d9dcdf3a5f4d83aa8295ab9`

Dry-run workflow `29674866867` passed without writes. The first authorized write
workflow `29675163335` promoted the exact payload, but its readback control used
a newline-bearing `jq` hash. The public payload matched the package, and the
workflow executed and verified the exact automatic rollback before stopping.

PR #3205 corrected only the canonical JSON hash calculation. Workflow
`29675471856` then reused the same staged revision, verified the same exact
active SHA, release, package, and approval, promoted one record, and passed the
nine-section readback without invoking rollback.

Publication and indexability fields did not change. Sitemap, `llms.txt`,
`llms-full.txt`, Search Channel, GSC, and indexing-request actions remained
untouched.

## Public acceptance

Readback used only public HTTPS endpoints from `2026-07-19T06:43:35.174Z` to
`2026-07-19T06:44:00.120Z`. No server-internal inspection was used.

Every target passed:

- API HTTP 200 and visible page HTTP 200.
- CMS/backend public API authority; no frontend or local-package runtime fallback.
- Exactly 9 sections in this order: `biggest_difference`,
  `quick_judgment_table`, `easy_misread`, `work_scenarios`,
  `relationship_scenarios`, `stress_scenarios`, `do_not_misjudge`,
  `common_ground`, `usage_boundary`.
- Non-empty section title/body contract.
- Exactly 5 FAQs.
- Exact Chinese canonical.
- `robots=index,follow`.
- JSON-LD present.
- Visible HTML contains the authoritative section titles.
- Per-target canonical section and projection fingerprints recorded in the JSON
  artifact.

Aggregate result: API `16/16`; visible pages `16/16`.

## Rollback readiness

The exact rollback target remains revision `212`'s stored
`previous_public_section` for `intp-a-vs-intp-t` only. The first failed control
readback exercised and verified this rollback path. The successful retry did not
need rollback.

## Repository rule impact

This report changes no runtime behavior or content authority. CMS/backend remains
the authority for comparison content, publication, indexability, canonical, FAQ,
sitemap, and llms enumeration. The next train item is `MBTI-COMP-GATE-47` in
fap-web.
