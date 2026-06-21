# Enneagram Result Page Ops Agent Control Plane

This directory defines the read-only operations contract for the Enneagram result page agent.

The control plane allows the agent to automate:

- `auto-to-pr`: prepare scoped branches, validation evidence, and pull requests.
- `auto-to-staging`: prepare staging-only candidate validation and inactive import plans.
- `auto-to-report`: prepare evidence bundles, failure reports, and sidecar issue payloads.
- `production-manual-gate`: prepare the exact production approval packet.

Production rollout is never automatic. A production activation command may only be prepared after a human provides the exact release id, candidate manifest hash, runtime registry hash, rollback window, and post-activation smoke plan required by `control_plane_v0_1.json`.

This scaffold does not generate content, import candidates, switch runtime, write production data, or touch frontend code.
