SELECT
  DATE(f.created_at) AS day,
  i.provider,
  i.model,
  f.rating,
  f.reason,
  COUNT(*) AS feedback_count
FROM ai_insight_feedback f
JOIN ai_insights i ON i.id = f.insight_id
WHERE f.created_at IS NOT NULL
GROUP BY DATE(f.created_at), i.provider, i.model, f.rating, f.reason;
