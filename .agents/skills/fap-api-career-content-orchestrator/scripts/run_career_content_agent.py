#!/usr/bin/env python3
"""Deterministic, no-authority executor for the Career Content Agent gates."""

from __future__ import annotations

import argparse
import contextlib
import fcntl
import hashlib
import importlib.util
import json
import math
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any, Iterator


SKILL_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = SKILL_ROOT.parents[2]
VALIDATOR_PATH = SKILL_ROOT / "scripts" / "validate_content_agent_contract.py"
RESEARCH_SKILL = REPO_ROOT / ".agents/skills/fap-api-career-content-research-producer"
RESEARCH_VALIDATOR = RESEARCH_SKILL / "scripts/validate_research_package.py"
ADAPTER = RESEARCH_SKILL / "scripts/adapt_research_package_to_compiler_evidence.php"
SOURCE_POLICY = RESEARCH_SKILL / "references/source-policy.md"
BASELINE_ASSETS = REPO_ROOT / "backend/content_assets/career/current/assets.jsonl"
BACKEND = REPO_ROOT / "backend"
STATE_VERSION = "career.content_agent.state.v1"
ADAPTER_VERSION = "career.research.compiler_evidence_adapter.v1"
TERMINAL_STATES = {
    "BLOCKED_INPUT", "BLOCKED_SOURCE_ACCESS", "BLOCKED_RESEARCH", "WARN_EDITORIAL",
    "BLOCKED_EDITORIAL", "BLOCKED_EVIDENCE", "BLOCKED_COMPILE",
    "MANUAL_REVIEW_REQUIRED", "BUDGET_EXHAUSTED", "ORCHESTRATED",
}
WRITE_COUNTS = {
    "repository": 0, "current_package": 0, "zh_master": 0, "english_assets": 0,
    "runtime_api": 0, "cms": 0, "database": 0, "cache": 0, "publisher": 0,
    "deploy": 0, "sitemap": 0, "discoverability": 0,
    "search_submission": 0, "automation": 0,
}
PERMISSIONS = {
    "publication_authorized": False, "current_replacement_authorized": False,
    "deploy_authorized": False, "search_submission_authorized": False,
}
EVIDENCE_CONTRACTS = [
    "career.claim_binding.v1", "career.evidence.authority.manifest.v1",
    "career.evidence.cohort.v1", "career.evidence.maturity_selection.v1",
    "career.evidence.schema_profile_manifest.v1", "career.source_registry.v1",
]


def _load_validator() -> Any:
    spec = importlib.util.spec_from_file_location("career_content_agent_contract", VALIDATOR_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError("contract_validator_unavailable")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTRACT = _load_validator()


class AgentError(RuntimeError):
    """Safe command failure."""


def canonical_bytes(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def hash_value(value: Any) -> str:
    return hashlib.sha256(canonical_bytes(value)).hexdigest()


def hash_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def read_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def read_jsonl(path: Path) -> list[dict[str, Any]]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]


def atomic_json(path: Path, value: Any) -> None:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2).encode("utf-8") + b"\n"
    fd, temporary = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        directory_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


@contextlib.contextmanager
def output_lock(root: Path, *, exclusive: bool = True, create: bool = True) -> Iterator[None]:
    lock_path = root / ".career-content-agent.lock"
    if not create and not lock_path.is_file():
        raise AgentError("output_root_not_initialized")
    descriptor = os.open(lock_path, (os.O_CREAT if create else 0) | os.O_RDWR, 0o600)
    operation = fcntl.LOCK_EX if exclusive else fcntl.LOCK_SH
    try:
        try:
            fcntl.flock(descriptor, operation | fcntl.LOCK_NB)
        except BlockingIOError as exc:
            raise AgentError("output_root_locked") from exc
        yield
    finally:
        fcntl.flock(descriptor, fcntl.LOCK_UN)
        os.close(descriptor)


def resolve_root(raw: str) -> Path:
    try:
        root = Path(raw).resolve(strict=True)
    except OSError as exc:
        raise AgentError("output_root_unresolvable") from exc
    if not root.is_dir():
        raise AgentError("output_root_not_directory")
    return root


def state_path(root: Path) -> Path:
    return root / "agent-state.json"


def load_state(root: Path) -> dict[str, Any]:
    path = state_path(root)
    if not path.is_file() or path.is_symlink():
        raise AgentError("agent_state_missing")
    state = read_json(path)
    if state.get("state_version") != STATE_VERSION or state.get("output_root") != str(root.resolve(strict=True)):
        raise AgentError("agent_state_invalid")
    return state


def save_state(root: Path, state: dict[str, Any]) -> None:
    atomic_json(state_path(root), state)


def request_for(state: dict[str, Any], root: Path) -> dict[str, Any]:
    request = read_json(root / "request.locked.json")
    result = CONTRACT.validate_request(request)
    if not result["ok"] or result["request_hash"] != state["request_hash"] or result["inventory_hash"] != state["inventory_hash"]:
        raise AgentError("locked_request_drift")
    return request


def command_payload_hash(paths: list[Path], values: dict[str, Any]) -> str:
    rows = []
    for path in paths:
        if path.is_file():
            rows.append({"path": str(path.resolve()), "sha256": hash_file(path)})
        elif path.is_dir():
            files = [item for item in path.rglob("*") if item.is_file() and not item.is_symlink()]
            rows.append({"path": str(path.resolve()), "tree": [
                {"path": item.relative_to(path).as_posix(), "sha256": hash_file(item)} for item in sorted(files)
            ]})
        else:
            raise AgentError(f"input_path_missing:{path}")
    return hash_value({"paths": rows, "values": values})


def gate_repeat(state: dict[str, Any], gate: str, execution_hash: str) -> bool:
    recorded = state["execution_input_hashes"].get(gate)
    if recorded is None:
        return False
    if recorded != execution_hash:
        raise AgentError(f"gate_input_hash_conflict:{gate}")
    return True


def require_state(state: dict[str, Any], expected: str) -> None:
    if state["state"] in TERMINAL_STATES:
        raise AgentError(f"terminal_state:{state['state']}")
    if state["state"] != expected:
        raise AgentError(f"gate_order_invalid:expected_{expected}:actual_{state['state']}")


def run_process_json(command: list[str], cwd: Path | None = None) -> tuple[int, dict[str, Any]]:
    result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, check=False)
    raw = result.stdout.strip()
    try:
        payload = json.loads(raw) if raw else {}
    except json.JSONDecodeError as exc:
        raise AgentError("command_output_not_json") from exc
    return result.returncode, payload


def run_json(command: list[str], cwd: Path | None = None) -> dict[str, Any]:
    returncode, payload = run_process_json(command, cwd)
    if returncode != 0:
        code = payload.get("safe_error_code") or payload.get("errors") or "command_failed"
        raise AgentError(f"subprocess_failed:{code}")
    return payload


def empty_counts() -> dict[str, Any]:
    return {
        "research_modules_per_slug": 0, "producer_errors": 0, "expired_sources": 0,
        "unresolved": 0, "unmapped": 0, "sensitive_claims_without_tier_1_2": 0,
        "dimension_mismatches": 0, "auto_rewrite_attempts": 0,
        "misrouted_unmapped_claims": 0, "research_deterministic_rerun_pass": False,
        "evidence_contracts_passed": 0, "loader_cohort_pass": False,
        "loader_single_slug_pass": False, "evidence_deterministic_rerun_pass": False,
        "required_compiler_claim_coverage_percent": 0, "dry_compile_source_files": 0,
        "dry_compile_locale_projections": 0, "components_per_page": 0,
        "dry_compile_blockers": 0, "candidate_rows": 0,
        "dry_compile_deterministic_rerun_pass": False,
    }


def empty_resources() -> dict[str, Any]:
    return {
        "requests_total": 0, "max_requests_for_any_source": 0,
        "retries_for_any_request": 0, "wall_time_seconds": 0,
        "token_units": {"status": "unknown", "reason": "not observed"},
        "external_spend": {"status": "unknown", "reason": "not observed"},
        "paid_external_api_calls": 0,
    }


def init_agent(request_path: Path) -> dict[str, Any]:
    request = read_json(request_path)
    result = run_json([sys.executable, str(VALIDATOR_PATH), "request", str(request_path)])
    if not result.get("ok"):
        raise AgentError("request_validation_failed:" + ",".join(result.get("errors", [])))
    root = Path(result["normalized_request"]["output_root"])
    with output_lock(root):
        if state_path(root).exists():
            state = load_state(root)
            if state["request_hash"] != result["request_hash"]:
                raise AgentError("request_hash_conflict")
            return public_status(state)
        atomic_json(root / "request.locked.json", result["normalized_request"])
        state = {
            "state_version": STATE_VERSION, "state": "REQUEST_LOCKED", "blocker": None,
            "output_root": str(root), "request_hash": result["request_hash"],
            "inventory_hash": result["inventory_hash"],
            "source_policy": {"version": request["source_policy_version"], "hash": hash_file(SOURCE_POLICY)},
            "completed_gates": [], "execution_input_hashes": {},
            "artifact_hashes": {"research_candidate": None, "evidence_package": None, "dry_compile_candidate": None},
            "slug_results": [{"slug": slug, "evidence_adapter_state": "NOT_RUN", "evidence_package_digest": None, "dry_compile_state": "NOT_RUN", "candidate_row_digest": None} for slug in sorted(request["slugs"])],
            "dimension_binding": {"claim_rows": 0, "source_rows": 0, "adapter_rows": 0, "binding_digest": hash_value([]), "mismatch_count": 0},
            "evidence_contract_versions": [], "dry_compile_status": None,
            "counts": empty_counts(), "resources": empty_resources(), "source_access_blockers": [],
            "manual_review": {"required": False, "status": "not_required"},
            "lifecycle": {"valid": 0, "review_due_soon": 0, "expired": 0, "source_version_superseded": 0, "supersession_bindings": [], "review_queue_count": 0, "published_content_mutations": 0},
        }
        save_state(root, state)
        return public_status(state)


def observations_checked(request: dict[str, Any], raw: dict[str, Any]) -> tuple[dict[str, Any], list[str]]:
    required = {"requests_by_source", "retries_for_any_request", "wall_time_seconds", "token_units", "external_spend", "paid_external_api_calls", "source_access_blockers"}
    if not isinstance(raw, dict) or not required.issubset(raw):
        raise AgentError("resource_observations_invalid")
    by_source = raw["requests_by_source"]
    if not isinstance(by_source, dict) or any(not isinstance(v, int) or v < 0 for v in by_source.values()):
        raise AgentError("requests_by_source_invalid")
    integer_fields = ("retries_for_any_request", "paid_external_api_calls")
    if any(not isinstance(raw[key], int) or isinstance(raw[key], bool) or raw[key] < 0 for key in integer_fields):
        raise AgentError("resource_observations_invalid")
    wall_time = raw["wall_time_seconds"]
    if not isinstance(wall_time, (int, float)) or isinstance(wall_time, bool) or not math.isfinite(wall_time) or wall_time < 0:
        raise AgentError("resource_observations_invalid")
    for key in ("token_units", "external_spend"):
        observation = raw[key]
        if not isinstance(observation, dict) or observation.get("status") not in {"known", "unknown"}:
            raise AgentError("resource_observations_invalid")
        if observation["status"] == "known":
            value = observation.get("value")
            if not isinstance(value, (int, float)) or isinstance(value, bool) or not math.isfinite(value) or value < 0:
                raise AgentError("resource_observations_invalid")
        elif not isinstance(observation.get("reason"), str) or not observation["reason"]:
            raise AgentError("resource_observations_invalid")
    resources = {
        "requests_total": sum(by_source.values()),
        "max_requests_for_any_source": max(by_source.values(), default=0),
        "retries_for_any_request": raw["retries_for_any_request"],
        "wall_time_seconds": raw["wall_time_seconds"], "token_units": raw["token_units"],
        "external_spend": raw["external_spend"], "paid_external_api_calls": raw["paid_external_api_calls"],
    }
    blockers = raw["source_access_blockers"]
    if not isinstance(blockers, list) or any(not isinstance(item, str) or not item for item in blockers):
        raise AgentError("source_access_blockers_invalid")
    probe = {"resources": resources}
    if CONTRACT.budget_exceeded(request, probe):
        return resources, blockers
    return resources, blockers


def lifecycle_summary(request: dict[str, Any], sources: list[dict[str, Any]]) -> dict[str, Any]:
    counts = {"valid": 0, "review_due_soon": 0, "expired": 0, "source_version_superseded": 0}
    bindings = []
    for row in sources:
        superseding = row.get("superseding_version")
        superseded = isinstance(superseding, str) and bool(superseding)
        status = CONTRACT.lifecycle_state(
            request["research_as_of"], row["valid_through"],
            request["execution_limits"]["review_due_soon_days"], superseded,
        )
        counts[status] += 1
        if superseded:
            bindings.append({
                "source_key": row["source_key"], "previous_version": str(row.get("source_version", "unknown")),
                "superseding_version": superseding, "registry_record_hash": hash_value(row),
            })
    return {**counts, "supersession_bindings": sorted(bindings, key=lambda item: item["source_key"]),
            "review_queue_count": counts["review_due_soon"] + counts["source_version_superseded"],
            "published_content_mutations": 0}


def record_research(root: Path, package: Path, observations_path: Path) -> dict[str, Any]:
    with output_lock(root):
        state = load_state(root)
        execution_hash = command_payload_hash([package, observations_path], {})
        if gate_repeat(state, "research", execution_hash):
            return public_status(state)
        require_state(state, "REQUEST_LOCKED")
        request = request_for(state, root)
        if not package.is_relative_to(root):
            raise AgentError("research_package_outside_locked_root")
        first_code, report = run_process_json([sys.executable, str(RESEARCH_VALIDATOR), str(package)])
        second_code, second_report = run_process_json([sys.executable, str(RESEARCH_VALIDATOR), str(package)])
        receipt = read_json(package / "research-receipt.json")
        requested_jurisdictions = {request["jurisdictions"]["primary"]["code"], *(row["code"] for row in request["jurisdictions"]["comparison"])}
        receipt_jurisdiction = receipt.get("jurisdiction", {})
        comparisons = receipt_jurisdiction.get("comparison", []) if isinstance(receipt_jurisdiction, dict) else []
        package_jurisdictions = {receipt_jurisdiction.get("primary"), *comparisons} if isinstance(comparisons, list) else set()
        if (not set(request["slugs"]).issubset(set(receipt.get("slugs", [])))
            or set(receipt.get("locales", [])) != set(request["locales"])
            or receipt.get("research_as_of") != request["research_as_of"]
            or receipt.get("source_policy_version") != request["source_policy_version"]
            or package_jurisdictions != requested_jurisdictions
            or Path(receipt.get("output_root", "")).resolve() != root.resolve()):
            raise AgentError("research_request_binding_mismatch")
        observations = read_json(observations_path)
        resources, blockers = observations_checked(request, observations)
        state["resources"] = resources
        state["source_access_blockers"] = blockers
        state["execution_input_hashes"]["research"] = execution_hash
        counts = state["counts"]
        counts.update({
            "research_modules_per_slug": report["counts"]["modules"] // len(receipt["slugs"]),
            "producer_errors": len(report.get("errors", [])), "expired_sources": receipt["counts"]["expired_source_count"],
            "unresolved": receipt["counts"]["unresolved_count"],
            "sensitive_claims_without_tier_1_2": int(observations.get("sensitive_claims_without_tier_1_2", 0)),
            "research_deterministic_rerun_pass": first_code == second_code and report == second_report,
        })
        sources = read_jsonl(package / "source-registry.jsonl")
        state["lifecycle"] = lifecycle_summary(request, sources)
        output_hash = receipt["hashes"]["candidate_tree_sha256"]
        state["artifact_hashes"]["research_candidate"] = output_hash
        gate = {"gate": "research", "state": "PASS", "input_hash": hash_value({"request_hash": state["request_hash"], "inventory_hash": state["inventory_hash"], "source_policy": state["source_policy"]}), "output_hash": output_hash}
        final = None
        if CONTRACT.budget_exceeded(request, {"resources": resources}):
            gate["state"], final = "BUDGET_EXHAUSTED", "BUDGET_EXHAUSTED"
        elif blockers:
            gate["state"], final = "BLOCKED", "BLOCKED_SOURCE_ACCESS"
        elif first_code != 0 or not report.get("ok") or counts["research_modules_per_slug"] != 10 or counts["expired_sources"] != 0 or not counts["research_deterministic_rerun_pass"] or (request["authorized_content_scope"]["mode"] in {"c3_6c_single_slug", "c3_6d_batch"} and counts["unresolved"] != 0):
            gate["state"], final = "BLOCKED", "BLOCKED_RESEARCH"
        elif request["risk_class"]["batch_max"] in {"regulated", "ymyl_high"} and counts["sensitive_claims_without_tier_1_2"]:
            gate["state"], final = "BLOCKED", "BLOCKED_RESEARCH"
        state["completed_gates"].append(gate)
        state["state"] = final or "RESEARCH_PASS"
        state["blocker"] = final
        checkpoint = {"execution_input_hash": execution_hash, "research_package": str(package.resolve()), "validator": report, "research_receipt_sha256": hash_file(package / "research-receipt.json"), "gate": gate}
        atomic_json(root / "gate-01-research.json", checkpoint)
        save_state(root, state)
        return public_status(state)


def record_editorial(root: Path, result_path: Path) -> dict[str, Any]:
    with output_lock(root):
        state = load_state(root)
        execution_hash = command_payload_hash([result_path], {})
        if gate_repeat(state, "editorial", execution_hash):
            return public_status(state)
        require_state(state, "RESEARCH_PASS")
        result = read_json(result_path)
        decision = result.get("decision")
        if decision not in {"PASS", "WARN", "BLOCKED"}:
            raise AgentError("editorial_decision_invalid")
        if result.get("research_candidate_sha256") != state["artifact_hashes"]["research_candidate"]:
            raise AgentError("editorial_candidate_hash_mismatch")
        rewrite_attempts = result.get("auto_rewrite_attempts", 0)
        if rewrite_attempts != 0:
            raise AgentError("automatic_rewrite_forbidden")
        state["counts"]["auto_rewrite_attempts"] = 0
        output_hash = hash_value(result)
        previous = state["completed_gates"][0]
        gate = {"gate": "editorial", "state": decision, "input_hash": hash_value({"request_hash": state["request_hash"], "research_candidate": state["artifact_hashes"]["research_candidate"], "research_output": previous["output_hash"]}), "output_hash": output_hash}
        request = request_for(state, root)
        if decision == "WARN":
            state["state"], state["blocker"] = "WARN_EDITORIAL", "WARN_EDITORIAL"
        elif decision == "BLOCKED":
            state["state"], state["blocker"] = "BLOCKED_EDITORIAL", "BLOCKED_EDITORIAL"
        elif request["risk_class"]["batch_max"] == "ymyl_high":
            state["state"], state["blocker"] = "MANUAL_REVIEW_REQUIRED", "MANUAL_REVIEW_REQUIRED"
            state["manual_review"] = {"required": True, "status": "required_pending"}
        else:
            state["state"], state["blocker"] = "EDITORIAL_PASS", None
        state["execution_input_hashes"]["editorial"] = execution_hash
        state["completed_gates"].append(gate)
        atomic_json(root / "gate-02-editorial.json", {"execution_input_hash": execution_hash, "result": result, "gate": gate})
        save_state(root, state)
        return public_status(state)


def adapter_command(package: Path, source_root: Path, lookup: Path, control: str, target: str, output: Path, evaluation_date: str) -> list[str]:
    return ["php", str(ADAPTER), f"--research-package={package}", f"--source-root={source_root}",
            f"--lookup={lookup}", f"--baseline-assets={BASELINE_ASSETS}", f"--control-slug={control}",
            f"--target-slug={target}", f"--evaluation-date={evaluation_date}", f"--output-root={output}"]


def dimension_binding(request: dict[str, Any], package: Path, adapter_roots: list[Path]) -> tuple[dict[str, Any], int, int]:
    claims = read_jsonl(package / "claim-bindings.jsonl")
    sources = read_jsonl(package / "source-registry.jsonl")
    adapter_claims = [row for root in adapter_roots for row in read_jsonl(root / "claim-bindings.jsonl")]
    allowed_locales = set(request["locales"])
    allowed_markets = set(request["markets"])
    allowed_jurisdictions = {request["jurisdictions"]["primary"]["code"], *(row["code"] for row in request["jurisdictions"]["comparison"])}
    mismatch = 0
    for row in claims:
        mismatch += int(row.get("locale") not in allowed_locales or row.get("jurisdiction") not in allowed_jurisdictions)
    for row in sources:
        metadata = row.get("compiler_metadata")
        if metadata:
            mismatch += int(metadata.get("market") not in allowed_markets or metadata.get("locale") not in allowed_locales)
    mapped_keys = {row.get("claim_key") for row in adapter_claims}
    unmapped = [row for row in claims if row.get("compiler_disposition") == "not_compiler_mapped"]
    misrouted = sum(1 for row in unmapped if row.get("compiler_mapping", {}).get("compiler_claim_key") in mapped_keys)
    rows = [{"slug": row.get("slug"), "locale": row.get("locale"), "jurisdiction": row.get("jurisdiction"), "source_keys": sorted(row.get("source_keys", []))} for row in claims]
    return {"claim_rows": len(claims), "source_rows": len(sources), "adapter_rows": len(adapter_claims), "binding_digest": hash_value(sorted(rows, key=lambda row: canonical_bytes(row))), "mismatch_count": mismatch}, len(unmapped), misrouted


def run_evidence(root: Path, package: Path, source_root: Path, lookup: Path, control: str) -> dict[str, Any]:
    with output_lock(root):
        state = load_state(root)
        if "evidence_adapter" not in state["execution_input_hashes"]:
            require_state(state, "EDITORIAL_PASS")
        execution_hash = command_payload_hash([package, source_root, lookup], {"control_slug": control})
        if gate_repeat(state, "evidence_adapter", execution_hash):
            return public_status(state)
        request = request_for(state, root)
        adapter_roots = []
        outputs = []
        try:
            for slug in sorted(request["slugs"]):
                if slug == control:
                    raise AgentError("adapter_control_target_overlap")
                output = root / f"evidence-{slug}"
                output.mkdir(mode=0o700, exist_ok=True)
                first = run_json(adapter_command(package, source_root, lookup, control, slug, output, request["research_as_of"]), REPO_ROOT)
                before = {item.name: hash_file(item) for item in output.iterdir() if item.is_file()}
                second = run_json(adapter_command(package, source_root, lookup, control, slug, output, request["research_as_of"]), REPO_ROOT)
                after = {item.name: hash_file(item) for item in output.iterdir() if item.is_file()}
                if first.get("status") != "PASS_RESEARCH_COMPILER_EVIDENCE_ADAPTER" or first != second or before != after:
                    raise AgentError("adapter_deterministic_rerun_failed")
                digest = first.get("deterministic_output_hash")
                if not isinstance(digest, str) or len(digest) != 64:
                    raise AgentError("adapter_digest_missing")
                adapter_roots.append(output)
                outputs.append({"slug": slug, "output_root": str(output), "digest": digest, "receipt": first})
        except (AgentError, OSError) as exc:
            state["state"], state["blocker"] = "BLOCKED_EVIDENCE", str(exc)
            state["execution_input_hashes"]["evidence_adapter"] = execution_hash
            gate = {"gate": "evidence_adapter", "state": "BLOCKED", "input_hash": hash_value({"request_hash": state["request_hash"], "research_candidate": state["artifact_hashes"]["research_candidate"], "editorial_output": state["completed_gates"][1]["output_hash"], "adapter_version": ADAPTER_VERSION}), "output_hash": hash_value({"blocked": str(exc)})}
            state["completed_gates"].append(gate)
            atomic_json(root / "gate-03-evidence.json", {"execution_input_hash": execution_hash, "error": str(exc), "gate": gate})
            save_state(root, state)
            return public_status(state)
        binding, unmapped, misrouted = dimension_binding(request, package, adapter_roots)
        state["dimension_binding"] = binding
        state["counts"].update({"unmapped": unmapped, "misrouted_unmapped_claims": misrouted,
            "dimension_mismatches": binding["mismatch_count"], "evidence_contracts_passed": 6,
            "loader_cohort_pass": all(item["receipt"].get("loader_cohort_validation") == "passed" for item in outputs),
            "loader_single_slug_pass": all(all(v == "passed" for v in item["receipt"].get("loader_single_slug_validation", {}).values()) for item in outputs),
            "evidence_deterministic_rerun_pass": True, "required_compiler_claim_coverage_percent": 100})
        for row, output in zip(state["slug_results"], outputs):
            row.update({"evidence_adapter_state": "PASS", "evidence_package_digest": output["digest"]})
        aggregate = CONTRACT.evidence_aggregate_hash(state["slug_results"])
        state["artifact_hashes"]["evidence_package"] = aggregate
        gate = {"gate": "evidence_adapter", "state": "PASS", "input_hash": hash_value({"request_hash": state["request_hash"], "research_candidate": state["artifact_hashes"]["research_candidate"], "editorial_output": state["completed_gates"][1]["output_hash"], "adapter_version": ADAPTER_VERSION}), "output_hash": aggregate}
        if binding["mismatch_count"] or misrouted:
            gate["state"], state["state"], state["blocker"] = "BLOCKED", "BLOCKED_EVIDENCE", "dimension_or_unmapped_claim_mismatch"
        else:
            state["state"], state["blocker"] = "EVIDENCE_ADAPTER_PASS", None
        state["execution_input_hashes"]["evidence_adapter"] = execution_hash
        state["evidence_contract_versions"] = EVIDENCE_CONTRACTS
        state["completed_gates"].append(gate)
        atomic_json(root / "gate-03-evidence.json", {"execution_input_hash": execution_hash, "per_slug": outputs, "dimension_binding": binding, "gate": gate})
        save_state(root, state)
        return public_status(state)


def compile_command(slug: str, source_root: Path, lookup: Path, evidence: Path, output: Path) -> list[str]:
    return ["php", "artisan", "career:current-candidate-compile", slug, f"--source-root={source_root}",
            f"--lookup={lookup}", f"--evidence={evidence}", f"--baseline-assets={BASELINE_ASSETS}",
            f"--output-root={output}"]


def run_compile(root: Path, source_root: Path, lookup: Path) -> dict[str, Any]:
    with output_lock(root):
        state = load_state(root)
        if "dry_compile" not in state["execution_input_hashes"]:
            require_state(state, "EVIDENCE_ADAPTER_PASS")
        evidence_roots = [root / f"evidence-{row['slug']}" for row in state["slug_results"]]
        execution_hash = command_payload_hash([source_root, lookup, *evidence_roots], {})
        if gate_repeat(state, "dry_compile", execution_hash):
            return public_status(state)
        if state["lifecycle"]["expired"] or state["lifecycle"]["source_version_superseded"]:
            blocker = "lifecycle_forbids_dry_compile"
            gate = {"gate": "dry_compile", "state": "BLOCKED", "input_hash": hash_value({"request_hash": state["request_hash"], "evidence_package": state["artifact_hashes"]["evidence_package"], "evidence_output": state["completed_gates"][2]["output_hash"], "dimension_binding_digest": state["dimension_binding"]["binding_digest"]}), "output_hash": hash_value({"blocked": blocker})}
            state["state"], state["blocker"] = "BLOCKED_COMPILE", blocker
            state["execution_input_hashes"]["dry_compile"] = execution_hash
            state["completed_gates"].append(gate)
            atomic_json(root / "gate-04-dry-compile.json", {"execution_input_hash": execution_hash, "error": blocker, "gate": gate})
            save_state(root, state)
            return public_status(state)
        outputs = []
        try:
            for row in state["slug_results"]:
                slug = row["slug"]
                output = root / f"dry-compile-{slug}"
                output.mkdir(mode=0o700, exist_ok=True)
                command = compile_command(slug, source_root, lookup, root / f"evidence-{slug}", output)
                first = run_json(command, BACKEND)
                first_digest = hash_file(output / "candidate-row.json") if (output / "candidate-row.json").is_file() else None
                second = run_json(command, BACKEND)
                second_digest = hash_file(output / "candidate-row.json") if (output / "candidate-row.json").is_file() else None
                receipt = first.get("receipt", {})
                if (first.get("status") != "PASS_TEN_BLOCK_DRY_COMPILE" or first != second or first_digest is None or first_digest != second_digest
                    or (receipt.get("mapped_file_count"), receipt.get("locale_count"), receipt.get("component_count")) != (10, 2, 26)
                    or receipt.get("blocked_fields") != []):
                    raise AgentError("dry_compile_contract_failed")
                row.update({"dry_compile_state": "PASS", "candidate_row_digest": first_digest})
                outputs.append({"slug": slug, "output_root": str(output), "candidate_row_digest": first_digest, "receipt": receipt})
        except (AgentError, OSError) as exc:
            state["state"], state["blocker"] = "BLOCKED_COMPILE", str(exc)
            state["execution_input_hashes"]["dry_compile"] = execution_hash
            gate = {"gate": "dry_compile", "state": "BLOCKED", "input_hash": hash_value({"request_hash": state["request_hash"], "evidence_package": state["artifact_hashes"]["evidence_package"], "evidence_output": state["completed_gates"][2]["output_hash"], "dimension_binding_digest": state["dimension_binding"]["binding_digest"]}), "output_hash": hash_value({"blocked": str(exc)})}
            state["completed_gates"].append(gate)
            atomic_json(root / "gate-04-dry-compile.json", {"execution_input_hash": execution_hash, "error": str(exc), "gate": gate})
            save_state(root, state)
            return public_status(state)
        aggregate = CONTRACT.dry_compile_aggregate_hash(state["slug_results"])
        state["artifact_hashes"]["dry_compile_candidate"] = aggregate
        state["dry_compile_status"] = "PASS_TEN_BLOCK_DRY_COMPILE"
        state["counts"].update({"dry_compile_source_files": 10, "dry_compile_locale_projections": 2,
            "components_per_page": 26, "dry_compile_blockers": 0, "candidate_rows": len(outputs),
            "dry_compile_deterministic_rerun_pass": True})
        gate = {"gate": "dry_compile", "state": "PASS", "input_hash": hash_value({"request_hash": state["request_hash"], "evidence_package": state["artifact_hashes"]["evidence_package"], "evidence_output": state["completed_gates"][2]["output_hash"], "dimension_binding_digest": state["dimension_binding"]["binding_digest"]}), "output_hash": aggregate}
        state["execution_input_hashes"]["dry_compile"] = execution_hash
        state["completed_gates"].append(gate)
        state["state"], state["blocker"] = "DRY_COMPILE_PASS", None
        atomic_json(root / "gate-04-dry-compile.json", {"execution_input_hash": execution_hash, "per_slug": outputs, "gate": gate})
        save_state(root, state)
        return public_status(state)


def receipt_from_state(state: dict[str, Any], request: dict[str, Any]) -> dict[str, Any]:
    return {
        "contract_version": "career.content_agent.receipt.v1", "batch_id": request["batch_id"],
        "request_hash": state["request_hash"], "inventory_hash": state["inventory_hash"],
        "source_policy": state["source_policy"], "adapter_version": ADAPTER_VERSION,
        "batch_risk": request["risk_class"]["batch_max"], "final_state": "ORCHESTRATED",
        "gates": list(state["completed_gates"]), "artifact_hashes": state["artifact_hashes"],
        "slug_results": state["slug_results"], "dimension_binding": state["dimension_binding"],
        "evidence_contract_versions": state["evidence_contract_versions"],
        "dry_compile_status": state["dry_compile_status"], "counts": state["counts"],
        "resources": state["resources"], "source_access_blockers": state["source_access_blockers"],
        "manual_review": state["manual_review"], "lifecycle": state["lifecycle"],
        "permissions": PERMISSIONS, "write_counts": WRITE_COUNTS,
    }


def finalize(root: Path) -> dict[str, Any]:
    with output_lock(root):
        state = load_state(root)
        if state["state"] == "ORCHESTRATED":
            return public_status(state)
        require_state(state, "DRY_COMPILE_PASS")
        request = request_for(state, root)
        receipt = receipt_from_state(state, request)
        gate = {"gate": "orchestrator", "state": "PASS", "input_hash": hash_value({"request_hash": state["request_hash"], "dry_compile_candidate": state["artifact_hashes"]["dry_compile_candidate"], "dry_compile_output": state["completed_gates"][3]["output_hash"]}), "output_hash": "0" * 64}
        receipt["gates"].append(gate)
        gate["output_hash"] = CONTRACT.orchestrator_projection_hash(receipt)
        result = CONTRACT.validate_receipt(receipt, request)
        if not result["ok"]:
            raise AgentError("receipt_validation_failed:" + ",".join(result["errors"]))
        atomic_json(root / "career-content-agent-receipt.json", receipt)
        state["completed_gates"] = receipt["gates"]
        state["execution_input_hashes"]["orchestrator"] = gate["input_hash"]
        state["state"], state["blocker"] = "ORCHESTRATED", None
        save_state(root, state)
        return public_status(state)


def next_command(state: dict[str, Any]) -> str | None:
    return {
        "REQUEST_LOCKED": "record-research", "RESEARCH_PASS": "record-editorial",
        "EDITORIAL_PASS": "run-evidence-adapter", "EVIDENCE_ADAPTER_PASS": "run-dry-compile",
        "DRY_COMPILE_PASS": "finalize",
    }.get(state["state"])


def public_status(state: dict[str, Any]) -> dict[str, Any]:
    return {"ok": True, "state": state["state"], "completed_gates": [gate["gate"] for gate in state["completed_gates"]],
            "next_command": next_command(state), "blocker": state["blocker"], "resources": state["resources"],
            "request_hash": state["request_hash"], "inventory_hash": state["inventory_hash"]}


def status(root: Path) -> dict[str, Any]:
    with output_lock(root, exclusive=False, create=False):
        return public_status(load_state(root))


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    commands = root.add_subparsers(dest="command", required=True)
    init = commands.add_parser("init"); init.add_argument("--request", required=True, type=Path)
    for name in ("status", "resume", "finalize"):
        item = commands.add_parser(name); item.add_argument("--output-root", required=True)
    research = commands.add_parser("record-research")
    research.add_argument("--output-root", required=True); research.add_argument("--research-package", required=True, type=Path); research.add_argument("--observations", required=True, type=Path)
    editorial = commands.add_parser("record-editorial")
    editorial.add_argument("--output-root", required=True); editorial.add_argument("--result", required=True, type=Path)
    evidence = commands.add_parser("run-evidence-adapter")
    evidence.add_argument("--output-root", required=True); evidence.add_argument("--research-package", required=True, type=Path); evidence.add_argument("--source-root", required=True, type=Path); evidence.add_argument("--lookup", required=True, type=Path); evidence.add_argument("--control-slug", required=True)
    compile_parser = commands.add_parser("run-dry-compile")
    compile_parser.add_argument("--output-root", required=True); compile_parser.add_argument("--source-root", required=True, type=Path); compile_parser.add_argument("--lookup", required=True, type=Path)
    return root


def main() -> int:
    args = parser().parse_args()
    try:
        if args.command == "init": result = init_agent(args.request)
        else:
            root = resolve_root(args.output_root)
            if args.command in {"status", "resume"}: result = status(root)
            elif args.command == "record-research": result = record_research(root, args.research_package.resolve(strict=True), args.observations.resolve(strict=True))
            elif args.command == "record-editorial": result = record_editorial(root, args.result.resolve(strict=True))
            elif args.command == "run-evidence-adapter": result = run_evidence(root, args.research_package.resolve(strict=True), args.source_root.resolve(strict=True), args.lookup.resolve(strict=True), args.control_slug)
            elif args.command == "run-dry-compile": result = run_compile(root, args.source_root.resolve(strict=True), args.lookup.resolve(strict=True))
            elif args.command == "finalize": result = finalize(root)
            else: raise AgentError("unknown_command")
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0
    except (AgentError, OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False, sort_keys=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
