---
name: fermatmind-global-seo-geo-growth-scan
description: Build and submit a structured zero-budget SEO Council MissionRequest to the unique fap-api Laravel Orchestrator; never route roles, invoke tools/models, or mutate production from the Skill.
---

# FermatMind SEO/GEO Mission thin client

This Skill is an entry adapter only. Laravel is the sole authority for mission validation, privacy, dependency status, Role–Capability Binding, routing, conflict resolution, Career ordering, Policy veto, and RunReceipt generation.

## Required boundary

- Read repository `AGENTS.md` and `backend/resources/seo-agent/council/schemas/seo.mission_request.v1.schema.json`.
- Construct only the schema's exact structured fields.
- Keep `autonomy=L0`; every budget value is zero; `tool_scope=[]`; `egress_scope=[]`.
- Evidence inputs are versioned bundle IDs and SHA-256 refs only. Never include raw queries, page bodies, prompts, private identifiers, raw errors, or chat history.
- `requested_role` is an optional non-authoritative hint. Do not select, add, truncate, or reorder roles.
- Never issue an ActionManifest, add a signing key, invoke a model/tool, export a trace, or write CMS/database/cache/URL Truth/sitemap/discoverability/search state.

## Submit

Write the MissionRequest to a task-local temporary JSON file outside the repository, then call the dedicated local-skill adapter:

```bash
php backend/scripts/seo/submit_seo_council_mission.php /absolute/path/to/mission-request.json
```

The returned `RunReceipt` or HOLD is final for that request. A stop state requires a new MissionRequest; resume is valid only with a repository-verified receipt and step hash.

## Mission mapping

- Weekly scan: `weekly_opportunity`
- Monthly review: `monthly_portfolio`
- Breakthrough: `breakthrough_sprint`
- Explicit full portfolio: `global_portfolio`
- One-domain review: `bounded_review` plus one enumerated `review_domain`
- Independent registry review: `independent_registry_review`
- Career candidate chain: `career_candidate_generation`, `family=career`

Do not maintain a role router or permission table in this Skill. Treat `DEPENDENCY_HOLD`, `ROUTING_SCOPE_HOLD`, `SOURCE_CAPABILITY_UNAVAILABLE`, `unresolved_conflict`, and every other stop state as non-executable.
