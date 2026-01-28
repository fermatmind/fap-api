-- Ingestion latency: latest ingest time - latest recorded_at per provider/day
SELECT
  source AS provider,
  DATE(recorded_at) AS day,
  MAX(created_at) AS latest_ingest_at,
  MAX(recorded_at) AS latest_recorded_at,
  TIMESTAMPDIFF(MINUTE, MAX(recorded_at), MAX(created_at)) AS latency_minutes
FROM (
  SELECT source, recorded_at, created_at FROM sleep_samples
  UNION ALL
  SELECT source, recorded_at, created_at FROM health_samples
  UNION ALL
  SELECT source, recorded_at, created_at FROM screen_time_samples
) t
GROUP BY source, DATE(recorded_at);
