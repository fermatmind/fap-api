SELECT
  DATE(created_at) AS day,
  provider,
  model,
  COALESCE(error_code, 'NONE') AS error_code,
  COUNT(*) AS failure_count
FROM ai_insights
WHERE status = 'failed'
  AND created_at >= (CURRENT_DATE - INTERVAL 7 DAY)
GROUP BY DATE(created_at), provider, model, COALESCE(error_code, 'NONE');
