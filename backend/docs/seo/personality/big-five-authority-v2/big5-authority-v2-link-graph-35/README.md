# Big Five Authority V2 — Link Graph 35

This package rebuilds the candidate link, canonical, hreflang, and redirect graph from the exact PR07–34 backend-authoritative inventory. It validates 231 unique candidate nodes, 1,199 real-target locale-preserving internal edges, 109 reciprocal EN/`zh-CN` hreflang pairs, and ten exact single-hop `zh-CN` legacy redirects.

Every node remains a self-canonical CMS/backend candidate. The ten EN legacy polarity explainers remain `compatibility_only` with distinct intent from V2 score-range interpretation pages; they do not receive a fabricated Chinese hreflang counterpart. The ten Chinese legacy aliases are not candidate nodes and redirect with exact 301 mappings to the corresponding V2 range, including `emotional-stability` to `neuroticism-low`.

The graph has zero dead targets, orphan nodes, sink nodes, self-links, cross-locale navigation edges, or redirect chains/cycles. The PHP validator and read-only console command fail closed on canonical, target, hreflang, legacy-intent, redirect, or authority violations.

Repository rule impact: none. This is candidate graph planning/validation only. It does not release or widen metadata, sitemap, llms, JSON-LD, schema, robots, indexability, CMS state, or production behavior; eligibility remains deferred to PR36.
