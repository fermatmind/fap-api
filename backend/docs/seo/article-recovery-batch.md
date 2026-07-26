# Five-article recovery batch

`SEO-10K-ARTICLE-RECOVERY-BATCH-01` prepares a deterministic manual-review package for five existing article owners with verified current GSC performance deterioration. It does not create URLs or change CMS, database, publication, indexability, sitemap, llms, Search Channel, scheduler, queue, or deploy state.

## Authority and evidence

The committed evidence is sanitized and bounded:

- `live-gsc-evidence.v1.json` records contextual page-level comparison metrics, current public API authority hashes, source references, proposed visible edits, claim boundaries, and the current formal read-model hold.
- `live-gsc-page-cohort-hashes.v1.json` binds all 54 article rows from the exact page export, the deterministic full-cohort ranking, the exact top-five opaque page evidence IDs, and the rank-5/rank-6 cutoff. The random ID-to-URL mapping remains outside Git; neither raw non-target URLs nor unkeyed URL digests are committed.
- `live-gsc-query-summary.v1.json` records only per-target retained/excluded counts and privacy state. Every count must be a nonnegative integer and reconcile as `raw = retained + site_operator_excluded + brand_or_mixed_excluded`. Raw query text and unkeyed query digests are not committed because low-entropy search terms can be dictionary-recovered from ordinary SHA-256 values.
- The evidence SHA, query-summary artifact SHA, each current content SHA, each current SEO SHA, and the ordered target-set SHA are immutable preconditions.
- The five URLs are existing backend-authoritative, public, indexable, sitemap-eligible, llms-eligible, approved article records. The package is not a replacement content authority.

Eligibility requires either `click_delta < 0`, or `click_delta = 0` with `impression_delta < 0`; the planner fails closed if fewer than five rows meet that definition. The deterministic ranking is `click_delta ASC`, then `impression_delta ASC`, then opaque `page_evidence_id ASC`. Thus click loss is prioritized, while ranks 4 and 5 transparently represent click-flat impression loss rather than being mislabeled as click loss. The tie-break is directly verifiable from every committed cohort row without exposing dictionary-reversible URL hashes. Query-owner conflicts are checked before the counts-only sanitization step; no raw query or unkeyed query digest is retained in Git. GSC privacy-threshold suppression is represented explicitly and remains an additional manual-review hold; it is never filled with inferred query text.

The authenticated GSC browser export is contextual candidate evidence, not formal recovery authority. This planner never accepts a self-attested gate artifact as proof of production rows. A future separately scoped backend verifier must read the authoritative `seo_gsc_daily` rows and compute a receipt that binds the GSC property, search type, windows, complete 54-article cohort, and deterministic ranking fingerprint. The completed 10-row production canary covers only the zh MBTI test page, so current eligible article-cohort coverage is `0/54`. The committed package therefore fails closed with `blocked_formal_gsc_readmodel_gate`; it must not be approved or used for CMS work.

## Dry-run

From `backend/`:

```bash
php artisan seo-ops:article-recovery-batch \
  --evidence=content_packs/seo/SEO-10K-ARTICLE-RECOVERY-BATCH-01/live-gsc-evidence.v1.json \
  --confirm-evidence-sha256=b3b1e39ad318d8ad59becd61be804e1ba81ff56707489483bfb77b9d21b75fed \
  --artifact-dir=/tmp/seo-10k-article-recovery-batch-01 \
  --json
```

The command writes only the requested local candidate artifact. While the formal read-model gate is blocked it intentionally exits non-zero, emits `approval_eligible=false`, and still writes the deterministic blocked package for audit. Repeating it with identical inputs produces identical bytes and reports `changed=false`. The exact evidence SHA is locked in the planner; a caller cannot substitute a regenerated evidence file merely by supplying its new SHA. Any evidence drift, full-cohort or cutoff drift, owner conflict, raw-query/raw-URL attestation, missing source/claim boundary, target-count change, unsafe URL, authority mismatch, or scope flag that enables a write blocks the package.

Artifact-directory and file-write failures are reported in the command envelope under `command.issues`; they never mutate the planner package after `package_sha256` is computed.

## Manual review and observation

The current package is not review-eligible. No JSON-only gate can change that. After a future separately reviewed backend row verifier computes and validates a full-cohort receipt, a later scoped change may enable the reviewer to independently confirm:

1. the exact target-set SHA and each current content/SEO SHA still match public authority;
2. the query-owner conflict count remains zero;
3. visible claims are supported by the listed sources and retain the required boundary language;
4. the privacy-suppressed target is reviewed without inventing query evidence, and each review target SHA still binds its query state plus the global and target-specific query-summary artifact hashes;
5. the candidate title, description, and visible section actions are approved per target SHA.

This task does not authorize that future CMS write or publication. If a separately authorized manual publication later occurs, its immutable receipt becomes the observation anchor for D1, D7, D14, and D28. A second batch remains locked until the D28 review is completed; there is no automatic second batch.

## Repository rule impact

No authority or publishing rule changes. Backend CMS/public API remains the article source of truth. This addition is a zero-business-write planning control and sanitized evidence package only; it adds no frontend fallback or generated public SEO surface.
