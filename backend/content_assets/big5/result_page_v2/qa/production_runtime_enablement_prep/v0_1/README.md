# Big Five V2 Production Runtime Enablement Prep v0.1

This package records the operator-facing preparation evidence for enabling the Big Five V2 production runtime after import gate pass.

It does not enable production runtime, configure rollout traffic, change environment defaults, import a release snapshot, or add frontend copy.

Scope:
- document the runtime switch path.
- bind the switch path to `big5_result_page_v2_rc_0_3`.
- list fail-closed checks that must remain active before and after enablement.
- defer rollout mode and allowlist scope to a separate rollout-config gate.

Current decision: PREP_ONLY_RUNTIME_DISABLED.
