# MBTI v0.3 Event Mapping (Draft)

Purpose  
Map the A3 Event Dictionary to the actual `events` table / log fields
for the MBTI scale.

Stage: 2 · Skeleton-level

---

## 1. Scope

- Events in use for Stage 2:
  - `scale_view`
  - `test_start`
  - `test_submit`
  - `result_view`
  - `share_generate`
  - `share_click` (optional for Stage 2)

---

## 2. TODO

- For each event, list:
  - required fields (anon_id, scale_code, region, locale, etc.)
  - how it is produced (client / server).
- Ensure naming is consistent with A3 spec.