#!/usr/bin/env bash

laravel_log_primary_path() {
  local backend_dir="${1:?backend_dir required}"
  printf '%s\n' "${backend_dir}/storage/logs/laravel.log"
}

laravel_log_rotated_paths() {
  local backend_dir="${1:?backend_dir required}"
  local log_dir="${backend_dir}/storage/logs"

  [[ -d "${log_dir}" ]] || return 0

  find "${log_dir}" -maxdepth 1 -type f -name 'laravel-*.log' -print 2>/dev/null | sort -r
}

print_laravel_log_tails() {
  local backend_dir="${1:?backend_dir required}"
  local lines="${2:-200}"
  local primary
  local printed=0

  primary="$(laravel_log_primary_path "${backend_dir}")"

  if [[ -f "${primary}" ]]; then
    echo "--- $(basename "${primary}") (tail) ---"
    tail -n "${lines}" "${primary}" 2>/dev/null || true
    printed=1
  fi

  while IFS= read -r rotated; do
    [[ -n "${rotated}" ]] || continue
    echo "--- $(basename "${rotated}") (tail) ---"
    tail -n "${lines}" "${rotated}" 2>/dev/null || true
    printed=1
  done < <(laravel_log_rotated_paths "${backend_dir}")

  return $((printed == 0))
}

copy_laravel_logs_to_dir() {
  local backend_dir="${1:?backend_dir required}"
  local dest_dir="${2:?dest_dir required}"
  local primary

  mkdir -p "${dest_dir}"
  primary="$(laravel_log_primary_path "${backend_dir}")"

  if [[ -f "${primary}" ]]; then
    cp -f "${primary}" "${dest_dir}/$(basename "${primary}")" 2>/dev/null || true
  fi

  while IFS= read -r rotated; do
    [[ -n "${rotated}" ]] || continue
    cp -f "${rotated}" "${dest_dir}/$(basename "${rotated}")" 2>/dev/null || true
  done < <(laravel_log_rotated_paths "${backend_dir}")
}
