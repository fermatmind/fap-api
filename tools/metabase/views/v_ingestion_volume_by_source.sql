-- Ingestion volume by source/provider per day
SELECT
  source AS provider,
  DATE(recorded_at) AS day,
  COUNT(*) AS sample_count
FROM (
  SELECT source, recorded_at FROM sleep_samples
  UNION ALL
  SELECT source, recorded_at FROM health_samples
  UNION ALL
  SELECT source, recorded_at FROM screen_time_samples
) t
GROUP BY source, DATE(recorded_at);
