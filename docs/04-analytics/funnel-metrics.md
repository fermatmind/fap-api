# Funnel Metrics (PR9)

## Definitions

- Funnel daily: `v_funnel_daily` (event_name by day, includes unique users/sessions)
- Dropoff: `v_question_dropoff` (is_dropoff=1 or event_name='dropoff')
- Duration heatmap: `v_question_duration_heatmap` (avg + p95 duration)
- Share conversion: `v_share_conversion`
- Content pack distribution: `v_content_pack_distribution`

## Verify events columns are populated (last 1 hour)

```
SELECT
  id,
  event_code,
  event_name,
  attempt_id,
  question_index,
  duration_ms,
  share_id,
  pack_id,
  dir_version,
  region,
  locale,
  occurred_at
FROM events
WHERE occurred_at >= NOW() - INTERVAL 1 HOUR
ORDER BY occurred_at DESC
LIMIT 50;
```
