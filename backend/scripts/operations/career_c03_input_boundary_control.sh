#!/usr/bin/env bash

set -euo pipefail

if [ "$#" -ne 1 ]; then
  printf '%s\n' 'control.receipt_path.required' >&2
  exit 2
fi

receipt="$1"
validator_sha="${INPUT_BOUNDARY_RUNNER_SHA256:-}"
sha256_pattern='^[0-9a-f]{64}$'
numeric_pattern='^[1-9][0-9]*$'
digest_pattern='^sha256:[0-9a-f]{64}$'

if [ ! -f "$receipt" ] || [ -L "$receipt" ]; then
  printf '%s\n' 'control.receipt_path.regular_file' >&2
  exit 2
fi
if [[ ! "$validator_sha" =~ $sha256_pattern ]]; then
  printf '%s\n' 'control.validator_sha256.required_sha256' >&2
  exit 2
fi

write_boundary() {
  local passed="$1"
  local invariant="$2"
  local temporary

  umask 077
  temporary="$(mktemp "${receipt}.input-boundary.XXXXXX")"
  jq \
    --argjson passed "$passed" \
    --arg invariant "$invariant" \
    --arg validator "$validator_sha" '
      .input_boundary = {
        checked: true,
        passed: $passed,
        failed_invariant: (if $passed then null else $invariant end),
        validator_sha256: $validator
      }
      | .safe_failure_code = (if $passed then null else "INPUT_BOUNDARY_INVARIANT_FAILED" end)
    ' "$receipt" > "$temporary"
  mv "$temporary" "$receipt"
}

fail_boundary() {
  local invariant="$1"
  write_boundary false "$invariant"
  printf '%s\n' "$invariant" >&2
  exit 1
}

require_empty() {
  local name="$1"
  local invariant="$2"
  [ -z "${!name:-}" ] || fail_boundary "$invariant"
}

require_numeric() {
  local name="$1"
  local invariant="$2"
  [[ "${!name:-}" =~ $numeric_pattern ]] || fail_boundary "$invariant"
}

require_sha256() {
  local name="$1"
  local invariant="$2"
  [[ "${!name:-}" =~ $sha256_pattern ]] || fail_boundary "$invariant"
}

require_digest() {
  local name="$1"
  local invariant="$2"
  [[ "${!name:-}" =~ $digest_pattern ]] || fail_boundary "$invariant"
}

require_nonempty() {
  local name="$1"
  local invariant="$2"
  [ -n "${!name:-}" ] || fail_boundary "$invariant"
}

case "${MODE:-}" in
  incident_closeout)
    require_empty INCIDENT_CLOSEOUT_RUN_ID 'incident_closeout.incident_closeout_run_id.must_be_empty'
    require_empty INCIDENT_CLOSEOUT_RUN_ATTEMPT 'incident_closeout.incident_closeout_run_attempt.must_be_empty'
    require_empty EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256 'incident_closeout.expected_incident_closeout_receipt_sha256.must_be_empty'
    require_empty EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST 'incident_closeout.expected_incident_closeout_artifact_digest.must_be_empty'
    require_empty VERIFY_RUN_ID 'incident_closeout.verify_run_id.must_be_empty'
    require_empty VERIFY_RUN_ATTEMPT 'incident_closeout.verify_run_attempt.must_be_empty'
    require_empty EXPECTED_VERIFY_RECEIPT_SHA256 'incident_closeout.expected_verify_receipt_sha256.must_be_empty'
    require_empty EXPECTED_VERIFY_ARTIFACT_DIGEST 'incident_closeout.expected_verify_artifact_digest.must_be_empty'
    require_empty OPERATOR_APPROVAL_PHRASE 'incident_closeout.operator_approval_phrase.must_be_empty'
    ;;
  verify)
    require_numeric INCIDENT_CLOSEOUT_RUN_ID 'verify.incident_closeout_run_id.required_numeric'
    require_numeric INCIDENT_CLOSEOUT_RUN_ATTEMPT 'verify.incident_closeout_run_attempt.required_numeric'
    require_sha256 EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256 'verify.expected_incident_closeout_receipt_sha256.required_sha256'
    require_digest EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST 'verify.expected_incident_closeout_artifact_digest.required_digest'
    require_empty VERIFY_RUN_ID 'verify.verify_run_id.must_be_empty'
    require_empty VERIFY_RUN_ATTEMPT 'verify.verify_run_attempt.must_be_empty'
    require_empty EXPECTED_VERIFY_RECEIPT_SHA256 'verify.expected_verify_receipt_sha256.must_be_empty'
    require_empty EXPECTED_VERIFY_ARTIFACT_DIGEST 'verify.expected_verify_artifact_digest.must_be_empty'
    require_empty OPERATOR_APPROVAL_PHRASE 'verify.operator_approval_phrase.must_be_empty'
    ;;
  apply)
    require_numeric INCIDENT_CLOSEOUT_RUN_ID 'apply.incident_closeout_run_id.required_numeric'
    require_numeric INCIDENT_CLOSEOUT_RUN_ATTEMPT 'apply.incident_closeout_run_attempt.required_numeric'
    require_sha256 EXPECTED_INCIDENT_CLOSEOUT_RECEIPT_SHA256 'apply.expected_incident_closeout_receipt_sha256.required_sha256'
    require_digest EXPECTED_INCIDENT_CLOSEOUT_ARTIFACT_DIGEST 'apply.expected_incident_closeout_artifact_digest.required_digest'
    require_numeric VERIFY_RUN_ID 'apply.verify_run_id.required_numeric'
    require_numeric VERIFY_RUN_ATTEMPT 'apply.verify_run_attempt.required_numeric'
    require_sha256 EXPECTED_VERIFY_RECEIPT_SHA256 'apply.expected_verify_receipt_sha256.required_sha256'
    require_digest EXPECTED_VERIFY_ARTIFACT_DIGEST 'apply.expected_verify_artifact_digest.required_digest'
    require_nonempty OPERATOR_APPROVAL_PHRASE 'apply.operator_approval_phrase.required_nonempty'
    ;;
  *)
    fail_boundary 'control.mode.allowed'
    ;;
esac

write_boundary true ''
