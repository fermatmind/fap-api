# Platform 12A-08: daily read-only runtime

## Scope

Only the three versioned daily Catalog Missions are admitted through the frozen-input Scheduler path. They run at 06:20, 06:25 and 06:30 Asia/Shanghai. The existing GSC, funnel, URL Truth reconciliation, runtime probe and weekly decision schedules are unchanged.

The minute tick discovers at most one Mission, drains at most one notification, and exits when idle. It uses the existing shared lease/fencing store and Council tables; no new worker, queue or authority database is introduced. The command has a 120-second hard deadline and a 180-second lease. A nonterminal delivery can compute at most twice, always with its original sanitized input. Earlier-than-activation slots are excluded; subsequent missed slots remain HOLD evidence rather than historical replay.

Council computation and Council audit persistence are distinct from business execution. Model, Tool Broker, CMS, publication, canonical, robots, URL Truth and search writes remain disabled. Neither this change nor runtime resume starts the 28-day measurement clock or signs Day 0 enablement.

## Evidence and acceptance boundaries

- GSC reads the latest scheduled refresh receipt, including failures. Public API health reads only the existing fixed anonymous delivery-probe cache. Runtime calibration binds production readback to the active revision.
- URL Truth compares the existing public authority inventory and URL Truth read model. Cached sitemap counts are observations, never authority. D1 derives a fixed 24-to-48-hour cohort from current decision-card revisions and their first/last observations.
- Safety combines the actual scheduled HTTP negative-set receipt with the existing deterministic guard validator. It scans the bounded active minimized Evidence set and the past-day Council tool audit, not raw queries, identities or private result tables. Empty queried sets are valid zero; missing tables, stale probes, invalid bundles and query-budget overflow are unavailable/HOLD.
- A missing Query HMAC capability is not healthy evidence. Unknown PII state is HOLD. Historical expired Evidence remains visible; no retention deletion is performed.
- Source reference hashes, read times and available source observation times are frozen with the Mission. Replay does not read fresh evidence under an old request identity.
- Terminal delivery, Council audit and notification outbox writes share a fenced database transaction. A revoked generation, stale fence or transaction failure cannot leave a successful partial audit.

## Operator surface

Use the existing `/ops/seo-operations` Automation workspace. It shows runtime state, three recent Mission results, next scheduled times, actionable count and sanitized Trace navigation. There are no run, permission or business-write buttons. Success is receipt-only. Repeated identical incidents are quiet; recovery is emitted once.

The single operational command is `seo:council-runtime status|pause|resume`. Pause affects Council only and retains all evidence. Shared cache failure fails closed. Pause takes effect at the next computation/commit checkpoint, bounded by the command deadline. It also stops Council notification sending. Resume rechecks the same prerequisites and never replays terminal deliveries.

The existing webhook transport cannot prove recipient-side exactly-once delivery after an ambiguous network acknowledgement. Such a send becomes a visible `DELIVERY_ACK_UNKNOWN` terminal failure, not a blind resend. A crash before dispatch can be recovered. Transport outcomes never change Mission verdicts.

## Rollout and rollback

The shipped configuration remains disabled. Development/offline tests are not production activation evidence. Staging and testing permit explicit read-only configuration; production additionally requires the existing immutable full Nightly artifact, with a verified artifact-derived file digest and the active release SHA. `daily_operations` cannot replace `weekly_full_checks`.

The release workflow's existing CI, staging, production and smoke receipts remain deployment authority. Transporting the verified Nightly artifact, enabling the production read-only configuration, verifying staging pause/resume, and observing all three production natural slots are still required before declaring A08 complete. No manual or alternate deployment route is authorized by this document.

Controlled acceptance calls use the existing scheduled command's `--acceptance` option with one allowlisted Catalog ID. They are recorded separately and never count as natural slots. A HOLD caused by a genuinely connected failing source is a valid diagnosis; a missing necessary source is not completed wiring acceptance.

Pause is the runtime rollback. Application rollback remains with the existing deployment/LKG workflow. No schema is removed, audit is retained, and old business schedules remain independent.
