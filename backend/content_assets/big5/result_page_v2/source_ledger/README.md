# Big Five Result Page V2 Source Ledger

Status: `BIG5-RESULT-SOURCE-LEDGER-01`

This directory records source authority for the Big Five Result Page V2 asset agent. It is evidence-only. It does not generate selector assets, import CMS data, change runtime behavior, or open pilot or production gates.

## Files

- `source_ledger.json`: normalized source-boundary contract consumed by the audit agent. Its only allowed source labels are `public_domain_source`, `citation_only`, `structure_reference_only`, and `forbidden_copy_source`.
- `source_ledger_template_v0_1.json`: schema-like template for future source ledger rows.
- `initial_evidence_ledger_v0_1.json`: first source ledger covering public method sources, internal Big Five V2 source documents, and existing asset packs.

## Required Claim Trace

Every future content claim must trace to a `source_ledger.json` row with:

- source id and source label;
- source reference URL or repository-relative path;
- claim category;
- permitted use;
- limitation;
- disallowed use;
- copy policy;
- evidence references;
- asset scopes allowed to consume the claim.

If a claim cannot be traced, the agent must fail closed and keep the candidate out of `final_assets.jsonl`.

## Source Boundaries

- IPIP is classified as `public_domain_source` and may be used as public-domain measurement context, but FermatMind assets still require local safety and selector validation.
- BFI-2 may be used only as structure/method reference. Do not copy item text, scoring text, report copy, or body prose.
- University of Oregon Big Five material may be used for dimensional and probabilistic method framing, not for deterministic type claims.
- Internal V2.0 and long-form drafts are internal authority sources, not blanket permission to generalize O59 body copy to all users.
- Existing asset packs are staging evidence. They do not imply runtime, pilot, CMS import, or production readiness.
- Forbidden-copy sources are hard rejection boundaries for future candidates, not usable copy sources.
