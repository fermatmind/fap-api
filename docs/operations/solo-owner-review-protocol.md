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

Before canonical JSON encoding, the top-level `exceptions` list is sorted by `target_identity`; callers must use that normalized order when computing evidence. The canonicalizer then recursively sorts object keys and preserves the order of every list, including the already-normalized `exceptions` list. `evidence_sha256` is computed over this normalized complete payload excluding the `evidence_sha256` field itself. Missing, duplicate, unknown, extra, malformed, count-drifted, target-hash-drifted, package-drifted, actor-drifted, and canonical-evidence-drifted input fails closed.

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

No production bind or historical backfill is part of this PR train. Concrete adapters are migrated only in their declared train items; implementation and test fixtures may bind local transactional evidence, but production/CMS data is never mutated by the train itself.

## CMS editorial adapters (PR2)

`SOLO-OWNER-CMS-REVIEW-02` activates compact evidence for Article, ArticleTranslationRevision, CmsTranslationRevision, ContentPage, SupportArticle, InterpretationGuide, ResearchReport, and EditorialReview. One configured owner approval binds the exact resource/revision set and expands immutable per-target evidence. A content or revision payload edit changes the target fingerprint; workflow timestamps and later state outputs do not.

Approval and release remain separate transitions. In `solo_owner` mode, an approved draft may create or bind compact evidence, while a scheduled, published, promoted, or controlled-publish transition must find previously bound evidence for the exact current targets. The release transition cannot attach a new attestation to approve and release in one operation. Generic Article/Career release restrictions and controlled-publisher preflight remain fail closed.

`team_separated` mode retains distinct owner/reviewer enforcement and does not accept compact solo-owner attestations. ResearchReport and science ContentPage external-evidence gates remain R4: owner attestation may record review of existing evidence, but cannot replace missing references, legal/science readiness, or other objective evidence.

## Risk tiers

- R1 internal content/editorial review: solo-owner attestation is sufficient for the human-review gate.
- R2 publish, promotion, release, canary, and search submission: solo-owner attestation may satisfy human review, but execution requires a separate exact preflight and authorization transition.
- R3 refund, benefit, payment reprocess, rollback, and data lifecycle: requester and approver may be the configured owner, but current MFA/TOTP step-up, reason, exact target fingerprint, correlation ID, idempotency, audit, and separate approve/execute transitions remain mandatory.
- R4 external objective evidence: owner attestation may record that existing evidence was checked. It cannot manufacture expert review, clinical validation, legal advice, official partnership/certification, third-party endorsement, licensing, or platform approval. Missing evidence remains Unknown, blocked, or non-public.

## Personality adapters (PR3)

`SOLO-OWNER-PERSONALITY-REVIEW-03` activates the same exact-target compact evidence contract for PersonalityPublicContentAsset, its private revision-review evidence, Big Five editorial revisions and Authority V2 review preflight, MBTI approval batches, Enneagram Authority V2 review binding, and RIASEC content/release review. MBTI cross-type authority remains policy-registered with its adapter pending until an actual review gate consumes the central attestation contract.

Big Five editorial approval permits the configured owner to be author, submitter, and reviewer in `solo_owner` mode and binds immutable evidence in the same transaction as the review-state transition. `team_separated` keeps the historical distinct-reviewer rule. Rollback remains separately authorized and retains its existing separation and audit controls.

The MBTI approval queue binds its exact item IDs, recommendation hashes, source package SHA, and QA SHA before changing approval state. It does not write CMS content or trigger publication, promotion, indexability, sitemap, llms, or search actions.

The Enneagram binder accepts either the existing private 116-row register or one compact `approved_all` attestation over the exact release-report target set. Compact input expands into 116 central immutable target-evidence rows and retains the 116-row private revision-review register required by the existing promoter. Missing, duplicate, extra, rejected, package-drifted, or hash-drifted input aborts the transaction with zero evidence or workflow-state writes. Binding remains distinct from the 116-row atomic promotion, deployed-SHA gate, readback, rollback, and PR23 execution.

Big Five Authority V2 compact review is consumed only by its read-only promotion preflight; it cannot supply source/media permission, runtime binding, rollback targets, deployed SHA, or cohort authorization. RIASEC compact review binds only an exact release snapshot and explicitly authorizes no import, release, rollout, or production execution.

Big Five and Enneagram PersonalityPublicContentAsset surfaces remain permanently text-only. These adapters add no Media Library, hero, inline, OG, Twitter, Markdown-image, or HTML-image path.

## Career and SEO adapters (PR4)

`SOLO-OWNER-CAREER-SEO-REVIEW-04` activates one private exact-target adapter for Career trust manifests, occupation truth metrics, editorial patches, occupation-directory review, salary and AI-impact assets, import/publish readiness, SEO Agent drafts and content packages, canary approval, search-submission queue approval, and claim-risk review. Callers must derive each target identity and SHA-256 from the authoritative resource, artifact, revision, or bounded queue snapshot; handwritten counts and combined single-target attestations cannot satisfy an exact batch gate.

An owner attestation may record `approved_all`, `approved_with_exceptions`, or `rejected`. Exceptions expand into immutable per-target `excepted` evidence and cannot satisfy a later all-approved execution preflight. R4 occupation-truth and claim-risk surfaces still require their existing external objective evidence; owner review cannot manufacture or replace it.

Binding Career/SEO review evidence has no domain-action capability. It does not publish or import Career/CMS content, approve a production import, change canonical/hreflang/robots/noindex/JSON-LD/sitemap/llms/discoverability state, enqueue or submit a search URL, or expose private reviewer identity. Publish, import, index, canary, and search-submission execution remain separate exact-authorized transitions that must consume one previously bound attestation over the exact current target set.

## High-risk Ops approval adapter (PR5)

`SOLO-OWNER-OPS-APPROVAL-05` activates the R3 adapter for registered `AdminApproval` operations: refund, manual benefit grant, benefit revoke, payment-event reprocess, release rollback, and data-lifecycle approval. In `solo_owner` mode, the configured owner may be both requester and approver. In `team_separated` mode, requester and approver must remain different administrators.

Approval requires the current administrator's MFA/TOTP step-up session, a non-empty reason, a UUID correlation ID, and a supported registered operation type. It computes a deterministic fingerprint over the exact approval ID, operation surface, organization, requester, reason, correlation ID, and business payload. The approval record stores only versioned private governance metadata, the target fingerprint, reason hash, evidence SHA-256, actor IDs, review mode, approval timestamp, and the fact that step-up succeeded. TOTP values, recovery codes, tokens, secrets, passwords, authorization headers, cookies, and API keys are never stored in governance metadata or written to audit/error output.

Approve and execute are separate transitions. Approving records evidence and never dispatches the operation. A later explicit execute action queues the existing worker; the worker recomputes and verifies the exact target fingerprint and evidence SHA before changing the approval to `EXECUTING`. Any payload, reason, correlation, actor, mode, schema, timestamp, or evidence drift fails closed before action dispatch. Duplicate approval is idempotent only when the existing immutable evidence still matches.

The adapter does not add a generic data-lifecycle executor or bypass existing domain authorization. Registered data-lifecycle targets may bind approval evidence, but a concrete execution adapter remains separately required. Tests use local transactions and fake queues only; this train performs no refund, benefit, payment, rollback, or data-lifecycle production action.

## Registry and public projection

`ReviewPolicyRegistry` inventories every known human-review surface and declares its domain, risk tier, authority, current implementation, solo-owner policy, step-up boundary, execution separation, public projection, external-evidence requirement, migration PR, and adapter status. Architecture tests enforce schema, uniqueness, required coverage, R3 step-up, R4 evidence classification, and registration of models with review/approval fields. The generated inventory is `docs/operations/generated/solo-owner-review-surface-registry.v1.json`.

The eventual public contract is limited to `review_state`, `last_reviewed_at`, and `reviewer: null`. Private owner identity, admin ID, evidence, exceptions, notes, attestation content, tokens, secrets, and private URLs must never be projected publicly. Public normalization belongs to `SOLO-OWNER-PUBLIC-REVIEW-CONTRACT-06`; this foundation does not change public APIs.

## Repository rule impact

This protocol changes the internal reviewer-separation contract: one configured owner/operator may complete internal human review. Backend/CMS remains the authority. External evidence, production authorization, publication/indexability, automated verification, high-risk step-up, privacy, and deployment controls are unchanged.
