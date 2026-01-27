SELECT
  g.day,
  COUNT(*) AS generated_count,
  SUM(CASE WHEN c.share_id IS NOT NULL THEN 1 ELSE 0 END) AS clicked_count,
  SUM(CASE WHEN v.share_id IS NOT NULL THEN 1 ELSE 0 END) AS viewed_count,
  ROUND(SUM(CASE WHEN c.share_id IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 4) AS ctr_click,
  ROUND(SUM(CASE WHEN v.share_id IS NOT NULL THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0), 4) AS ctr_view
FROM (
  SELECT
    share_id,
    MIN(DATE(occurred_at)) AS day
  FROM events
  WHERE share_id IS NOT NULL
    AND COALESCE(event_name, event_code) = 'share_generate'
  GROUP BY share_id
) g
LEFT JOIN (
  SELECT DISTINCT share_id
  FROM events
  WHERE share_id IS NOT NULL
    AND COALESCE(event_name, event_code) = 'share_click'
) c ON c.share_id = g.share_id
LEFT JOIN (
  SELECT DISTINCT share_id
  FROM events
  WHERE share_id IS NOT NULL
    AND COALESCE(event_name, event_code) = 'report_view'
) v ON v.share_id = g.share_id
GROUP BY g.day;
