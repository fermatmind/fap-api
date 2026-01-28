SELECT
  DATE(created_at) AS day,
  provider,
  model,
  status,
  COUNT(*) AS insights_count,
  SUM(tokens_in) AS tokens_in,
  SUM(tokens_out) AS tokens_out,
  SUM(cost_usd) AS cost_usd
FROM ai_insights
WHERE created_at IS NOT NULL
GROUP BY DATE(created_at), provider, model, status;
