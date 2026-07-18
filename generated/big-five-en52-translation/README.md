# FermatMind Big Five EN52 translation package

This repository package freezes the translation authority for exactly 52 en-US Big Five canonical pages. It is a local editorial/evidence package, not CMS or runtime authority.

## Task 1 commands

```bash
node generated/big-five-en52-translation/build-authority.mjs \
  --source-root=/Users/rainie/Desktop/FermatMind-Big-Five-ZH-V3-content-package \
  --reviewed-date=2026-07-19

node generated/big-five-en52-translation/validate-authority.mjs
```

The builder uses the operator-reviewed Markdown tree and the checked-in deterministic zh-CN release package. The review date is an explicit deterministic Asia/Shanghai input rather than a build-time clock or hidden constant. The validator is repository-contained and does not require the external Markdown tree.

## Completed-page contract

Later train items add only their assigned `.en-US.md` pages, claim sidecars, QA report, and dynamic ledger updates. The canonical manifest stays immutable. Each completed claim sidecar must include the page-level translation evidence fields frozen by the validator, a one-to-one `section_map`, a one-to-one `faq_map`, and every locked release claim with unchanged claim ID, claim type, and source-ID mapping. Section and FAQ maps use the same `translation_equivalence_status` vocabulary as claims.

The validator requires English H2 and FAQ counts to match the locked zh-CN page, exact registry citation URLs inside visible sourced claims, zero `needs_review` rows, a reason for every `scientifically_narrowed` claim, current page/claim hashes in the ledger, and zero body or frontmatter media surfaces.

## Boundaries

- `en-US` is the editorial locale; the existing backend locale contract remains `en`.
- Canonical paths remain under `/en/personality/big-five`.
- Big Five personality content is permanently text-only.
- Redirect-only legacy aliases are excluded.
- This package does not write CMS/database state, publish, promote, deploy, change runtime SEO, submit search URLs, or modify fap-web.
