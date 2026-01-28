-- Ingestion missing rate (sleep_samples as baseline, last 30 days)
WITH RECURSIVE dates AS (
  SELECT CURDATE() AS day
  UNION ALL
  SELECT day - INTERVAL 1 DAY
  FROM dates
  WHERE day > CURDATE() - INTERVAL 29 DAY
),
providers AS (
  SELECT DISTINCT source AS provider FROM sleep_samples
),
daily AS (
  SELECT source AS provider, DATE(recorded_at) AS day, 1 AS actual_days
  FROM sleep_samples
  GROUP BY source, DATE(recorded_at)
)
SELECT
  p.provider,
  d.day,
  1 AS expected_days,
  COALESCE(dly.actual_days, 0) AS actual_days,
  (1 - COALESCE(dly.actual_days, 0)) AS missing_days,
  (1 - COALESCE(dly.actual_days, 0)) AS missing_rate
FROM providers p
CROSS JOIN dates d
LEFT JOIN daily dly
  ON dly.provider = p.provider
 AND dly.day = d.day;
