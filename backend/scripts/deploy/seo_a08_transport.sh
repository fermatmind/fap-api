#!/usr/bin/env bash
set -euo pipefail
[[ "${A08_GATE_ONLY:-false}" = true || "${A08_GATE_ONLY:-false}" = false ]]
mode="${1:?}"; output="${2:?}"
[[ "$mode" = state || "$mode" = install || "$mode" = sources || "$mode" = acceptance || "$mode" = staging-safety ]]
printf -v q_path '%q' "$DEPLOY_PATH"
ssh_args=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -p "$DEPLOY_PORT")
if [[ "${TARGET:?}" = staging ]]; then
  ssh_args+=(-o IdentitiesOnly=yes -i "${DEPLOY_IDENTITY_FILE_STG:?}")
fi
identity_cmd=''
if [[ "${TARGET:?}" = production || "$mode" = sources || "$mode" = staging-safety ]]; then identity_cmd='sudo -n -u www-data --'; fi
if [[ "$mode" = state ]]; then
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd env A08_GATE_ONLY=${A08_GATE_ONLY:-false} php" \
    < backend/scripts/deploy/seo_a08_state.php > "$output"
elif [[ "$mode" = staging-safety ]]; then
  [[ "$TARGET" = staging ]]
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd php scripts/deploy/seo_a08_staging_safety.php" > "$output"
elif [[ "$mode" = sources ]]; then
  mkdir -p "$output"
  missions=(seo.platform12.daily_gsc_core_runtime seo.platform12.daily_url_truth_reconciliation seo.platform12.daily_private_policy_evidence_drift)
  for index in 0 1 2; do
    ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd php artisan seo:council-source-check --mission=${missions[$index]} --json" > "$output/source-$index.json"
  done
elif [[ "$mode" = acceptance || "$mode" = staging-safety ]]; then
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd php scripts/deploy/seo_a08_acceptance.php" < "$output" > "${3:?}"
else
  [[ "$DEPLOY_SHA" =~ ^[a-f0-9]{40}$ ]]
  # The deployed installer validates exact SHA/hash/schema and never changes runtime cache.
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd php scripts/deploy/seo_a08_install.php" < "$output"
fi
