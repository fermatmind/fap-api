# Blocked Tasks

| task_id | blocker label | reason | unblock condition |
| --- | --- | --- | --- |
| `MONEY-INTENT-TDK-DRYRUN-CANDIDATE-SELECTION-01` | `BLOCKED_BY_GSC_DATA_QUALITY` | GSC/seo_intel gate is fixture/missing live readmodel. | `GscDataQualityGate=pass`. |
| `CTR-REPAIR-LOOP-ELIGIBILITY-READONLY-01` | `BLOCKED_BY_GSC_DATA_QUALITY` | Opportunity queue not eligible. | Live/non-fixture readmodel proof. |
| `CTR-TDK-REPAIR-DRYRUN-QUEUE-01` | `BLOCKED_BY_GSC_DATA_QUALITY` | Depends on eligible CTR candidates. | Card 22 pass plus operator/Codex review. |
| `MBTI-MAIN-FAQ-D7-OBSERVATION-01` | `BLOCKED_BY_DATE_WINDOW` | Not before 2026-07-12. | Calendar window opens. |
| `MBTI-MAIN-FAQ-D28-OBSERVATION-01` | `BLOCKED_BY_DATE_WINDOW` | Not before 2026-08-02. | Calendar window opens. |
| `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01` | `OPERATOR_HELD` | Previously excluded. | Fresh operator authorization. |

Runtime evidence gaps are not blockers for docs-only queue generation, but they block any claim of lane completion.
