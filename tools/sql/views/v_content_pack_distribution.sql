SELECT
  pack_id,
  dir_version,
  region,
  locale,
  COUNT(*) AS events_count,
  ROUND(COUNT(*) / NULLIF(SUM(COUNT(*)) OVER (), 0), 6) AS share_of_total
FROM events
WHERE pack_id IS NOT NULL
GROUP BY pack_id, dir_version, region, locale;
