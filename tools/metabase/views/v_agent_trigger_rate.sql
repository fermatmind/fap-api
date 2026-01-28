SELECT
  DATE(fired_at) AS day,
  trigger_type,
  COUNT(*) AS fired_count,
  COUNT(DISTINCT user_id) AS users_count
FROM agent_triggers
WHERE fired_at IS NOT NULL
  AND status = 'fired'
GROUP BY DATE(fired_at), trigger_type;
