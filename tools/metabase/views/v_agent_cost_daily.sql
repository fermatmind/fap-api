SELECT
  day,
  SUM(insights_cost_usd) AS insights_cost_usd,
  SUM(embeddings_cost_usd) AS embeddings_cost_usd,
  SUM(agent_cost_usd) AS agent_cost_usd,
  SUM(insights_cost_usd + embeddings_cost_usd + agent_cost_usd) AS total_cost_usd
FROM (
  SELECT
    DATE(created_at) AS day,
    SUM(cost_usd) AS insights_cost_usd,
    0 AS embeddings_cost_usd,
    0 AS agent_cost_usd
  FROM ai_insights
  WHERE created_at IS NOT NULL
  GROUP BY DATE(created_at)

  UNION ALL

  SELECT
    DATE(created_at) AS day,
    0 AS insights_cost_usd,
    COUNT(*) * 0.0002 AS embeddings_cost_usd,
    0 AS agent_cost_usd
  FROM embeddings
  WHERE created_at IS NOT NULL
  GROUP BY DATE(created_at)

  UNION ALL

  SELECT
    DATE(sent_at) AS day,
    0 AS insights_cost_usd,
    0 AS embeddings_cost_usd,
    COUNT(*) * 0.001 AS agent_cost_usd
  FROM agent_messages
  WHERE sent_at IS NOT NULL
  GROUP BY DATE(sent_at)
) t
GROUP BY day;
