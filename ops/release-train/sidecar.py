"""Sidecar failure policy for release train execution."""

from __future__ import annotations


def classify_check_failure(
    *,
    check_name: str,
    required: bool,
    observed_failure: str,
    is_core_smoke: bool = False,
    is_private_or_held_exposure: bool = False,
    is_discoverability_artifact: bool = False,
    allow_discoverability_soft_alert: bool = False,
    is_search_channel_or_staging_guard: bool = False,
    likely_external: bool = False,
) -> dict:
    observed = observed_failure.lower()
    transport_failure = "5xx" in observed or "timeout" in observed
    blocking = (
        required
        or is_core_smoke
        or is_private_or_held_exposure
        or is_search_channel_or_staging_guard
    )
    reason = ""

    if check_name.startswith("required_") and required:
        blocking = True
        reason = "required check failed"
    if transport_failure:
        blocking = True
        reason = "critical transport or backend availability failure"
        if (
            is_discoverability_artifact
            and allow_discoverability_soft_alert
            and not required
            and not is_core_smoke
            and not is_private_or_held_exposure
            and not is_search_channel_or_staging_guard
        ):
            blocking = False
            reason = "discoverability artifact transport failure allowed as soft-alert sidecar"
    if is_private_or_held_exposure:
        blocking = True
        reason = "private or held exposure policy violation"
    if is_search_channel_or_staging_guard:
        blocking = True
        reason = "Search Channel or staging guard failure"

    allow_nonblocking = not blocking and likely_external

    return {
        "required": required,
        "check_name": check_name,
        "observed_failure": observed_failure,
        "is_core_smoke": is_core_smoke,
        "is_private_or_held_exposure": is_private_or_held_exposure,
        "is_discoverability_artifact": is_discoverability_artifact,
        "allow_discoverability_soft_alert": allow_discoverability_soft_alert,
        "is_search_channel_or_staging_guard": is_search_channel_or_staging_guard,
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
