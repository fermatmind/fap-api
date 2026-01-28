-- Update threshold_usd to match current daily budget.
SELECT
  COALESCE(SUM(cost_usd), 0) AS today_cost_usd,
  5.0 AS threshold_usd,
  CASE WHEN COALESCE(SUM(cost_usd), 0) >= 5.0 THEN 1 ELSE 0 END AS is_alert
FROM ai_insights
WHERE DATE(created_at) = CURRENT_DATE;
