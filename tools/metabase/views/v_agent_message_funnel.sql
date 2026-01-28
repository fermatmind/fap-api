SELECT
  day,
  SUM(trigger_fired) AS trigger_fired,
  SUM(decision_send) AS decision_send,
  SUM(message_sent) AS message_sent,
  SUM(message_viewed) AS message_viewed,
  SUM(message_feedback) AS message_feedback
FROM (
  SELECT DATE(fired_at) AS day, COUNT(*) AS trigger_fired, 0 AS decision_send, 0 AS message_sent, 0 AS message_viewed, 0 AS message_feedback
  FROM agent_triggers
  WHERE fired_at IS NOT NULL
    AND status = 'fired'
  GROUP BY DATE(fired_at)

  UNION ALL

  SELECT DATE(created_at) AS day, 0, COUNT(*) AS decision_send, 0, 0, 0
  FROM agent_decisions
  WHERE created_at IS NOT NULL
    AND decision = 'send'
  GROUP BY DATE(created_at)

  UNION ALL

  SELECT DATE(sent_at) AS day, 0, 0, COUNT(*) AS message_sent, 0, 0
  FROM agent_messages
  WHERE sent_at IS NOT NULL
  GROUP BY DATE(sent_at)

  UNION ALL

  SELECT DATE(acked_at) AS day, 0, 0, 0, COUNT(*) AS message_viewed, 0
  FROM agent_messages
  WHERE acked_at IS NOT NULL
  GROUP BY DATE(acked_at)

  UNION ALL

  SELECT DATE(created_at) AS day, 0, 0, 0, 0, COUNT(*) AS message_feedback
  FROM agent_feedback
  WHERE created_at IS NOT NULL
  GROUP BY DATE(created_at)
) t
GROUP BY day;
