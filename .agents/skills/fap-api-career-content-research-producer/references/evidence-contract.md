# Research package evidence contract

## Layout

```text
<output_root>/<batch_id>/
├── careers/<slug>/
│   ├── identity.json
│   ├── definition.json
│   ├── salary.json
│   ├── geo.json
│   ├── ai-impact.json
│   ├── fit-personality.json
│   ├── risk.json
│   ├── compare-links.json
│   ├── faq.json
│   └── page-meta.json
├── source-registry.jsonl
├── claim-bindings.jsonl
├── module-coverage.json
├── unresolved-claims.json
└── research-receipt.json
```

The resolved package parent is the locked `output_root`. It must already exist under the system temporary root and outside the repository.

## Source registry

Each `source-registry.jsonl` row contains `source_key`, `publisher`, `title`, absolute HTTPS `url`, `source_tier` (1–5), `source_type`, `jurisdiction`, `retrieved_at`, `data_year` (integer or `N/A`), `effective_at`, `reviewed_at`, `valid_through`, lowercase 64-hex `content_sha256`, and `scope`.

Allowed scopes are `exact`, `combined_official`, `parent_occupation_proxy`, `industry_proxy`, `recruitment_proxy`, `market_signal`, `internal_rubric`, and `editorial_synthesis`. Tier 4 sources must use a proxy/signal scope and include `collection_query` and positive `sample_size`. Tier 5 sources must use an internal/editorial scope. Require `effective_at <= reviewed_at <= valid_through`; a source is expired when `valid_through < research_as_of`.

## Claim bindings

Each `claim-bindings.jsonl` row contains `slug`, `locale`, module name, an RFC 6901 `json_pointer`, `claim_type`, `source_keys`, `transformation`, `jurisdiction`, `as_of`, and `review_status`.

Allowed transformations are `verbatim_fact`, `normalized`, `calculated`, `combined_official`, `parent_proxy`, `industry_proxy`, `market_signal`, `internal_rubric`, and `editorial_synthesis`. Proxy transformations must bind only to compatible proxy sources and cannot claim exact scope. `calculated` adds a non-empty `formula` and `input_source_keys`, all of which exist and are included in `source_keys`. Tier 5 editorial guidance may have no external source; factual, numeric, dated, salary, growth, or qualification claims may not.

Every pointer resolves in the named module. Every numeric leaf and every date/salary/growth/licensing field has a binding. FAQ facts reuse source keys from the originating module.

## Coverage and unresolved claims

`module-coverage.json` has `schema_version: career.research-module-coverage.v1` and one row per target slug/module with `slug`, `module`, `populated_field_count`, `bound_claim_count`, and `unresolved_claim_count`. `populated_field_count` is the number of non-null top-level module fields; the validator recomputes all three counts.

`unresolved-claims.json` is an array. Every item has `slug`, `locale`, `module`, `json_pointer`, `reason`, and `status: blocker`. It is present even when empty. Missing evidence or omitted requested fields must appear here; no value may be silently zero-filled.

## Research receipt

`research-receipt.json` binds the original request and contains:

- `schema_version`, `validator_version`, `batch_id`, `slugs`, `locales`, `jurisdiction`, `research_as_of`, `source_policy_version`, `output_root`, `authorized_content_scope`, and `canonical_slugs`;
- `counts`: `slug_count`, `locale_count`, `module_count`, `source_count`, `claim_count`, `unresolved_count`, and `expired_source_count`;
- `hashes`: `source_registry_sha256`, `claim_bindings_sha256`, and `candidate_tree_sha256`;
- `non_target_writes`: `repository`, `current_package`, `zh_master`, `english_assets`, `fap_web`, `runtime_api`, `cms_db_cache`, `discoverability_search`, `automation`, and `agent_definitions`, all integer zero.

Counts and hashes are evidence, not authority. The validator recomputes them and fails on drift.

## Deterministic hashing

- Registry and binding hashes are SHA-256 of their exact file bytes.
- Candidate tree hash includes only the ten module files. For each sorted relative POSIX path, hash exact bytes and form `{\"path\": path, \"sha256\": digest}`. SHA-256 the UTF-8 compact JSON array with keys sorted and no ASCII escaping.
- The receipt is excluded to avoid a circular hash. Re-running without byte changes produces the same hashes.
