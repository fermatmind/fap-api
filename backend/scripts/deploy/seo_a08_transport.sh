#!/usr/bin/env bash
set -euo pipefail
[[ "${A08_GATE_ONLY:-false}" = true || "${A08_GATE_ONLY:-false}" = false ]]
mode="${1:?}"; output="${2:?}"
[[ "$mode" = state || "$mode" = install ]]
printf -v q_path '%q' "$DEPLOY_PATH"
ssh_args=(-o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10 -o ServerAliveInterval=15 -o ServerAliveCountMax=4 -p "$DEPLOY_PORT")
if [[ "${TARGET:?}" = staging ]]; then
  ssh_args+=(-o IdentitiesOnly=yes -i "${DEPLOY_IDENTITY_FILE_STG:?}")
fi
identity=()
if [[ "${TARGET:?}" = production ]]; then identity=(sudo -n -u www-data --); fi
printf -v identity_cmd '%q ' "${identity[@]}"
if [[ "$mode" = state ]]; then
  if [[ "${TARGET}" = staging ]]; then identity_cmd=''; fi
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd env A08_GATE_ONLY=${A08_GATE_ONLY:-false} php" \
    < backend/scripts/deploy/seo_a08_state.php > "$output"
else
  [[ "$DEPLOY_SHA" =~ ^[a-f0-9]{40}$ ]]
  # The deployed installer validates exact SHA/hash/schema and never changes runtime cache.
  if [[ "${TARGET}" = staging ]]; then identity_cmd=''; fi
  ssh "${ssh_args[@]}" "$DEPLOY_USER@$DEPLOY_HOST" "set -e; cd $q_path/current/backend; $identity_cmd php scripts/deploy/seo_a08_install.php" < "$output"
fi
