SELECT day, event_name, events_count, unique_users, unique_sessions
FROM v_funnel_daily
WHERE day >= CURDATE() - INTERVAL 30 DAY
ORDER BY day ASC, event_name;
