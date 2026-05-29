"""Sidecar failure policy for release train execution."""

from __future__ import annotations


def classify_check_failure(
    *,
    check_name: str,
    required: bool,
    observed_failure: str,
    is_core_smoke: bool = False,
    is_private_or_held_exposure: bool = False,
    likely_external: bool = False,
) -> dict:
    blocking = required or is_core_smoke or is_private_or_held_exposure
    reason = ""

    if check_name.startswith("required_") and required:
        blocking = True
        reason = "required check failed"
    if "5xx" in observed_failure.lower() or "timeout" in observed_failure.lower():
        blocking = True
        reason = "critical transport or backend availability failure"
    if is_private_or_held_exposure:
        blocking = True
        reason = "private or held exposure policy violation"
        blocking = True

    allow_nonblocking = not blocking and likely_external

    return {
        "required": required,
        "check_name": check_name,
        "observed_failure": observed_failure,
        "is_core_smoke": is_core_smoke,
        "is_private_or_held_exposure": is_private_or_held_exposure,
        "likely_external": likely_external,
        "allow_nonblocking": allow_nonblocking,
        "reason": reason or "allowed as non-blocking sidecar",
    }


def build_sidecar_payload(
    *,
    train_id: str,
    pr_number: str,
    repo: str,
    component: str,
    check_name: str,
    observed_failure: str,
    why_nonblocking: str,
    recommended_followup: str,
    owner: str,
    severity: str,
) -> dict:
    return {
        "train_id": train_id,
        "pr_number": str(pr_number),
        "repo": repo,
        "component": component,
        "check_name": check_name,
        "observed_failure": observed_failure,
        "why_nonblocking": why_nonblocking,
        "recommended_followup": recommended_followup,
        "owner": owner,
        "severity": severity,
    }
