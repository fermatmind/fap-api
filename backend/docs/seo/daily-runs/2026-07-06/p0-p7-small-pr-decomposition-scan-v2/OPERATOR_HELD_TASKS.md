# Operator Held Tasks

| task_id | held reason | release condition |
| --- | --- | --- |
| `COMPETITOR-ALTERNATIVE-HELD-READINESS-01` | Competitor alternatives require source, claim, and legal policy decisions. | Exact operator authorization for readiness review. |
| `COMPETITOR-ALTERNATIVE-LEGAL-CLAIM-REVIEW-HANDOFF-01` | Legal/claim review cannot be delegated as normal docs work. | Operator/legal approval workflow. |
| `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01` | Explicitly held in earlier train. | Fresh operator reauthorization. |
| `RIASEC-GAOKAO-CLUSTER-CONTENT-PACKAGE-HANDOFF-01` | Could enable content package work. | Exact operator content-package authorization. |
| `CTR-TDK-REPAIR-DRYRUN-QUEUE-01` | Could lead to title/meta public-copy changes. | GSC gate pass plus operator/Codex review. |

Held tasks must not be executed by OpenCode unless the operator issues a new exact `/goal` that explicitly names and authorizes that task.
