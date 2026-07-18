# Career detail atomic exposure

Career detail exposure is fail-closed against the same cache-readiness contract used by the coverage audit. A target locale is ready only when its detail payload is available through `ready_active`, `ready_lkg`, or `legacy_migratable` state.

The required release order is:

1. build the detail projection;
2. publish an active/LKG-safe detail projection;
3. verify its pointer and payload;
4. expose runtime projection flags such as `detail_route_enabled` and `dataset_visible`;
5. rebuild and activate the locale directory read model.

`CareerRuntimePublishProjectionValidator`, the canonical promotion rollback gate, and the post-promotion release gate reject exposure with `detail_cache_not_ready_for_exposure` or `post_promotion_detail_cache_not_ready` when the target locale is not ready. Apply prepares only the bounded promotion batch from its explicit post-promotion projection while database exposure remains uncommitted, verifies the resulting active pointer and payload, commits exposure, and then activates the directory. It never performs a synchronous full-corpus rebuild.

For ordinary targeted warming, detail payloads are warmed before the full directory/index warm so the newly activated directory observes the final locale readiness state. Multi-locale directory activation stages and verifies every immutable locale payload before switching any active pointer. If a later pointer switch fails, all active/LKG/activation metadata touched by the batch is restored, so a partially activated directory cannot advertise a remediated promotion.

The directory keeps public/indexability authority separate from runtime detail readiness. An otherwise eligible occupation remains a directory member when a transient cache loss occurs, but its `detail_ready` field becomes `false` on the next directory rebuild. This runtime state never deletes or rewrites CMS content, occupation records, publication authority, sitemap, llms, canonical, noindex, or JSON-LD authority.

This change performs no production promotion, cache repair, CMS/database mutation, or deployment. Runtime monitoring and controlled repair belong to `CAREER-DETAIL-DEPLOY-SLO-REPAIR-01`.
