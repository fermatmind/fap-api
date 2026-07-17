# Solo Owner Review Protocol

## Authority and boundary

FermatMind internal human review uses the backend-authoritative `solo_owner` governance mode. The configured owner/operator may be the author, submitter, reviewer, attester, requester, and approver for the same internal review surface. A second internal reviewer is not required.

This policy does not create external evidence and does not weaken automated tests, required checks, exact-SHA authorization, preflight, readback, permissions, audit logging, TOTP/step-up, or production execution controls. Human review completion is evidence for a review gate; it is never production publish, promotion, refund, rollback, payment, data-lifecycle, CMS-write, search-submission, or deploy authorization.

Configuration contains only the governance mode and numeric admin identity:

```dotenv
FM_REVIEW_GOVERNANCE_MODE=solo_owner
FM_REVIEW_SOLO_OWNER_ADMIN_USER_ID=1
```

Passwords, TOTP values, tokens, secrets, reviewer names, private notes, evidence documents, and private URLs must not be stored in these variables.

## Compact attestation v1

The private compact payload is:

```json
{
  "schema_version": "solo-owner-review-attestation.v1",
  "review_mode": "solo_owner",
  "review_source": "owner_operator_attestation",
  "scope_type": "package",
  "scope_identity": "stable-package-identity",
  "decision": "approved_all",
  "target_count": 2,
  "target_set_sha256": "<exact deterministic SHA-256>",
  "package_sha256": "<exact package SHA-256 or null>",
  "exceptions": [],
  "statement_version": "solo-owner-attestation.v1",
  "attested_by_admin_user_id": 1,
  "attested_at": "2026-07-17T12:00:00Z",
  "evidence_sha256": "<canonical evidence SHA-256>"
}
```

Callers must derive the trusted target set from the exact package, release report, batch, revision set, or resource set. Each target contains a stable `target_identity` and exact lowercase `target_sha256`. Targets are sorted by identity and canonical JSON encoded before hashing. The validator recomputes count and SHA; handwritten count or hash values never define the authoritative target set.

The canonicalizer recursively sorts object keys and preserves list order. `evidence_sha256` is computed over the complete payload excluding the `evidence_sha256` field itself. Missing, duplicate, unknown, extra, malformed, count-drifted, target-hash-drifted, package-drifted, actor-drifted, and canonical-evidence-drifted input fails closed.

`approved_with_exceptions` requires a non-empty proper subset of exact targets. Each exception has only `target_identity` and a private `reason`. `approved_all` and `rejected` do not accept exceptions.

## Preflight and bind

Read-only validation is available through:

```bash
cd backend
php artisan review:attestation-preflight \
  --attestation=/private/path/attestation.json \
  --targets=/trusted/path/targets.json \
  --expected-package-sha256=<sha256> \
  --json --no-ansi
```

Preflight performs zero database writes. It returns only redacted status, scope identity, decision, counts, hashes, and safety flags; it does not return the owner admin ID or private exception reasons.

Binding uses one database transaction. It writes one immutable `review_attestations` row and automatically expands the trusted target set into immutable `review_attestation_target_evidences` rows. The attestation evidence SHA is the idempotency identity. Exact existing evidence returns the existing record after readback; partial, extra, or drifted evidence aborts. Any target write failure rolls back the attestation and all target evidence.

No production bind or historical backfill is part of the foundation PR. Concrete CMS, personality, Career/SEO, and Ops adapters are migrated only in their declared later train items.

## Risk tiers

- R1 internal content/editorial review: solo-owner attestation is sufficient for the human-review gate.
- R2 publish, promotion, release, canary, and search submission: solo-owner attestation may satisfy human review, but execution requires a separate exact preflight and authorization transition.
- R3 refund, benefit, payment reprocess, rollback, and data lifecycle: requester and approver may be the configured owner, but current MFA/TOTP step-up, reason, exact target fingerprint, correlation ID, idempotency, audit, and separate approve/execute transitions remain mandatory.
- R4 external objective evidence: owner attestation may record that existing evidence was checked. It cannot manufacture expert review, clinical validation, legal advice, official partnership/certification, third-party endorsement, licensing, or platform approval. Missing evidence remains Unknown, blocked, or non-public.

## Registry and public projection

`ReviewPolicyRegistry` inventories every known human-review surface and declares its domain, risk tier, authority, current implementation, solo-owner policy, step-up boundary, execution separation, public projection, external-evidence requirement, migration PR, and adapter status. Architecture tests enforce schema, uniqueness, required coverage, R3 step-up, R4 evidence classification, and registration of models with review/approval fields. The generated inventory is `docs/operations/generated/solo-owner-review-surface-registry.v1.json`.

The eventual public contract is limited to `review_state`, `last_reviewed_at`, and `reviewer: null`. Private owner identity, admin ID, evidence, exceptions, notes, attestation content, tokens, secrets, and private URLs must never be projected publicly. Public normalization belongs to `SOLO-OWNER-PUBLIC-REVIEW-CONTRACT-06`; this foundation does not change public APIs.

## Repository rule impact

This protocol changes the internal reviewer-separation contract: one configured owner/operator may complete internal human review. Backend/CMS remains the authority. External evidence, production authorization, publication/indexability, automated verification, high-risk step-up, privacy, and deployment controls are unchanged.
