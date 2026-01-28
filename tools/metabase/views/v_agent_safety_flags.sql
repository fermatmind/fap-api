SELECT
  DATE(created_at) AS day,
  event_code,
  COUNT(*) AS event_count
FROM events
WHERE created_at IS NOT NULL
  AND event_code IN (
    'agent_safety_escalated',
    'agent_suppressed_by_policy',
    'agent_message_failed'
  )
GROUP BY DATE(created_at), event_code;
