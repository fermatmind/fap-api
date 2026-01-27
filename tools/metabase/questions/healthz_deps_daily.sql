SELECT day, dep_name, total_count, red_count, red_rate
FROM v_healthz_deps_daily
WHERE day >= CURDATE() - INTERVAL 30 DAY
ORDER BY day ASC, dep_name ASC;
