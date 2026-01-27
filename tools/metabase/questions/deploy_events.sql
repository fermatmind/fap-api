SELECT occurred_at, env, status, revision, actor
FROM v_deploy_events
WHERE occurred_at >= NOW() - INTERVAL 30 DAY
ORDER BY occurred_at DESC;
