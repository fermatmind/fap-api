# Stale Ledger Or Open PR Review

## PR #2758-#2764 State

GitHub reports all scoped PRs as merged:

| PR | State | Merge commit |
| --- | --- | --- |
| #2758 | `MERGED` | `bfb09871bc0f6294c48e66ea564e851c201b6205` |
| #2759 | `MERGED` | `8ee3790b3c2a56f7fbde91f685cc8cffe91682ac` |
| #2760 | `MERGED` | `0b17d5aeffa7b8e0829373c0604b34495fabeb74` |
| #2761 | `MERGED` | `824b69a6db3c8353db6b2d5dd4b8fc6fad306afd` |
| #2762 | `MERGED` | `c3c41d0266d754863eb0a06c1f6f102d730c4735` |
| #2763 | `MERGED` | `d10c1c8e1af6befd85d868360cb1536e1acdb981` |
| #2764 | `MERGED` | `f580ec867e5d4aeaed1f00c7ad0ec3e00256bb6c` |

## Ledger Review

`docs/codex/pr-train-state.json` does not contain state entries for #2758-#2764 PR IDs or the scan IDs from this read-only train. This is not automatically a blocker because these PRs were executed as generated-only/ad-hoc evidence PRs, but it means PR-train ledger state cannot be used as proof of their completion.

## Local Stale/Unmerged Evidence

The requested input path `backend/docs/seo/daily-runs/2026-07-05/next-task-roadmap-scan/` is not present in the clean `origin/main` worktree. It exists in another local main worktree as staged/unmerged generated evidence:

- `/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01/backend/docs/seo/daily-runs/2026-07-05/next-task-roadmap-scan/`
- `/Users/rainie/Desktop/GitHub/fap-api-mbti-main-free-faq-content-01/backend/docs/seo/daily-runs/2026-07-05/riasec-zh-test-landing-diagnostic-consumption-01/`

This scan read those local files as contextual evidence only. They should not be treated as merged repository evidence until reconciled by a separate generated-only cleanup PR or intentionally discarded by the operator.

## Open PR Review

No open PR was created or modified by the evidence PRs #2758-#2764 at the time of this scan. This scan itself is a new generated-only PR scope.
