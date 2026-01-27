SELECT
  DATE(occurred_at) AS day,
  COALESCE(event_name, event_code) AS event_name,
  COUNT(*) AS events_count,
  COUNT(DISTINCT CASE
    WHEN user_id IS NOT NULL THEN CONCAT('u:', user_id)
    WHEN anon_id IS NOT NULL AND anon_id <> '' THEN CONCAT('a:', anon_id)
    WHEN session_id IS NOT NULL AND session_id <> '' THEN CONCAT('s:', session_id)
    ELSE NULL
  END) AS unique_users,
  COUNT(DISTINCT CASE
    WHEN session_id IS NOT NULL AND session_id <> '' THEN session_id
    ELSE NULL
  END) AS unique_sessions
FROM events
WHERE occurred_at IS NOT NULL
GROUP BY DATE(occurred_at), COALESCE(event_name, event_code);
