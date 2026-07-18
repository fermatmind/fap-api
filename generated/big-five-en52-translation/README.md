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

## Boundaries

- `en-US` is the editorial locale; the existing backend locale contract remains `en`.
- Canonical paths remain under `/en/personality/big-five`.
- Big Five personality content is permanently text-only.
- Redirect-only legacy aliases are excluded.
- This package does not write CMS/database state, publish, promote, deploy, change runtime SEO, submit search URLs, or modify fap-web.
