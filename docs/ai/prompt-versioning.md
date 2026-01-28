# Prompt Versioning

## Version Format
- Use semantic versions: `vMAJOR.MINOR.PATCH` (e.g., `v1.0.0`).
- Store in `config/ai.php` as `prompt_version` and persist per insight row.

## Change Policy
- PATCH: wording changes that do not alter expected outputs.
- MINOR: new evidence fields or output sections.
- MAJOR: behavior or schema changes requiring re-baselining.

## Regression Dataset (JSONL)
Store regression prompts in JSONL for deterministic evaluation:
```json
{"input": {"period_type": "week", "period_start": "2026-01-01", "period_end": "2026-01-07"}, "evidence": [...], "expected": {"summary": "..."}}
```

Each line includes:
- `input`: structured request payload
- `evidence`: evidence_json snapshot
- `expected`: required output fields

## Rollout Process
1) Bump `prompt_version` in config.
2) Run regression dataset (offline evaluation).
3) Update `docs/ai/insight-spec.md` if output schema changes.
4) Deploy and monitor `v_ai_*` views for cost/quality anomalies.
