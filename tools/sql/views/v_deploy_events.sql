SELECT
  id,
  env,
  revision,
  status,
  actor,
  meta_json,
  occurred_at,
  DATE(occurred_at) AS day
FROM ops_deploy_events;
