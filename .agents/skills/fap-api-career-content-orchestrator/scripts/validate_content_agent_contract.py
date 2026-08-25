#!/usr/bin/env python3
"""Fail-closed validator for Career Content Agent request and receipt contracts."""

from __future__ import annotations

import argparse
import functools
import hashlib
import json
import math
import os
import re
import sys
from datetime import date
from pathlib import Path
from typing import Any


REQUEST_VERSION = "career.content_agent.request.v1"
RECEIPT_VERSION = "career.content_agent.receipt.v1"
RISK_ORDER = {"standard": 0, "regulated": 1, "ymyl_high": 2}
GATE_ORDER = ["research", "editorial", "evidence_adapter", "dry_compile", "orchestrator"]
EVIDENCE_CONTRACTS = {
    "career.source_registry.v1", "career.claim_binding.v1", "career.evidence.authority.manifest.v1",
    "career.evidence.cohort.v1", "career.evidence.schema_profile_manifest.v1",
    "career.evidence.maturity_selection.v1",
}
WRITE_KEYS = [
    "repository", "current_package", "zh_master", "english_assets", "runtime_api", "cms",
    "database", "cache", "publisher", "deploy", "sitemap", "discoverability",
    "search_submission", "automation",
]
SCHEMA_ROOT = Path(__file__).resolve().parents[1] / "references" / "schemas"


def repository_root() -> Path:
    here = Path(__file__).resolve()
    for parent in here.parents:
        if (parent / "backend/content_assets/career/current/manifest.json").is_file():
            return parent
    raise RuntimeError("repository_root_not_found")


def canonical_bytes(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")


def sha256_value(value: Any) -> str:
    return hashlib.sha256(canonical_bytes(value)).hexdigest()


def load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as handle:
        return json.load(handle)


def load_schema(version: str) -> dict[str, Any]:
    filename = f"{version}.schema.json"
    schema = load_json(SCHEMA_ROOT / filename)
    if schema.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
        raise ValueError(f"schema_draft_invalid:{filename}")
    return schema


def _resolve_ref(root: dict[str, Any], ref: str) -> dict[str, Any]:
    if not ref.startswith("#/"):
        raise ValueError(f"external_schema_ref_forbidden:{ref}")
    value: Any = root
    for token in ref[2:].split("/"):
        value = value[token.replace("~1", "/").replace("~0", "~")]
    return value


def validate_schema(value: Any, schema: dict[str, Any], root: dict[str, Any] | None = None, path: str = "$") -> list[str]:
    root = root or schema
    if "$ref" in schema:
        return validate_schema(value, _resolve_ref(root, schema["$ref"]), root, path)
    if "oneOf" in schema:
        matches = [validate_schema(value, item, root, path) for item in schema["oneOf"]]
        passing = [errors for errors in matches if not errors]
        return [] if len(passing) == 1 else [f"{path}:oneOf"]

    errors: list[str] = []
    expected = schema.get("type")
    allowed_types = expected if isinstance(expected, list) else [expected] if expected else []
    type_ok = not allowed_types or any(_is_type(value, item) for item in allowed_types)
    if not type_ok:
        return [f"{path}:type"]
    if "const" in schema and value != schema["const"]:
        errors.append(f"{path}:const")
    if "enum" in schema and value not in schema["enum"]:
        errors.append(f"{path}:enum")

    if isinstance(value, dict):
        required = schema.get("required", [])
        errors.extend(f"{path}.{key}:required" for key in required if key not in value)
        properties = schema.get("properties", {})
        if schema.get("additionalProperties") is False:
            errors.extend(f"{path}.{key}:additional_property" for key in value if key not in properties)
        for key, child in properties.items():
            if key in value:
                errors.extend(validate_schema(value[key], child, root, f"{path}.{key}"))
    elif isinstance(value, list):
        if len(value) < schema.get("minItems", 0):
            errors.append(f"{path}:minItems")
        if "maxItems" in schema and len(value) > schema["maxItems"]:
            errors.append(f"{path}:maxItems")
        if schema.get("uniqueItems") and len({canonical_bytes(item) for item in value}) != len(value):
            errors.append(f"{path}:uniqueItems")
        if "items" in schema:
            for index, item in enumerate(value):
                errors.extend(validate_schema(item, schema["items"], root, f"{path}[{index}]"))
    elif isinstance(value, str):
        if len(value) < schema.get("minLength", 0):
            errors.append(f"{path}:minLength")
        if "pattern" in schema and re.fullmatch(schema["pattern"], value) is None:
            errors.append(f"{path}:pattern")
        if schema.get("format") == "date":
            try:
                date.fromisoformat(value)
            except ValueError:
                errors.append(f"{path}:format_date")
    elif isinstance(value, (int, float)) and not isinstance(value, bool):
        if not math.isfinite(float(value)):
            errors.append(f"{path}:finite")
        if "minimum" in schema and value < schema["minimum"]:
            errors.append(f"{path}:minimum")
        if "maximum" in schema and value > schema["maximum"]:
            errors.append(f"{path}:maximum")
    return errors


def _is_type(value: Any, expected: str) -> bool:
    return {
        "object": isinstance(value, dict),
        "array": isinstance(value, list),
        "string": isinstance(value, str),
        "integer": isinstance(value, int) and not isinstance(value, bool),
        "number": isinstance(value, (int, float)) and not isinstance(value, bool),
        "boolean": isinstance(value, bool),
        "null": value is None,
    }.get(expected, False)


MODULES = ["identity", "definition", "salary", "geo", "ai-impact", "fit-personality", "risk", "compare-links", "faq", "page-meta"]


def shard_index(slug: str) -> int:
    return int(hashlib.sha256(slug.encode("utf-8")).hexdigest()[:8], 16) % 64


def _current_manifest(repo: Path | None = None) -> tuple[Path, dict[str, Any], dict[str, dict[str, Any]]]:
    current = (repo or repository_root()) / "backend/content_assets/career/current"
    manifest = load_json(current / "manifest.json")
    if manifest.get("contract_version") != "career.sharded_current.manifest.v1" or manifest.get("modules") != MODULES:
        raise ValueError("inventory_manifest_contract_invalid")
    declarations = {row.get("path"): row for row in manifest.get("shards", []) if isinstance(row, dict)}
    expected_paths = {f"{module}/shard-{index:02d}.jsonl" for module in MODULES for index in range(64)}
    if len(declarations) != 640 or set(declarations) != expected_paths:
        raise ValueError("inventory_manifest_shards_invalid")
    for path, declaration in declarations.items():
        module, filename = path.split("/", 1)
        index = int(filename.removeprefix("shard-").removesuffix(".jsonl"))
        if declaration.get("module") != module or declaration.get("shard_index") != index:
            raise ValueError("inventory_manifest_shard_identity_invalid")
    projection = {key: manifest[key] for key in ("contract_version", "modules", "shards", "registries", "coverage", "module_completeness")}
    if manifest.get("aggregate_sha256") != sha256_value(projection):
        raise ValueError("inventory_manifest_aggregate_mismatch")
    if not isinstance(manifest.get("versionless_projection_sha256"), str) or len(manifest["versionless_projection_sha256"]) != 64:
        raise ValueError("inventory_versionless_projection_missing")
    return current, manifest, declarations


def _load_declared_shard(current: Path, declarations: dict[str, dict[str, Any]], relative: str) -> list[dict[str, Any]]:
    declaration = declarations.get(relative)
    path = current / relative
    if declaration is None or not path.is_file() or path.is_symlink():
        raise ValueError("inventory_shard_missing:" + relative)
    raw = path.read_bytes()
    if hashlib.sha256(raw).hexdigest() != declaration.get("sha256"):
        raise ValueError("inventory_shard_hash_mismatch:" + relative)
    rows = [json.loads(line) for line in raw.decode("utf-8").splitlines() if line]
    if len(rows) != declaration.get("row_count"):
        raise ValueError("inventory_shard_count_mismatch:" + relative)
    return rows


@functools.lru_cache(maxsize=1)
def inventory() -> tuple[set[str], str]:
    return inventory_at(repository_root())


def inventory_at(repo: Path) -> tuple[set[str], str]:
    current, manifest, declarations = _current_manifest(repo)
    locales: dict[str, set[str]] = {}
    for index in range(64):
        relative = f"identity/shard-{index:02d}.jsonl"
        for row in _load_declared_shard(current, declarations, relative):
            if row.get("module") != "identity" or row.get("locale") not in {"en", "zh-CN"}:
                raise ValueError("inventory_identity_row_invalid")
            slug = row.get("canonical_slug")
            if not isinstance(slug, str) or shard_index(slug) != index:
                raise ValueError("inventory_slug_invalid")
            locales.setdefault(slug, set()).add(row["locale"])
    ordered = sorted(locales)
    if len(ordered) != 1046 or any(value != {"en", "zh-CN"} for value in locales.values()) or "software-developers" in locales:
        raise ValueError("inventory_contract_invalid")
    if manifest.get("coverage", {}).get("slugs") != 1046:
        raise ValueError("inventory_manifest_coverage_mismatch")
    return set(ordered), sha256_value(ordered)


def expected_current_locks(module: str, slugs: list[str], repo: Path | None = None) -> tuple[list[dict[str, str]], list[dict[str, str]]]:
    current, _manifest, declarations = _current_manifest(repo)
    by_shard: dict[int, list[str]] = {}
    for slug in slugs:
        by_shard.setdefault(shard_index(slug), []).append(slug)
    row_locks: list[dict[str, str]] = []
    shard_locks: list[dict[str, str]] = []
    for index, shard_slugs in sorted(by_shard.items()):
        relative = f"{module}/shard-{index:02d}.jsonl"
        declaration = declarations[relative]
        rows = _load_declared_shard(current, declarations, relative)
        pairs: dict[str, dict[str, Any]] = {}
        for row in rows:
            slug = row.get("canonical_slug")
            if slug in shard_slugs:
                pairs.setdefault(slug, {})[row.get("locale")] = row
        for slug in sorted(shard_slugs):
            if set(pairs.get(slug, {})) != {"en", "zh-CN"}:
                raise ValueError("expected_row_missing:" + slug)
            projection = {"module": module, "rows": pairs[slug], "slug": slug}
            row_locks.append({"slug": slug, "sha256": sha256_value(projection)})
        shard_locks.append({"path": relative, "sha256": declaration["sha256"]})
    return row_locks, shard_locks


def normalize_request(request: dict[str, Any]) -> dict[str, Any]:
    normalized = json.loads(json.dumps(request))
    for key in ("slugs", "locales", "markets"):
        normalized[key] = sorted(normalized[key])
    normalized["jurisdictions"]["comparison"] = sorted(
        normalized["jurisdictions"]["comparison"], key=lambda item: (item["code"], item["status"]),
    )
    normalized["risk_class"]["by_slug"] = sorted(normalized["risk_class"]["by_slug"], key=lambda item: item["slug"])
    normalized["expected_row_hashes"] = sorted(normalized["expected_row_hashes"], key=lambda item: item["slug"])
    normalized["expected_shard_hashes"] = sorted(normalized["expected_shard_hashes"], key=lambda item: item["path"])
    for key in ("modules", "slugs", "locales", "markets"):
        normalized["authorized_content_scope"][key] = sorted(normalized["authorized_content_scope"][key])
    normalized["output_root"] = str(Path(normalized["output_root"]).resolve(strict=True))
    return normalized


def validate_request(request: Any, repo_root: Path | None = None) -> dict[str, Any]:
    errors = validate_schema(request, load_schema(REQUEST_VERSION))
    if errors or not isinstance(request, dict):
        return {"ok": False, "errors": sorted(set(errors)), "state": "BLOCKED_INPUT"}
    repo = (repo_root or repository_root()).resolve()
    try:
        canonical_slugs, inventory_hash = inventory() if repo_root is None else inventory_at(repo)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        return {"ok": False, "errors": [str(exc)], "state": "BLOCKED_INPUT"}

    slugs = request["slugs"]
    missing = sorted(set(slugs) - canonical_slugs)
    if missing:
        errors.append("slug_not_in_current_inventory:" + ",".join(missing))
    if "software-developers" in slugs:
        errors.append("software_developers_forbidden")
    risk_rows = request["risk_class"]["by_slug"]
    risk_slugs = [row["slug"] for row in risk_rows]
    if sorted(risk_slugs) != sorted(slugs) or len(set(risk_slugs)) != len(risk_slugs):
        errors.append("risk_by_slug_must_match_request")
    elif request["risk_class"]["batch_max"] != max((row["class"] for row in risk_rows), key=RISK_ORDER.get):
        errors.append("batch_risk_not_highest")

    scope = request["authorized_content_scope"]
    for key in ("slugs", "locales", "markets"):
        if set(scope[key]) != set(request[key]):
            errors.append(f"authorized_{key}_must_match_request")

    try:
        expected_rows, expected_shards = expected_current_locks(request["module"], slugs, repo)
        if sorted(request["expected_row_hashes"], key=lambda item: item["slug"]) != expected_rows:
            errors.append("expected_row_hash_mismatch")
        if sorted(request["expected_shard_hashes"], key=lambda item: item["path"]) != expected_shards:
            errors.append("expected_shard_hash_mismatch")
    except (OSError, ValueError, KeyError, json.JSONDecodeError) as exc:
        errors.append(str(exc))

    jurisdictions = [request["jurisdictions"]["primary"], *request["jurisdictions"]["comparison"]]
    for item in jurisdictions:
        if (item["status"] == "unknown") != (item["code"] == "UNKNOWN"):
            errors.append("jurisdiction_unknown_marker_mismatch")
    if len({item["code"] for item in jurisdictions}) != len(jurisdictions):
        errors.append("duplicate_jurisdiction")

    raw_root = Path(request["output_root"])
    if ".." in raw_root.parts:
        errors.append("output_root_traversal")
    try:
        if raw_root.is_symlink():
            errors.append("output_root_symlink_forbidden")
        resolved = raw_root.resolve(strict=True)
        temp_roots = {Path(os.path.realpath("/tmp")), Path(os.path.realpath(os.getenv("TMPDIR") or "/tmp"))}
        if not resolved.is_dir() or not any(resolved.is_relative_to(root) for root in temp_roots):
            errors.append("output_root_not_system_temp")
        if not any(part.startswith("career-content-agent-") for part in resolved.parts):
            errors.append("output_root_not_controlled_candidate")
        protected = [
            repo,
            repo / "backend/content_assets/career/current",
            repo / "backend/content_assets/en-content-parity",
            repo / "backend/app",
        ]
        if any(resolved == item or resolved.is_relative_to(item) for item in protected):
            errors.append("output_root_protected")
    except (OSError, RuntimeError):
        errors.append("output_root_unresolvable")

    if errors:
        return {"ok": False, "errors": sorted(set(errors)), "state": "BLOCKED_INPUT"}
    normalized = normalize_request(request)
    return {
        "ok": True,
        "errors": [],
        "state": "REQUEST_LOCKED",
        "request_hash": sha256_value(normalized),
        "inventory_hash": inventory_hash,
        "normalized_request": normalized,
    }


def lifecycle_state(research_as_of: str, valid_through: str, review_due_soon_days: int, superseded: bool) -> str:
    as_of = date.fromisoformat(research_as_of)
    expiry = date.fromisoformat(valid_through)
    if superseded:
        return "source_version_superseded"
    if as_of > expiry:
        return "expired"
    if (expiry - as_of).days <= review_due_soon_days:
        return "review_due_soon"
    return "valid"


def budget_exceeded(request: dict[str, Any], receipt: dict[str, Any]) -> bool:
    limits = request["execution_limits"]
    resources = receipt["resources"]
    checks = [
        resources["requests_total"] > limits["max_requests_total"],
        resources["max_requests_for_any_source"] > limits["max_requests_per_source"],
        resources["retries_for_any_request"] > limits["max_retries_per_request"],
        resources["wall_time_seconds"] > limits["max_wall_time_seconds"],
    ]
    token = resources["token_units"]
    spend = resources["external_spend"]
    if token["status"] == "known":
        checks.append(token["value"] > limits["max_token_units"])
    if spend["status"] == "known":
        checks.append(spend["value"] > limits["max_external_spend_amount"])
    if limits["max_external_spend_amount"] == 0 and spend["status"] == "known":
        checks.append(spend["value"] != 0)
    if limits["max_external_spend_amount"] == 0:
        checks.append(resources["paid_external_api_calls"] != 0)
    return any(checks)


def expected_gate_input_hash(receipt: dict[str, Any], gate_name: str) -> str:
    gates = {gate["gate"]: gate for gate in receipt["gates"]}
    artifacts = receipt["artifact_hashes"]
    bindings = receipt["input_bindings"]
    compiler = bindings["compiler_inputs"]
    if gate_name == "research":
        return sha256_value({"request_hash": receipt["request_hash"], "inventory_hash": receipt["inventory_hash"], "source_policy": receipt["source_policy"]})
    if gate_name == "editorial":
        value = {"request_hash": receipt["request_hash"], "research_candidate": artifacts["research_candidate"], "research_output": gates["research"]["output_hash"]}
    elif gate_name == "evidence_adapter":
        value = {"request_hash": receipt["request_hash"], "research_package_aggregate": artifacts["research_candidate"], "editorial_output": gates["editorial"]["output_hash"], "adapter_version": receipt["adapter_version"], "source_root_digest": compiler["source_root_digest"], "lookup_digest": compiler["lookup_digest"]}
    elif gate_name == "dry_compile":
        value = {"request_hash": receipt["request_hash"], "source_root_digest": compiler["source_root_digest"], "lookup_digest": compiler["lookup_digest"], "evidence_package_digest": compiler["evidence_package_digest"], "evidence_output": gates["evidence_adapter"]["output_hash"], "dimension_binding_digest": receipt["dimension_binding"]["binding_digest"]}
    elif gate_name == "orchestrator":
        value = {"request_hash": receipt["request_hash"], "dry_compile_candidate": artifacts["dry_compile_candidate"], "dry_compile_output": gates["dry_compile"]["output_hash"]}
    else:
        raise ValueError(f"unknown_gate:{gate_name}")
    return sha256_value(value)


def orchestrator_projection_hash(receipt: dict[str, Any]) -> str:
    gates = [{key: value for key, value in gate.items() if not (gate["gate"] == "orchestrator" and key == "output_hash")} for gate in receipt["gates"]]
    return sha256_value({
        "contract_version": receipt["contract_version"], "batch_id": receipt["batch_id"],
        "request_hash": receipt["request_hash"], "inventory_hash": receipt["inventory_hash"],
        "source_policy": receipt["source_policy"], "evidence_policy_version": receipt["evidence_policy_version"], "batch_risk": receipt["batch_risk"],
        "adapter_version": receipt["adapter_version"], "final_state": receipt["final_state"], "gates": gates, "artifact_hashes": receipt["artifact_hashes"], "input_bindings": receipt["input_bindings"],
        "publishable_slugs": receipt["publishable_slugs"],
        "slug_results": receipt["slug_results"], "dimension_binding": receipt["dimension_binding"],
        "evidence_contract_versions": receipt["evidence_contract_versions"], "dry_compile_status": receipt["dry_compile_status"],
        "counts": receipt["counts"], "source_access_blockers": receipt["source_access_blockers"],
        "manual_review": receipt["manual_review"], "lifecycle": receipt["lifecycle"],
        "permissions": receipt["permissions"], "write_counts": receipt["write_counts"],
    })


def dry_compile_aggregate_hash(slug_results: list[dict[str, Any]]) -> str:
    rows = [{"slug": row["slug"], "state": row["dry_compile_state"], "candidate_row_digest": row["candidate_row_digest"]} for row in sorted(slug_results, key=lambda item: item["slug"]) if row["dry_compile_state"] == "PASS"]
    return sha256_value(rows)


def evidence_aggregate_hash(slug_results: list[dict[str, Any]]) -> str:
    rows = [{"slug": row["slug"], "state": row["evidence_adapter_state"], "evidence_package_digest": row["evidence_package_digest"]} for row in sorted(slug_results, key=lambda item: item["slug"]) if row["evidence_adapter_state"] == "PASS"]
    return sha256_value(rows)


def validate_receipt(receipt: Any, request: Any, repo_root: Path | None = None) -> dict[str, Any]:
    errors = validate_schema(receipt, load_schema(RECEIPT_VERSION))
    request_result = validate_request(request, repo_root)
    if not request_result["ok"]:
        errors.extend("request:" + item for item in request_result["errors"])
    if errors or not isinstance(receipt, dict):
        return {"ok": False, "errors": sorted(set(errors))}

    if receipt["batch_id"] != request["batch_id"]:
        errors.append("batch_id_mismatch")
    if receipt["request_hash"] != request_result["request_hash"]:
        errors.append("request_hash_mismatch")
    if receipt["inventory_hash"] != request_result["inventory_hash"]:
        errors.append("inventory_hash_mismatch")
    if receipt["source_policy"]["version"] != request["source_policy_version"]:
        errors.append("source_policy_version_mismatch")
    if receipt["evidence_policy_version"] != request["evidence_policy_version"]:
        errors.append("evidence_policy_version_mismatch")
    source_policy_path = repository_root() / ".agents/skills/fap-api-career-content-research-producer/references/source-policy.md"
    if receipt["source_policy"]["hash"] != hashlib.sha256(source_policy_path.read_bytes()).hexdigest():
        errors.append("source_policy_hash_mismatch")
    if receipt["batch_risk"] != request["risk_class"]["batch_max"]:
        errors.append("batch_risk_mismatch")

    gates = receipt["gates"]
    gate_names = [gate["gate"] for gate in gates]
    if gate_names != GATE_ORDER[:len(gates)]:
        errors.append("gate_order_or_skip_invalid")
    for gate in gates:
        if gate["input_hash"] != expected_gate_input_hash(receipt, gate["gate"]):
            errors.append("gate_composite_input_hash_invalid")
    gate_by_name = {gate["gate"]: gate for gate in gates}
    artifact_bindings = {"research": "research_candidate", "evidence_adapter": "evidence_package", "dry_compile": "dry_compile_candidate"}
    for gate_name, artifact_name in artifact_bindings.items():
        gate = gate_by_name.get(gate_name)
        if gate and gate["state"] == "PASS" and receipt["artifact_hashes"][artifact_name] != gate["output_hash"]:
            errors.append(f"{artifact_name}_gate_hash_mismatch")
    bindings = receipt.get("input_bindings", {})
    research_binding = bindings.get("research_package", {}) if isinstance(bindings, dict) else {}
    compiler_binding = bindings.get("compiler_inputs", {}) if isinstance(bindings, dict) else {}
    if research_binding.get("package_aggregate_sha256") != receipt["artifact_hashes"]["research_candidate"]:
        errors.append("research_package_aggregate_binding_mismatch")
    if sorted(research_binding.get("validated_slugs", [])) != sorted(request["slugs"]):
        errors.append("research_package_slug_binding_mismatch")
    if gate_by_name.get("evidence_adapter", {}).get("state") == "PASS" and compiler_binding.get("evidence_package_digest") != receipt["artifact_hashes"]["evidence_package"]:
        errors.append("compiler_evidence_package_binding_mismatch")
    if gate_by_name.get("orchestrator", {}).get("state") == "PASS" and gate_by_name["orchestrator"]["output_hash"] != orchestrator_projection_hash(receipt):
        errors.append("orchestrator_projection_hash_mismatch")
    non_pass = [gate for gate in gates[:-1] if gate["state"] != "PASS"]
    if non_pass:
        errors.append("gate_continued_after_non_pass")

    final = receipt["final_state"]
    expected_from_gate = {
        ("research", "BLOCKED"): "BLOCKED_RESEARCH",
        ("editorial", "WARN"): "WARN_EDITORIAL",
        ("editorial", "BLOCKED"): "BLOCKED_EDITORIAL",
        ("evidence_adapter", "BLOCKED"): "BLOCKED_EVIDENCE",
        ("dry_compile", "BLOCKED"): "BLOCKED_COMPILE",
    }
    expected_final: str | None = None
    if not gates:
        expected_final = "BLOCKED_INPUT"
    elif gates[-1]["state"] == "BUDGET_EXHAUSTED":
        expected_final = "BUDGET_EXHAUSTED"
    elif gates[-1]["gate"] == "research" and gates[-1]["state"] == "BLOCKED" and receipt["source_access_blockers"]:
        expected_final = "BLOCKED_SOURCE_ACCESS"
    elif gates[-1]["state"] != "PASS":
        expected_final = expected_from_gate.get((gates[-1]["gate"], gates[-1]["state"]))
    elif len(gates) == 5:
        expected_final = "ORCHESTRATED"
    elif receipt["batch_risk"] == "ymyl_high" and gate_names == ["research", "editorial"]:
        expected_final = "MANUAL_REVIEW_REQUIRED"
    if expected_final is None or final != expected_final:
        errors.append("final_state_not_gate_result")
    if final == "ORCHESTRATED" and (len(gates) != 5 or any(gate["state"] != "PASS" for gate in gates)):
        errors.append("orchestrated_requires_five_passes")
    if final == "MANUAL_REVIEW_REQUIRED":
        if receipt["batch_risk"] != "ymyl_high" or gate_names != ["research", "editorial"] or any(gate["state"] != "PASS" for gate in gates):
            errors.append("manual_review_transition_invalid")
    if receipt["batch_risk"] == "ymyl_high" and len(gates) > 2:
        errors.append("ymyl_high_late_gate_forbidden")
    if receipt["batch_risk"] == "ymyl_high" and final != "MANUAL_REVIEW_REQUIRED" and len(gates) >= 2 and gates[1]["state"] == "PASS":
        errors.append("ymyl_high_must_stop_for_review")

    if budget_exceeded(request, receipt) != (final == "BUDGET_EXHAUSTED"):
        errors.append("budget_state_mismatch")
    if receipt["source_access_blockers"] and final not in {"BLOCKED_SOURCE_ACCESS", "BLOCKED_RESEARCH"}:
        errors.append("source_access_blocker_not_terminal")

    counts = receipt["counts"]
    publishable = receipt["publishable_slugs"]
    if "research" in gate_names and gates[0]["state"] == "PASS":
        if counts["research_modules_per_slug"] != 10 or counts["producer_errors"] != 0 or counts["expired_sources"] != 0 or not counts["research_deterministic_rerun_pass"]:
            errors.append("research_pass_threshold_invalid")
        if request["authorized_content_scope"]["mode"] in {"c3_6c_single_slug", "c3_6d_batch"} and counts["unresolved"] != 0:
            errors.append("c3_6c_d_unresolved_must_be_zero")
    if receipt["batch_risk"] in {"regulated", "ymyl_high"} and gate_by_name.get("research", {}).get("state") == "PASS" and counts.get("sensitive_claims_without_tier_1_2", 0) != 0:
        errors.append("sensitive_claim_tier_invalid")
    if "evidence_adapter" in gate_names and gates[2]["state"] == "PASS":
        if counts["evidence_contracts_passed"] != 6 or set(receipt["evidence_contract_versions"]) != EVIDENCE_CONTRACTS or counts["required_compiler_claim_coverage_percent"] != 100 or not counts["evidence_deterministic_rerun_pass"]:
            errors.append("evidence_pass_threshold_invalid")
        if not counts.get("loader_cohort_pass") or not counts.get("loader_single_slug_pass"):
            errors.append("evidence_loader_pass_missing")
    if "evidence_adapter" not in gate_names:
        if receipt["evidence_contract_versions"] or counts["evidence_contracts_passed"] != 0 or counts["loader_cohort_pass"] or counts["loader_single_slug_pass"] or counts["evidence_deterministic_rerun_pass"] or counts["required_compiler_claim_coverage_percent"] != 0 or receipt["dimension_binding"]["adapter_rows"] != 0:
            errors.append("unexecuted_evidence_metrics_must_be_zero")
    if "dry_compile" in gate_names and gates[3]["state"] == "PASS":
        if (counts["dry_compile_source_files"], counts["dry_compile_locale_projections"], counts["components_per_page"], counts["dry_compile_blockers"]) != (10, 2, 28, 0) or counts["candidate_rows"] != len(publishable) or receipt["dry_compile_status"] != "PASS_TEN_BLOCK_DRY_COMPILE" or not counts["dry_compile_deterministic_rerun_pass"]:
            errors.append("dry_compile_pass_threshold_invalid")
        if receipt["artifact_hashes"]["dry_compile_candidate"] is None:
            errors.append("candidate_row_digest_missing")
    if "dry_compile" not in gate_names:
        if receipt["dry_compile_status"] is not None or any(counts[key] != 0 for key in ("dry_compile_source_files", "dry_compile_locale_projections", "components_per_page", "dry_compile_blockers", "candidate_rows")) or counts["dry_compile_deterministic_rerun_pass"]:
            errors.append("unexecuted_dry_compile_metrics_must_be_zero")
    slug_results = receipt["slug_results"]
    if sorted(row["slug"] for row in slug_results) != sorted(request["slugs"]) or len({row["slug"] for row in slug_results}) != len(slug_results):
        errors.append("slug_results_must_match_request")
    editorial_pass = sorted(row["slug"] for row in slug_results if row["editorial_state"] == "PASS")
    if sorted(publishable) != editorial_pass or (final == "ORCHESTRATED" and not publishable):
        errors.append("publishable_slug_set_invalid")
    if gate_by_name.get("evidence_adapter", {}).get("state") == "PASS":
        if any(row["evidence_adapter_state"] != "PASS" or row["evidence_package_digest"] is None for row in slug_results if row["slug"] in publishable):
            errors.append("per_slug_evidence_results_incomplete")
        elif any(row["evidence_adapter_state"] != "NOT_RUN" or row["evidence_package_digest"] is not None for row in slug_results if row["slug"] not in publishable):
            errors.append("isolated_slug_evidence_forbidden")
        elif len({row["evidence_package_digest"] for row in slug_results if row["slug"] in publishable}) != len(publishable):
            errors.append("per_slug_evidence_digests_must_be_distinct")
        elif receipt["artifact_hashes"]["evidence_package"] != evidence_aggregate_hash(slug_results):
            errors.append("evidence_aggregate_hash_mismatch")
    if gate_by_name.get("dry_compile", {}).get("state") == "PASS":
        if counts["candidate_rows"] != len(publishable) or any(row["dry_compile_state"] != "PASS" or row["candidate_row_digest"] is None for row in slug_results if row["slug"] in publishable):
            errors.append("per_slug_compile_results_incomplete")
        elif any(row["dry_compile_state"] != "NOT_RUN" or row["candidate_row_digest"] is not None for row in slug_results if row["slug"] not in publishable):
            errors.append("isolated_slug_compile_forbidden")
        elif len({row["candidate_row_digest"] for row in slug_results if row["slug"] in publishable}) != len(publishable):
            errors.append("per_slug_candidate_digests_must_be_distinct")
        elif receipt["artifact_hashes"]["dry_compile_candidate"] != dry_compile_aggregate_hash(slug_results):
            errors.append("dry_compile_aggregate_hash_mismatch")
    if "evidence_adapter" not in gate_names and any(row["evidence_adapter_state"] != "NOT_RUN" or row["evidence_package_digest"] is not None for row in slug_results):
        errors.append("late_evidence_result_forbidden")
    if "dry_compile" not in gate_names and any(row["dry_compile_state"] != "NOT_RUN" or row["candidate_row_digest"] is not None for row in slug_results):
        errors.append("late_dry_compile_result_forbidden")
    if counts.get("auto_rewrite_attempts", 0) != 0:
        errors.append("automatic_rewrite_forbidden")
    if counts.get("dimension_mismatches", 0) != 0:
        errors.append("locale_market_jurisdiction_mismatch")
    dimensions = receipt["dimension_binding"]
    if dimensions["mismatch_count"] != 0 or (final == "ORCHESTRATED" and min(dimensions["claim_rows"], dimensions["source_rows"], dimensions["adapter_rows"]) < 1):
        errors.append("dimension_binding_invalid")
    if counts.get("misrouted_unmapped_claims", 0) != 0:
        errors.append("unmapped_claim_reroute_forbidden")

    lifecycle = receipt["lifecycle"]
    if lifecycle["review_queue_count"] != lifecycle["review_due_soon"] + lifecycle["source_version_superseded"]:
        errors.append("lifecycle_review_queue_count_invalid")
    if len(lifecycle["supersession_bindings"]) != lifecycle["source_version_superseded"]:
        errors.append("supersession_registry_binding_missing")
    if lifecycle["expired"] and "dry_compile" in gate_names:
        errors.append("expired_evidence_compiled")
    if lifecycle["source_version_superseded"] and "dry_compile" in gate_names:
        errors.append("superseded_evidence_compiled")
    if any(receipt["write_counts"][key] != 0 for key in WRITE_KEYS):
        errors.append("non_target_write_detected")
    permissions = receipt["permissions"]
    if any(permissions.values()):
        errors.append("receipt_authority_forbidden")
    required_manual = receipt["batch_risk"] == "ymyl_high" and final == "MANUAL_REVIEW_REQUIRED"
    expected_manual_status = "required_pending" if required_manual else "not_required"
    if receipt["manual_review"]["required"] != required_manual or receipt["manual_review"]["status"] != expected_manual_status:
        errors.append("manual_review_flag_mismatch")
    return {"ok": not errors, "errors": sorted(set(errors))}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("kind", choices=["request", "receipt", "lifecycle"])
    parser.add_argument("document")
    parser.add_argument("--request")
    args = parser.parse_args(argv)
    if args.kind == "lifecycle":
        payload = load_json(Path(args.document))
        result = {"ok": True, "state": lifecycle_state(**payload)}
    elif args.kind == "request":
        result = validate_request(load_json(Path(args.document)))
    else:
        if not args.request:
            parser.error("receipt validation requires --request")
        result = validate_receipt(load_json(Path(args.document)), load_json(Path(args.request)))
    print(json.dumps(result, ensure_ascii=False, sort_keys=True, separators=(",", ":")))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
