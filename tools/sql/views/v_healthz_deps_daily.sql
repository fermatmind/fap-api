SELECT
  day,
  dep_name,
  total_count,
  red_count,
  ROUND(red_count / NULLIF(total_count, 0), 4) AS red_rate
FROM (
  SELECT
    DATE(occurred_at) AS day,
    'db' AS dep_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(deps_json, '$.db.ok')) IN ('false','0') THEN 1 ELSE 0 END) AS red_count
  FROM ops_healthz_snapshots
  GROUP BY DATE(occurred_at)

  UNION ALL

  SELECT
    DATE(occurred_at) AS day,
    'redis' AS dep_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(deps_json, '$.redis.ok')) IN ('false','0') THEN 1 ELSE 0 END) AS red_count
  FROM ops_healthz_snapshots
  GROUP BY DATE(occurred_at)

  UNION ALL

  SELECT
    DATE(occurred_at) AS day,
    'queue' AS dep_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(deps_json, '$.queue.ok')) IN ('false','0') THEN 1 ELSE 0 END) AS red_count
  FROM ops_healthz_snapshots
  GROUP BY DATE(occurred_at)

  UNION ALL

  SELECT
    DATE(occurred_at) AS day,
    'cache_dirs' AS dep_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(deps_json, '$.cache_dirs.ok')) IN ('false','0') THEN 1 ELSE 0 END) AS red_count
  FROM ops_healthz_snapshots
  GROUP BY DATE(occurred_at)

  UNION ALL

  SELECT
    DATE(occurred_at) AS day,
    'content_source' AS dep_name,
    COUNT(*) AS total_count,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(deps_json, '$.content_source.ok')) IN ('false','0') THEN 1 ELSE 0 END) AS red_count
  FROM ops_healthz_snapshots
  GROUP BY DATE(occurred_at)
) t;
