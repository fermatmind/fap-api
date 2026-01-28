SELECT
  DATE(created_at) AS day,
  rating,
  reason,
  COUNT(*) AS feedback_count
FROM agent_feedback
WHERE created_at IS NOT NULL
GROUP BY DATE(created_at), rating, reason;
