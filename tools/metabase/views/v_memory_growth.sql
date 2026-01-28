SELECT
  day,
  SUM(proposed_count) AS proposed_count,
  SUM(confirmed_count) AS confirmed_count,
  SUM(deleted_count) AS deleted_count
FROM (
  SELECT DATE(proposed_at) AS day, COUNT(*) AS proposed_count, 0 AS confirmed_count, 0 AS deleted_count
  FROM memories
  WHERE proposed_at IS NOT NULL
  GROUP BY DATE(proposed_at)

  UNION ALL

  SELECT DATE(confirmed_at) AS day, 0 AS proposed_count, COUNT(*) AS confirmed_count, 0 AS deleted_count
  FROM memories
  WHERE confirmed_at IS NOT NULL
  GROUP BY DATE(confirmed_at)

  UNION ALL

  SELECT DATE(deleted_at) AS day, 0 AS proposed_count, 0 AS confirmed_count, COUNT(*) AS deleted_count
  FROM memories
  WHERE deleted_at IS NOT NULL
  GROUP BY DATE(deleted_at)
) t
GROUP BY day;
