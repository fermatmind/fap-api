SELECT
  day,
  question_index,
  AVG(duration_ms) AS avg_duration_ms,
  MIN(CASE WHEN rn >= CEIL(0.95 * cnt) THEN duration_ms END) AS p95_duration_ms
FROM (
  SELECT
    DATE(occurred_at) AS day,
    question_index,
    duration_ms,
    ROW_NUMBER() OVER (PARTITION BY DATE(occurred_at), question_index ORDER BY duration_ms) AS rn,
    COUNT(*) OVER (PARTITION BY DATE(occurred_at), question_index) AS cnt
  FROM events
  WHERE occurred_at IS NOT NULL
    AND question_index IS NOT NULL
    AND duration_ms IS NOT NULL
) t
GROUP BY day, question_index;
