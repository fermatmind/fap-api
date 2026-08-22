# Research candidate to compiler evidence adapter contract

This adapter is a deterministic compatibility bridge. It does not make a research candidate, evidence root, candidate row, or PASS receipt into Current or publication authority.

## Explicit input boundary

The adapter accepts one research package that already passes `validate_research_package.py`, a read-only ten-block source root, read-only canonical lookup, Current `assets.jsonl` as a compatibility baseline, one control slug, one different target slug, an evaluation date, and an existing system-temporary output directory outside the repository.

The fixed locale mapping is:

- `zh-CN` to compiler locale `zh`;
- `en` to compiler locale `en`;
- every other locale fails closed.

The adapter rejects symlinks, repository paths, Current paths, authority paths, missing lookup identity, overlapping control/target slugs, invalid research receipts, and source or research value drift. It stages the complete package, validates it through the repository `CareerEvidenceAuthorityLoader`, and writes `adapter-receipt.json` last. Repeating the same input produces identical bytes.

## Research extensions

Every research source used by a mapped compiler claim has an explicit `compiler_metadata` object:

```json
{
  "authority": "occupation_fact",
  "trust_certification": "trusted_public_source",
  "market": "US",
  "locale": "en",
  "claim_kinds": ["identity"],
  "captured_at": "YYYY-MM-DD",
  "effective_period": "explicit period",
  "confidence_method": "explicit method",
  "usage": "bounded usage",
  "expires_at": "YYYY-MM-DD"
}
```

Authority and trust certification must be one of the existing loader combinations. Market, mapped locale, captured date, effective period, and expiry must agree for every source bound to one claim. CN and US evidence are never exchanged. A proxy claim has a non-empty explicit boundary. Expired sources or claims cannot become approved compiler claims.

Every research claim declares exactly one disposition:

- `compiler_disposition: mapped` requires a complete `compiler_mapping`;
- `compiler_disposition: not_compiler_mapped` requires `compiler_unmapped_reason` and is listed in the adapter receipt.

The mapping contains `compiler_claim_key`, `compiler_claim_kind`, `input_jsonpath`, `component_id`, `authority_output_jsonpath`, `claim_mode`, `confidence`, `evidence_basis`, `proxy`, `proxy_boundary`, and `expires_at`. Missing, conflicting, duplicate, or unsupported mapping fails closed. The research RFC 6901 value and the ten-block compiler JSONPath value must have the same `CareerCurrentAuthorityPackage::hashValue` digest.

The supported compiler claim keys are:

- `identity.title_en`
- `identity.title_zh`
- `hero.lead`
- `definition.summary`
- `duties.list`
- `work_context.summary`
- `faq.items`
- `seo.title`
- `seo.description`

Salary, AI, market-signal, and other research claims stay in the research package as explicit `not_compiler_mapped` claims until the existing compiler consumes an exact public field. They are not guessed, silently dropped, or forced into another field.

## Output boundary

The output contains only:

```text
manifest.json
source-registry.jsonl
claim-bindings.jsonl
schema-profile-manifest.json
cohort.json
selection-report.json
adapter-receipt.json
```

These use the existing contracts `career.source_registry.v1`, `career.claim_binding.v1`, `career.evidence.authority.manifest.v1`, `career.evidence.cohort.v1`, `career.evidence.schema_profile_manifest.v1`, and `career.evidence.maturity_selection.v1`.

The cohort contains one non-empty control set and one non-empty target set with no overlap, their ordered union, and `software-developers` on manual hold. Baseline row and public-content hashes are computed from the supplied Current assets at execution time. Selection records only the explicit contract compatibility test; it does not restore maturity scoring, `READY_NOW`, or approval logic.

`adapter-receipt.json` reports mapped and unmapped counts, deterministic output hashes, loader validation, locale mapping, and zero non-target writes. It is compatibility evidence, never a publication receipt. The adapter never writes Current, source copy, English assets, runtime/API, CMS, database, cache, sitemap, discoverability, or search systems.

## Command

```bash
php scripts/adapt_research_package_to_compiler_evidence.php \
  --research-package=<validated-research-package> \
  --source-root=<read-only-ten-block-source-root> \
  --lookup=<read-only-career-lookup.json> \
  --baseline-assets=<current-assets.jsonl> \
  --control-slug=accountants-and-auditors \
  --target-slug=health-educators \
  --evaluation-date=<YYYY-MM-DD> \
  --output-root=<existing-system-temp-directory>
```

Validate the generated evidence with a real `career:current-candidate-compile` dry compile. Never pass `--write-current` and never route an adapter output to a publisher.
