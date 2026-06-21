# RIASEC Result Page Asset Agent Stage Gates

Status: `RIASEC-RESULT-ASSET-AGENT-RUNBOOK-01`

These gates define how Holland/RIASEC result page asset-agent work moves from documentation to preview handoff. They do not open runtime, CMS, pilot, or production access.

## Gate 1: Runbook

Entry: explicit PR-train authorization.

Pass criteria:

- docs define agent responsibilities, inputs, outputs, forbidden actions, and stop conditions;
- no runtime, CMS, asset-generation, or production files are changed;
- `git diff --check` passes.

Exit state: runbook documented, generation still forbidden.

## Gate 2: Source Ledger

Entry: runbook merged.

Pass criteria:

- ledger template exists;
- first evidence ledger covers Holland/RIASEC public sources, method-boundary references, internal formal drafts, internal long-form drafts, and existing asset packs;
- every claim row has source, reference, permitted use, limitation, and disallowed use;
- JSON validates;
- no generated selector or content assets are created.

Exit state: sources traceable, generation still forbidden.

## Gate 3: Validator Harness

Entry: source ledger merged.

Pass criteria:

- agent run validator can inspect a run directory without mutating runtime state;
- harness reuses existing RIASEC selector/content validators where available;
- inventory, checksum, forbidden public field, share leak, and gate report checks exist;
- strict mode fails closed on invalid inventory, P0 blockers, or leaks;
- focused harness and selector/content validator tests pass.

Exit state: validation executable, generation still forbidden.

## Gate 4: Existing Asset Gap Audit

Entry: validator harness merged.

Pass criteria:

- current RIASEC result page assets are inventoried;
- current deep slots are counted and grouped;
- current route/selector or content reference matrix is counted;
- current golden cases and canonical profiles are counted;
- gaps are grouped by registry, scope, scenario, share-safe coverage, low-quality coverage, fallback coverage, and route/golden-case readiness;
- output is a gap register, not a repair or generated asset batch.

Exit state: gap register ready for targeted repair.

## Gate 5: Selector QA Repair

Entry: existing asset gap audit merged.

Pass criteria:

- known selector/content QA policy issues are repaired: coverage warnings, golden group normalization, slot/module naming, banned terms, and fail-closed fallback rules;
- assets remain `staging_only`;
- no runtime wrapper, CMS import, pilot flag, or production flag changes;
- selector/content import, validator, and golden case tests pass.

Exit state: selector/content QA can act as a staging gate.

## Gate 6: Share-Safety Pilot Batch

Entry: selector QA repair merged.

Pass criteria:

- pilot batch scope is limited to `share_safety` unless the repository scan proves `low_quality cautious copy` is narrower and safer;
- raw draft, repair draft, final asset, validation report, safety report, and go/no-go report are preserved;
- every generated asset remains `staging_only` and `production_use_allowed=false`;
- shareable public payloads contain no raw scores, vectors, percentiles, deterministic type labels, or private fields;
- strict harness and selector/content validator pass.

Exit state: share-safe draft can be reviewed as staging-only content.

## Gate 7: Route Matrix And Golden Case QA

Entry: share-safety pilot batch merged.

Pass criteria:

- route rows validate at the current repository count;
- canonical profiles are covered at the current repository count;
- selector/content references resolve;
- conflict-resolution rules are deterministic;
- output is a staging selector input readiness report, not runtime wiring.

Exit state: route matrix can be considered staging selector input.

## Gate 8: Render Preview Handoff

Entry: route matrix and golden case QA merged.

Pass criteria:

- backend fixtures and expected assertions cover result page, PDF, share, history, compare, locked/free redaction, low quality, and fallback behavior;
- fixtures validate through backend payload and public-surface policies;
- no frontend content, CMS write, runtime flag, or production gate is changed;
- fap-web handoff document names the fixture files, expected assertions, and explicit deferred items.

Exit state: fap-web can run rendered preview QA against backend-owned fixtures.

## Production Boundary

None of these gates authorize production. Production requires a separate release package, backend dry-run, staging import, rendered preview pass, API smoke, pilot allowlist, production import gate, and production rollout approval.
