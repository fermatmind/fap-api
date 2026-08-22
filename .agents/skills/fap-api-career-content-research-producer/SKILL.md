---
name: fap-api-career-content-research-producer
description: Research new or explicitly authorized fap-api Career content from public sources and produce source-bound, ten-module candidate packages outside the repository; not for Current compilation, publication, deployment, or search submission.
---

# Career Content Research Producer

Produce a research candidate, never Career authority. Use this Skill only for a named new Career cohort, an explicitly authorized content update, or a research-only package.

## Required input

Fail closed unless the caller supplies every field:

```yaml
batch_id: string
slugs:
  - canonical-slug
locales:
  - zh-CN
jurisdiction:
  primary: CN
  comparison:
    - US
research_as_of: YYYY-MM-DD
source_policy_version: string
output_root: existing-temporary-directory
authorized_content_scope: new_content | explicit_update | research_only
```

Require a non-empty explicit slug/cohort, locale list, primary jurisdiction, research date, policy version, existing output directory, and authorization scope. Resolve `output_root` before research: it must be an existing system-temporary directory outside the repository and must not be Current, the approved 1046 zh-CN master, `career-en-translation`, or any runtime/content-authority path.

## Workflow

1. Lock the request fields and canonical slug set. Do not expand the cohort implicitly.
2. Read [references/source-policy.md](references/source-policy.md), then collect only allowed public evidence. Browse for current facts; record unavailable sources as blockers instead of filling them from model knowledge.
3. Read [references/module-generation-contract.md](references/module-generation-contract.md), separate facts, proxies, market signals, internal rubrics, editorial synthesis, and conditional guidance, and generate exactly ten modules per slug and locale.
4. Read [references/evidence-contract.md](references/evidence-contract.md). Bind every factual or time-sensitive claim to stable source keys, and record all unresolved claims explicitly.
5. Run the validator against `<output_root>/<batch_id>` and inspect every error. The validator is read-only and must not repair input:

```bash
python3 scripts/validate_research_package.py <output_root>/<batch_id>
```

6. Hand a valid candidate to `fermatmind-career-editorial-qa`. Do not call a compiler, publisher, deployer, CMS, database, cache, or search system from this Skill.

## Responsibility chain

```text
external source research
    -> fap-api-career-content-research-producer
    -> source-bound candidate
    -> fermatmind-career-editorial-qa
    -> fap-api-career-canonical-builder
    -> fap-api-career-content-orchestrator
    -> fap-api-career-release-authority
    -> fap-api-deploy-sre
```

- This producer researches and structures candidates; it cannot invoke a publisher.
- The canonical builder compiles approved assets and does not perform web research.
- Editorial QA supplies review evidence and never becomes content authority.
- The orchestrator routes a bounded batch and never rewrites blockers automatically.
- Release authority accepts only an approved Current package.
- Deploy SRE follows an exact SHA and never becomes content-publication authority.

## Hard boundaries

- Do not modify the approved 1046 zh-CN master, Current `assets.jsonl` or `manifest.json`, English translation assets, frontend, runtime/API schema, CMS/database/cache state, sitemap/discoverability/search state, automations, workflows, or Agent definitions.
- Do not compile or install Current, render pages, publish, deploy, or submit GSC, IndexNow, sitemap, llms, or other search actions.
- Do not treat a candidate, validator PASS, QA result, HTTP 200, or database record as published authority.
- Keep private assessment data, personal data, credentials, cookies, and browser sessions out of research packages.

## Output

Report the locked request, package path, ten-module coverage, source/claim/unresolved counts, expired-source count, deterministic hashes, validator result, and explicit non-mutations. A PASS means only that the temporary research candidate satisfies this contract.
