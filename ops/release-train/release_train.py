#!/usr/bin/env python3
"""Release train orchestrator implementation."""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Any

from risk_classifier import classify_manifest_item
from scope_validation import validate_changed_files
from github_client import GitHubClient
from smoke import run_smoke_checks, has_forbidden_content
from sidecar import classify_check_failure, build_sidecar_payload


CURRENT_DIR = Path(__file__).resolve().parent
if str(CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(CURRENT_DIR))


MANDATORY_FIELDS = {
    "schema_version": str,
    "train_id": str,
    "environment": str,
    "mode": str,
    "stop_on_failure": bool,
    "rollback_on_failed_smoke": bool,
    "allow_merge": bool,
    "allow_deploy": bool,
    "allow_rollback": bool,
    "items": list,
}


ALLOWED_MODES = {"plan_only", "dry_run", "deploy_with_approval", "auto_low_risk"}
ALLOWED_REPOS = {"fap-api", "fap-web"}
ALLOWED_COMPONENTS = {"backend", "frontend", "both", "docs", "ops"}
ALLOWED_RISK = {"low", "medium", "high"}
ALLOWED_DEPLOY_ORDER = {"backend_first", "frontend_first", "none"}
ALLOWED_CHECK_POLICIES = {"required_only", "all_checks"}


def _load_json(path: str) -> dict:
    with open(path, "r", encoding="utf-8") as handle:
        return json.load(handle)


def _bool_from_str(value: str) -> bool:
    return str(value).lower() in {"1", "true", "yes", "on"}


def validate_schema(manifest: dict) -> tuple[bool, list[str]]:
    errors = []

    for key, value_type in MANDATORY_FIELDS.items():
        if key not in manifest:
            errors.append(f"missing field: {key}")
            continue
        if key != "items" and not isinstance(manifest[key], value_type):
            errors.append(f"field {key} type invalid")

    if errors:
        return False, errors

    if manifest["mode"] not in ALLOWED_MODES:
        errors.append("mode invalid")

    if not manifest["train_id"]:
        errors.append("train_id empty")

    if manifest["environment"] not in {"production", "staging", "demo", "local"}:
        errors.append("environment invalid")

    items = manifest.get("items") or []
    if not isinstance(items, list):
        errors.append("items must be an array")
        return False, errors

    required_item_fields = {
        "id",
        "repo",
        "pr_number",
        "expected_head_sha",
        "component",
        "risk_level",
        "deploy_required",
        "deploy_order",
        "required_checks_policy",
        "allowed_files",
        "allowed_generated_paths",
        "scope_validation",
        "smoke_checks",
        "rollback",
        "sidecar_policy",
    }

    for index, item in enumerate(items, start=1):
        if not isinstance(item, dict):
            errors.append(f"item[{index}] is not an object")
            continue
        for key in sorted(required_item_fields):
            if key not in item:
                errors.append(f"item[{index}] missing field {key}")
        if item.get("repo") not in ALLOWED_REPOS:
            errors.append(f"item[{index}] has unsupported repo {item.get('repo')}")
        if item.get("component") not in ALLOWED_COMPONENTS:
            errors.append(f"item[{index}] has unsupported component {item.get('component')}")
        if item.get("risk_level") not in ALLOWED_RISK:
            errors.append(f"item[{index}] has unsupported risk_level {item.get('risk_level')}")
        if item.get("deploy_order") not in ALLOWED_DEPLOY_ORDER:
            errors.append(f"item[{index}] has unsupported deploy_order {item.get('deploy_order')}")
        if item.get("required_checks_policy") not in ALLOWED_CHECK_POLICIES:
            errors.append(f"item[{index}] has unsupported required_checks_policy {item.get('required_checks_policy')}")

    return len(errors) == 0, errors


def load_and_validate_manifest(manifest_path: str) -> dict:
    manifest = _load_json(manifest_path)
    ok, errors = validate_schema(manifest)
    if not ok:
        raise ValueError("manifest validation failed: " + "; ".join(errors))
    return manifest


def plan_actions(manifest: dict) -> dict:
    deploy_order_sort = {"backend_first": 0, "frontend_first": 1, "none": 2}
    actions = []
    items = sorted(
        manifest.get("items", []),
        key=lambda item: (deploy_order_sort.get(item.get("deploy_order", "none"), 2), item.get("id", "")),
    )
    for idx, item in enumerate(items, start=1):
        actions.append({
            "index": idx,
            "id": item.get("id"),
            "repo": item.get("repo"),
            "action": "deploy" if item.get("deploy_required") else "observe",
            "risk_level": item.get("risk_level"),
            "expected_head_sha": item.get("expected_head_sha"),
            "deploy_order": item.get("deploy_order"),
            "scope_check": item.get("scope_validation", {}),
            "smoke_checks_count": len(item.get("smoke_checks", [])),
        })
    return {"train_id": manifest["train_id"], "items": actions}


def _run_command(cmd: list[str], env: dict[str, str] | None = None) -> dict:
    completed = subprocess.run(
        cmd,
        env=env,
        text=True,
        capture_output=True,
    )
    return {
        "returncode": completed.returncode,
        "stdout": completed.stdout,
        "stderr": completed.stderr,
    }


def _pr_matches(pr_data: dict, expected_sha: str) -> bool:
    if not pr_data:
        return False
    head = pr_data.get("head", {})
    if isinstance(head, dict):
        actual = head.get("sha")
        if actual:
            return str(actual) == str(expected_sha)
    if pr_data.get("head_sha"):
        return str(pr_data.get("head_sha")) == str(expected_sha)
    if pr_data.get("mergeCommit", {}).get("oid"):
        return str(pr_data.get("mergeCommit").get("oid")) == str(expected_sha)
    return False


def evaluate_item(
    item: dict,
    *,
    manifest: dict,
    github: GitHubClient,
    execute_smoke: bool = False,
    allow_deploy: bool = False,
    mode: str = "dry-run",
) -> dict:
    item_id = item.get("id")
    repo = item.get("repo")
    pr_number = item.get("pr_number")
    expected_sha = item.get("expected_head_sha", "")

    result = {
        "id": item_id,
        "repo": repo,
        "pr_number": str(pr_number),
        "expected_head_sha": expected_sha,
        "risk": classify_manifest_item(item),
        "checks": {},
        "scope": {},
        "smoke": [],
        "status": "ok",
        "failures": [],
        "sidecars": [],
    }

    pr_data = github.get_pull_request(f"fermatmind/{repo}", pr_number)
    if pr_data is None:
        result["checks"]["pr_exists"] = False
        result["checks"]["sha_matches"] = False
        if mode == "run":
            result["status"] = "blocked"
            result["failures"].append("pr_not_found")
        else:
            result["checks"]["pr_exists_note"] = "skipped_in_offline_mode"
    else:
        result["checks"]["pr_exists"] = True
        result["checks"]["sha_matches"] = _pr_matches(pr_data, expected_sha)
        if not result["checks"]["sha_matches"]:
            if mode == "run":
                result["status"] = "blocked"
                result["failures"].append("sha_mismatch")
            else:
                result["checks"]["sha_matches_note"] = "not_enforced_in_offline_mode"

    if manifest.get("allow_merge") and str(manifest.get("mode")).lower() == "auto_low_risk":
        result["checks"]["auto_merge"] = True
    else:
        result["checks"]["auto_merge"] = False

    # checks
    checks_map = github.get_required_checks(f"fermatmind/{repo}", expected_sha)
    if checks_map:
        if item.get("required_checks_policy") == "all_checks":
            all_ok = all(v == "success" for v in checks_map.values()) if checks_map else False
            result["checks"]["required"] = all_ok
            if not all_ok:
                result["status"] = "blocked"
                result["failures"].append("required_checks_failed")
        else:
            # required_only: only enforce green if there is a known gate check.
            check_value = checks_map.get("CI")
            if check_value is not None:
                result["checks"]["required"] = (check_value == "success")
                if result["checks"]["required"] is not True:
                    result["status"] = "blocked"
                    result["failures"].append("required_checks_failed")
            else:
                result["checks"]["required"] = None
    else:
        result["checks"]["required"] = None

    changed_files = github.get_pull_request_changed_files(f"fermatmind/{repo}", pr_number)
    scope = validate_changed_files(changed_files, item.get("allowed_files", []), item.get("allowed_generated_paths", []))
    result["scope"] = scope
    if not scope.get("ok"):
        result["status"] = "blocked"
        result["failures"].append("scope_validation_failed")
        if mode != "run":
            result["checks"]["scope_note"] = "not_enforced_in_offline_mode"
            result["status"] = "ok"

    if item.get("risk_level") == "high" and result["risk"]["manual_approval_required"]:
        result["checks"]["manual_approval_needed"] = True
        if str(mode) != "run":
            result["status"] = result["status"]

    if execute_smoke and item.get("smoke_checks"):
        smoke_results = run_smoke_checks(item.get("smoke_checks"))
        result["smoke"] = smoke_results
        failed_smoke = [entry for entry in smoke_results if not entry["success"]]
        if failed_smoke:
            result["status"] = "blocked"
            result["failures"].append("smoke_failed")
            for entry in failed_smoke:
                result["sidecars"].append(
                    classify_check_failure(
                        check_name=f"smoke:{entry['url']}",
                        required=True,
                        observed_failure="|".join(entry["errors"]),
                        is_core_smoke=True,
                    )
                )
        else:
            # content scan guard
            for entry in smoke_results:
                if entry["response_snippet"]:
                    hits = has_forbidden_content(entry["response_snippet"])
                    if hits:
                        result["status"] = "blocked"
                        result["failures"].append("forbidden_content_detected")
                        result["sidecars"].append(
                            classify_check_failure(
                                check_name=f"smoke:{entry['url']}",
                                required=True,
                                observed_failure="forbidden content: " + ",".join(hits),
                                is_core_smoke=True,
                                is_private_or_held_exposure=True,
                            )
                        )

    if item.get("deploy_required") and allow_deploy and mode == "run" and repo == "fap-api":
        cmd_env = dict(os.environ)
        cmd_env.update({
            "BACKEND_SHA": expected_sha,
            "RELEASE_NAME": item.get("id"),
            "DEPLOY_TARGET": item.get("deploy_target", "production"),
            "ALLOW_PRODUCTION_DEPLOY": _bool_from_str(os.environ.get("RELEASE_TRAIN_ALLOW_DEPLOY", "false")),
        })
        if "DEPLOY_COMMAND" in item:
            cmd_env["DEPLOY_COMMAND"] = item["DEPLOY_COMMAND"]
        deployment = _run_command(
            ["bash", str(CURRENT_DIR.parent.parent / "backend/scripts/deploy/deploy_backend.sh")],
            env=cmd_env,
        )
        result["checks"]["deploy_exec"] = deployment["returncode"] == 0
        result["checks"]["deploy_stdout"] = deployment["stdout"].strip()
        result["checks"]["deploy_stderr"] = deployment["stderr"].strip()
        if not result["checks"]["deploy_exec"]:
            result["status"] = "blocked"
            result["failures"].append("deploy_failed")
    elif item.get("deploy_required") and not allow_deploy:
        result["checks"]["deploy_exec"] = False
        result["checks"]["deploy_status"] = "deploy_skipped_in_non_run_mode"
        if mode == "run":
            result["status"] = "blocked"
            result["failures"].append("allow_deploy_disabled")

    if result["status"] == "blocked" and result["failures"]:
        for failure in result["failures"]:
            policy = (item.get("sidecar_policy") or {})
            allow_sidecar = policy.get("allow_nonblocking_sidecars", False)
            if allow_sidecar and failure in {"scope_validation_failed"}:
                payload = build_sidecar_payload(
                    train_id=manifest["train_id"],
                    pr_number=str(pr_number),
                    repo=repo,
                    component=item.get("component", ""),
                    check_name=failure,
                    observed_failure=failure,
                    why_nonblocking="non-blocking only when explicitly allowed and confirmed external",
                    recommended_followup="record sidecar issue and rerun after clearance",
                    owner="ops",
                    severity="medium",
                )
                result.setdefault("sidecar_records", []).append(payload)

    return result


def print_json(payload: Any, output: str | None = None) -> None:
    text = json.dumps(payload, indent=2, sort_keys=True)
    if output:
        out = Path(output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(text + "\n", encoding="utf-8")
    else:
        print(text)


def cmd_validate_manifest(args: argparse.Namespace) -> int:
    try:
        manifest = _load_json(args.manifest)
        ok, errors = validate_schema(manifest)
        payload = {"ok": ok, "errors": errors}
        if ok:
            print_json(payload, args.output)
            return 0
        print_json(payload, args.output)
        return 1
    except Exception as exc:
        print_json({"ok": False, "errors": [str(exc)]}, args.output)
        return 1


def cmd_plan(args: argparse.Namespace, github: GitHubClient) -> int:
    try:
        manifest = load_and_validate_manifest(args.manifest)
        if args.train_id:
            manifest["train_id"] = args.train_id
        manifest_result = {
            "train_id": manifest["train_id"],
            "environment": manifest["environment"],
            "mode": manifest["mode"],
            "actions": plan_actions(manifest)["items"],
        }
        print_json(manifest_result, args.output)
        return 0
    except Exception as exc:
        print_json({"ok": False, "errors": [str(exc)]}, args.output)
        return 1


def cmd_dry_run(args: argparse.Namespace, github: GitHubClient) -> int:
    try:
        manifest = load_and_validate_manifest(args.manifest)
        if args.train_id:
            manifest["train_id"] = args.train_id
        items = manifest.get("items", [])
        result_items = []
        for item in items:
            result_items.append(evaluate_item(
                item,
                manifest=manifest,
                github=github,
                execute_smoke=False,
                allow_deploy=False,
                mode="dry-run",
            ))
        failures = [item for item in result_items if item["status"] == "blocked"]
        payload = {
            "ok": len(failures) == 0,
            "mode": "dry-run",
            "items": result_items,
        }
        print_json(payload, args.output)
        return 0 if payload["ok"] else 1
    except Exception as exc:
        print_json({"ok": False, "errors": [str(exc)]}, args.output)
        return 1


def cmd_run(args: argparse.Namespace, github: GitHubClient) -> int:
    try:
        manifest = load_and_validate_manifest(args.manifest)
        if args.train_id:
            manifest["train_id"] = args.train_id
        allow_merge = _bool_from_str(args.allow_merge)
        allow_deploy = _bool_from_str(args.allow_deploy)
        allow_rollback = _bool_from_str(args.allow_rollback)
        os.environ["RELEASE_TRAIN_ALLOW_DEPLOY"] = "true" if allow_deploy else "false"
        os.environ["RELEASE_TRAIN_ALLOW_ROLLBACK"] = "true" if allow_rollback else "false"
        os.environ["RELEASE_TRAIN_ALLOW_MERGE"] = "true" if allow_merge else "false"

        items = manifest.get("items", [])
        result_items = []
        blocked = []
        for item in items:
            result = evaluate_item(
                item,
                manifest=manifest,
                github=github,
                execute_smoke=args.execute_smoke,
                allow_deploy=allow_deploy,
                mode="run",
            )
            result_items.append(result)
            if result["status"] == "blocked":
                blocked.append(result["id"])
                if manifest.get("stop_on_failure"):
                    break

        payload = {
            "train_id": manifest["train_id"],
            "mode": "run",
            "blocked_items": blocked,
            "items": result_items,
        }
        payload["ok"] = len(blocked) == 0
        print_json(payload, args.output)
        return 0 if payload["ok"] else 1
    except Exception as exc:
        print_json({"ok": False, "errors": [str(exc)]}, args.output)
        return 1


def cmd_resume(args: argparse.Namespace, github: GitHubClient) -> int:
    # v1: resume behaves as run with a state file anchor.
    state_file = Path(args.state_file or "ops/release-train/state/resume-state.json")
    if not state_file.exists():
        print_json({"ok": False, "errors": [f"state file not found: {state_file}"]}, args.output)
        return 1
    with state_file.open("r", encoding="utf-8") as handle:
        state = json.load(handle)
    next_index = int(state.get("next_index", 0))
    manifest = load_and_validate_manifest(args.manifest)
    items = manifest.get("items", [])
    if not items:
        print_json({"ok": False, "errors": ["manifest has no items"]}, args.output)
        return 1
    remaining = items[next_index:]
    result_items = []
    for item in remaining:
        result_items.append(
            evaluate_item(
                item,
                manifest=manifest,
                github=github,
                execute_smoke=args.execute_smoke,
                allow_deploy=_bool_from_str(args.allow_deploy),
                mode="run",
            )
        )
    payload = {"ok": True, "mode": "resume", "items": result_items}
    print_json(payload, args.output)
    return 0


def cmd_print_confirmation_phrases(args: argparse.Namespace) -> int:
    manifest = load_and_validate_manifest(args.manifest)
    phrases = []
    for item in manifest.get("items", []):
        if item.get("deploy_required"):
            phrases.append(
                f"I explicitly approve backend production deploy for SHA {item.get('expected_head_sha')} release {item.get('id')}."
                if item.get("repo") == "fap-api" else
                f"I explicitly approve frontend Node1 production deploy for SHA {item.get('expected_head_sha')} release {item.get('id')}."
            )
    print_json({"phrases": phrases}, args.output)
    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Release train orchestrator")
    parser.add_argument("command", choices=[
        "validate-manifest",
        "plan",
        "dry-run",
        "run",
        "resume",
        "print-confirmation-phrases",
    ])
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--train-id", dest="train_id", default=None)
    parser.add_argument("--output", default=None)
    parser.add_argument("--allow-merge", dest="allow_merge", default="false")
    parser.add_argument("--allow-deploy", dest="allow_deploy", default="false")
    parser.add_argument("--allow-rollback", dest="allow_rollback", default="false")
    parser.add_argument("--mock-github-fixture", dest="mock_github_fixture", default=None)
    parser.add_argument("--state-file", dest="state_file", default=None)
    parser.add_argument("--execute-smoke", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    github = GitHubClient(args.mock_github_fixture)

    if args.command == "validate-manifest":
        return cmd_validate_manifest(args)
    if args.command == "plan":
        return cmd_plan(args, github)
    if args.command == "dry-run":
        return cmd_dry_run(args, github)
    if args.command == "run":
        return cmd_run(args, github)
    if args.command == "resume":
        return cmd_resume(args, github)
    if args.command == "print-confirmation-phrases":
        return cmd_print_confirmation_phrases(args)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
