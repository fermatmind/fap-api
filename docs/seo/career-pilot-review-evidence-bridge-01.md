# Career pilot review evidence bridge

## Purpose

`CAREER-PILOT-REVIEW-EVIDENCE-BRIDGE-01` binds a bounded Career search-entry pilot to the exact current bilingual public detail read model. It does not create content, publish a Career page, change indexability, alter sitemap/llms, enqueue Search Channel work, or submit URLs.

The deterministic package contains six targets per slug. Each locale's content target also binds the exact current public index entry so list approval cannot outlive title, summary, trust, or SEO drift:

- EN visible content, SEO, and visible claims;
- zh-CN visible content, SEO, and visible claims.

Targets are generated only when both locales resolve from an active or LKG detail payload and each bilingual index contains exactly one matching entry. The package builder uses cache-readiness inspection only: legacy, degraded, cold, missing, duplicate-index, or unpublished detail authority fails closed without cache promotion or warm-job dispatch.

## Read-only package generation

```bash
cd backend
php artisan career:build-pilot-review-package \
  --slugs=slug-one,slug-two \
  --output=/private/operator/career-pilot-review-package.json \
  --json
```

The command performs no database write and binds no evidence. The output file is written with private permissions. Record the exact `scope_identity`, `target_count`, `target_set_sha256`, and `package_sha256` shown by the command.

## Existing solo-owner review flow

Create a private compact attestation with:

- `scope_type=career_search_entry_pilot`;
- the exact generated `scope_identity`;
- `decision=approved_all` only after every visible target was reviewed;
- the exact package target set and package SHA;
- the configured `solo_owner` admin actor.

Then run the existing command in read-only preflight mode first:

```bash
php artisan review:career-seo-attestation \
  --surface=career_trust_manifest \
  --attestation=/private/operator/attestation.json \
  --targets=/private/operator/career-pilot-review-package.json \
  --expected-package-sha256=<exact-package-sha256> \
  --json
```

Binding is a separately controlled production database write and requires the operator's exact package SHA, target-set SHA, and admin actor confirmation:

```bash
php artisan review:career-seo-attestation \
  --surface=career_trust_manifest \
  --attestation=/private/operator/attestation.json \
  --targets=/private/operator/career-pilot-review-package.json \
  --expected-package-sha256=<exact-package-sha256> \
  --actor-admin-user-id=<confirmed-admin-id> \
  --bind \
  --json
```

This PR does not authorize or execute that bind.

## Public projection and invalidation

Only an immutable `approved_all` attestation whose scope identity, six-target-per-slug count, target-set SHA, package SHA, current configured solo owner, schema version, statement version, and every expanded approved target match the current bilingual detail and index read models projects:

```json
{
  "review_state": "approved",
  "last_reviewed_at": "<attested-at>"
}
```

Career list/detail review state defaults to `unknown` with no review timestamp. Rejected, exception, partial, duplicate, malformed, missing, cold, and stale evidence cannot inherit an older trust status. Any visible content, public trust evidence, exact index entry, score bundle, white-box score, integrity summary, SEO, structured-data, claim-permission, warning, source, or truth-layer drift changes the target/package SHA and invalidates the whole batch.

Reviewer identity, target SHA, target-set SHA, package SHA, evidence SHA, exceptions, and attestation records remain private. Existing compatibility fields may remain in the API, but the public reviewer value stays `null`.

## Repository rule impact

Career content authority remains backend/CMS-owned. This change adds only a backend review-evidence projection over already-public active/LKG payloads. It introduces no frontend fallback, new content surface, publication transition, indexability transition, sitemap/llms enumeration, or Search Channel action.
