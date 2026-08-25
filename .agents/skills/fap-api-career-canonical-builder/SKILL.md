---
name: fap-api-career-canonical-builder
description: Use for deterministic fap-api Career canonical-package candidate compilation from approved zh-CN or en source assets, including schema, slug, locale, current-component, link, hash, dry-compile, and package-diff validation; not for publication, deployment, frontend rendering, or search submission.
---

# Career Canonical Builder

Build a fail-closed Career Current candidate with the repository compiler. External source directories are inputs, never runtime or publication authority.

## Use this skill for

- Validating an approved Career source cohort and its lookup/evidence bindings.
- Deterministic dry compilation and package-diff review.
- Preparing a repository Current package candidate without silently changing source copy.

Do not use it to publish, deploy, write CMS/database/cache state, render frontend pages, or change sitemap, llms, GSC, IndexNow, canonical, or hreflang inventory.

## Authority and boundaries

- `backend/content_assets/career/current/manifest.json` and its bound 640 shards are the repository Current content authority.
- No committed flat projection exists. When a compiler needs whole-row evidence, it must synthesize a versionless projection from the manifest-bound shards into a system temporary directory.
- The compiler and package contracts under `backend/app/Domain/Career/{Compilation,Display}` define accepted input, output, locale, component, and hash behavior.
- Source assets, lookup files, evidence files, Desktop directories, dry-run output, and a PASS receipt are candidates or evidence only.
- Never change input copy during compilation. Report a blocker when normalization would require an editorial decision.
- Keep `career-en-translation` unchanged while its separate translation program is active.

Read [references/current-contract.md](references/current-contract.md) before compiling or changing the builder boundary.

For Career Content Agent execution, also read the orchestrator's [Gate 4 contract](../fap-api-career-content-orchestrator/references/gates-risk-lifecycle.md). Run only the existing real single-slug dry compile, return its bound digest and zero-write evidence, and never expose its internal eligibility signal as Agent publication authority.

## Workflow

1. Read repository rules and identify the exact source root, lookup JSON, evidence root, and baseline Current package.
2. Confirm the input cohort, canonical slugs, locales, source/evidence digests, and schema profile are explicit. Fail closed on missing or ambiguous bindings.
3. Create an existing task-specific temporary output directory outside the repository.
4. Run the repository dry compiler without `--write-current`:

```bash
cd backend
php artisan career:ten-block-current-package-compile \
  --source-root=<approved-source-root> \
  --lookup=<approved-lookup.json> \
  --evidence-root=<approved-evidence-root> \
  --output-root=<existing-task-temp-dir>
```

5. Inspect `full-compile-receipt.json`, `field-coverage-report.json`, and `package-diff-report.json`. Require zero blockers, silent drops, forbidden public keys, unresolved links, and unexpected locale/component drift.
6. Compare candidate bytes and hashes with Current. Do not install a candidate unless the task explicitly includes a Current package change and the diff is the approved scope.
7. Hand any approved Current package change to `fap-api-career-release-authority`; let normal trunk CI/deploy classification own later release behavior.

## Historical sharding recovery

`scripts/split_legacy_current.php` is preserved temporarily as inert historical transition evidence. It has no valid default input after the flat projection was removed and must not be used for Current compilation. Current compilation starts from the installed manifest-bound shards.

## Sharded candidate assembly

Use `scripts/assemble_sharded_current.php` to assemble a complete temporary legacy projection from splitter candidate shards. Both roots must already exist outside the repository under a system temporary root, and they must differ:

```bash
php .agents/skills/fap-api-career-canonical-builder/scripts/assemble_sharded_current.php \
  --candidate-root=<existing-candidate-temp-dir> \
  --output-root=<existing-assembly-temp-dir>
```

The assembler is historical equivalence tooling. Active Current compilation validates and assembles only the manifest-bound shards; any whole-row projection is temporary, versionless, and derived. Output and receipts remain candidate evidence with zero Current/runtime/publication writes.

## Acceptance

- The compiler reports 1046 careers, 2092 locale pages, and the current exact v4.3/28-component contract; v4.2/26 remains read-only compatible.
- Only `Occupation`, `BreadcrumbList`, and visible-content `FAQPage` structured-data families are allowed.
- Slug set, locale pairing, component order, source/evidence hashes, and package hashes validate.
- The dry run reports zero CMS, database, cache, discoverability, and search writes.
- Candidate output is confined to the task temp directory unless a separately scoped Current package edit is authorized.
