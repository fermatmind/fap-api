# SEO Agent Opportunity Source Expansion

Task: `SEO-AGENT-OPPORTUNITY-SOURCE-EXPANSION-01`

This contract expands FermatMind SEO Agent opportunity discovery beyond GSC. It defines safe read-only source families that may produce opportunity candidates even when GSC volume is too low. It does not implement scanners, write CMS content, enqueue Search Channel records, submit indexing requests, activate scheduler, or mutate source code.

## Source Families

Allowed source families:

- `gsc_performance`: existing GSC read model signals
- `cms_tdk_gap`: CMS pages missing title, description, canonical metadata, or indexability metadata
- `cms_faq_gap`: CMS pages missing FAQ blocks where the page type expects FAQ support
- `cms_internal_link_gap`: CMS/page records missing approved internal-link targets or anchor coverage
- `runtime_seo_qa`: public-page QA issues such as noindex, canonical mismatch, JSON-LD absence, robots conflicts, redirect surprises, or response status issues
- `sitemap_llms_gap`: sitemap and `llms.txt` eligibility or enumeration gaps
- `hreflang_canonical_gap`: hreflang, locale alternate, or canonical self-reference gaps

## Candidate Shape

Every source must emit sanitized opportunity candidates with:

- `source_family`
- `source_id`
- `subject_type`
- `subject_ref`
- `safe_path`
- `severity`
- `evidence_refs`
- `recommended_next_step`
- `allowed_action`
- `blocked_actions`

Raw URLs, raw queries, credentials, tokens, private paths, raw payloads, and CMS draft content are forbidden in source output.

## Scoring Boundary

The expanded source contract is allowed to score and rank candidates, but not execute actions. Scores must remain explainable through evidence fields. A source candidate may only flow into Codex review or CMS draft dry-run after it is wrapped by `seo-agent-run-control-packet.v1`.

## Execution Boundary

The source expansion does not authorize:

- CMS writes or publish
- Search Channel enqueue or submission
- indexing requests
- sitemap submission
- scheduler activation
- queue workers
- production environment changes
- source-code mutation

Future scanner PRs must implement one source family at a time and preserve this contract.
