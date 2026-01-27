SELECT
  question_index,
  SUM(CASE WHEN (is_dropoff = 1) OR (COALESCE(event_name, event_code) = 'dropoff') THEN 1 ELSE 0 END) AS dropoff_count,
  SUM(CASE WHEN COALESCE(event_name, event_code) = 'question_answered' THEN 1 ELSE 0 END) AS answered_count,
  ROUND(
    SUM(CASE WHEN (is_dropoff = 1) OR (COALESCE(event_name, event_code) = 'dropoff') THEN 1 ELSE 0 END)
    / NULLIF(
      SUM(CASE WHEN (is_dropoff = 1) OR (COALESCE(event_name, event_code) = 'dropoff') THEN 1 ELSE 0 END)
      + SUM(CASE WHEN COALESCE(event_name, event_code) = 'question_answered' THEN 1 ELSE 0 END)
    , 0)
  , 4) AS dropoff_rate
FROM events
WHERE question_index IS NOT NULL
GROUP BY question_index;
