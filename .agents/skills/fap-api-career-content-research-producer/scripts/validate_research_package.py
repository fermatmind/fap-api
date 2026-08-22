#!/usr/bin/env python3
"""Fail-closed validator for temporary Career research candidate packages."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import tempfile
from datetime import date
from pathlib import Path
from typing import Any, Iterable
from urllib.parse import urlparse


VALIDATOR_VERSION = "career.research-package-validator.v1"
MODULE_FILES = (
    "identity.json",
    "definition.json",
    "salary.json",
    "geo.json",
    "ai-impact.json",
    "fit-personality.json",
    "risk.json",
    "compare-links.json",
    "faq.json",
    "page-meta.json",
)
MODULE_NAMES = tuple(name.removesuffix(".json") for name in MODULE_FILES)
SOURCE_SCOPES = {
    "exact",
    "combined_official",
    "parent_occupation_proxy",
    "industry_proxy",
    "recruitment_proxy",
    "market_signal",
    "internal_rubric",
    "editorial_synthesis",
}
TRANSFORMATIONS = {
    "verbatim_fact",
    "normalized",
    "calculated",
    "combined_official",
    "parent_proxy",
    "industry_proxy",
    "market_signal",
    "internal_rubric",
    "editorial_synthesis",
}
PROXY_TRANSFORM_SCOPE = {
    "parent_proxy": {"parent_occupation_proxy"},
    "industry_proxy": {"industry_proxy"},
    "market_signal": {"recruitment_proxy", "market_signal"},
    "internal_rubric": {"internal_rubric"},
    "editorial_synthesis": {"editorial_synthesis", "internal_rubric"},
    "combined_official": {"combined_official"},
}
NON_TARGET_WRITE_KEYS = {
    "repository",
    "current_package",
    "zh_master",
    "english_assets",
    "fap_web",
    "runtime_api",
    "cms_db_cache",
    "discoverability_search",
    "automation",
    "agent_definitions",
}
PACKAGE_ENTRIES = {
    "careers",
    "source-registry.jsonl",
    "claim-bindings.jsonl",
    "module-coverage.json",
    "unresolved-claims.json",
    "research-receipt.json",
}
SENSITIVE_KEY = re.compile(
    r"(?:salary|wage|pay|median|growth|employment|opening|income|rate|license|licence|certification|qualification)",
    re.IGNORECASE,
)
DATE_VALUE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
SLUG = re.compile(r"^[a-z0-9]+(?:-[a-z0-9]+)*$")
HEX64 = re.compile(r"^[0-9a-f]{64}$")


def canonical_json(value: Any) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def candidate_tree_sha256(package_root: Path) -> str:
    rows = []
    for path in sorted((package_root / "careers").glob("*/*.json")):
        if path.name in MODULE_FILES and path.is_file():
            rows.append(
                {
                    "path": path.relative_to(package_root).as_posix(),
                    "sha256": sha256_file(path),
                }
            )
    return sha256_bytes(canonical_json(rows))


def parse_iso_date(value: Any) -> date | None:
    if not isinstance(value, str):
        return None
    try:
        return date.fromisoformat(value)
    except ValueError:
        return None


def decode_pointer_token(token: str) -> str:
    return token.replace("~1", "/").replace("~0", "~")


def resolve_json_pointer(document: Any, pointer: str) -> Any:
    if pointer == "":
        return document
    if not isinstance(pointer, str) or not pointer.startswith("/"):
        raise KeyError(pointer)
    value = document
    for raw_token in pointer[1:].split("/"):
        token = decode_pointer_token(raw_token)
        if isinstance(value, dict) and token in value:
            value = value[token]
        elif isinstance(value, list) and token.isdigit() and int(token) < len(value):
            value = value[int(token)]
        else:
            raise KeyError(pointer)
    return value


def pointer_token(value: str) -> str:
    return value.replace("~", "~0").replace("/", "~1")


def leaf_claim_pointers(value: Any, pointer: str = "") -> Iterable[str]:
    if isinstance(value, dict):
        for key, child in value.items():
            child_pointer = f"{pointer}/{pointer_token(str(key))}"
            if isinstance(child, bool) or child is None:
                continue
            if isinstance(child, (int, float)):
                yield child_pointer
            elif isinstance(child, str):
                is_process_date = key in {"review_date", "reviewed_at", "retrieved_at"}
                if SENSITIVE_KEY.search(str(key)) or (DATE_VALUE.fullmatch(child) and not is_process_date):
                    yield child_pointer
            else:
                yield from leaf_claim_pointers(child, child_pointer)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            yield from leaf_claim_pointers(child, f"{pointer}/{index}")


def find_values_for_key(value: Any, key: str) -> list[Any]:
    found: list[Any] = []
    if isinstance(value, dict):
        for item_key, child in value.items():
            if item_key == key:
                found.append(child)
            found.extend(find_values_for_key(child, key))
    elif isinstance(value, list):
        for child in value:
            found.extend(find_values_for_key(child, key))
    return found


class ResearchPackageValidator:
    def __init__(self, package_root: Path):
        self.package_root = package_root.resolve()
        self.errors: list[str] = []
        self.warnings: list[str] = []
        self.receipt: dict[str, Any] = {}
        self.sources: list[dict[str, Any]] = []
        self.source_by_key: dict[str, dict[str, Any]] = {}
        self.claims: list[dict[str, Any]] = []
        self.modules: dict[tuple[str, str], dict[str, Any]] = {}
        self.expired_source_count = 0

    def error(self, code: str) -> None:
        if code not in self.errors:
            self.errors.append(code)

    def load_json(self, relative: str, expected: type | tuple[type, ...]) -> Any:
        path = self.package_root / relative
        try:
            value = json.loads(path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            self.error(f"missing_file:{relative}")
            return expected() if isinstance(expected, type) else {}
        except (OSError, UnicodeDecodeError, json.JSONDecodeError):
            self.error(f"invalid_json:{relative}")
            return expected() if isinstance(expected, type) else {}
        if not isinstance(value, expected):
            self.error(f"invalid_shape:{relative}")
            return expected() if isinstance(expected, type) else {}
        return value

    def load_jsonl(self, relative: str) -> list[dict[str, Any]]:
        path = self.package_root / relative
        rows: list[dict[str, Any]] = []
        try:
            lines = path.read_text(encoding="utf-8").splitlines()
        except (FileNotFoundError, OSError, UnicodeDecodeError):
            self.error(f"missing_or_unreadable_file:{relative}")
            return rows
        for number, line in enumerate(lines, 1):
            if not line.strip():
                self.error(f"blank_jsonl_row:{relative}:{number}")
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError:
                self.error(f"invalid_jsonl:{relative}:{number}")
                continue
            if not isinstance(row, dict):
                self.error(f"invalid_jsonl_shape:{relative}:{number}")
                continue
            rows.append(row)
        return rows

    def validate_path_boundary(self) -> None:
        repo_root = Path(__file__).resolve().parents[4]
        temp_root = Path(tempfile.gettempdir()).resolve()
        try:
            self.package_root.relative_to(repo_root)
            self.error("package_inside_repository")
        except ValueError:
            pass
        try:
            self.package_root.relative_to(temp_root)
        except ValueError:
            self.error("package_outside_system_temp")
        output_root_value = self.receipt.get("output_root")
        if not isinstance(output_root_value, str) or not output_root_value:
            self.error("receipt_output_root_missing")
            return
        output_root = Path(output_root_value).expanduser().resolve()
        if not output_root.is_dir():
            self.error("receipt_output_root_not_existing_directory")
        if output_root != self.package_root.parent:
            self.error("receipt_output_root_mismatch")
        forbidden_parts = {"current", "career-en-translation", "zh-master", "zh_master", "中文母版"}
        if any(part.lower() in forbidden_parts for part in output_root.parts):
            self.error("forbidden_output_authority_path")
        if {path.name for path in self.package_root.iterdir()} != PACKAGE_ENTRIES:
            self.error("package_entry_set_invalid")
        if any(path.is_symlink() for path in self.package_root.rglob("*")):
            self.error("package_symlink_forbidden")

    def validate_receipt_contract(self) -> None:
        required = {
            "schema_version",
            "validator_version",
            "batch_id",
            "slugs",
            "locales",
            "jurisdiction",
            "research_as_of",
            "source_policy_version",
            "output_root",
            "authorized_content_scope",
            "canonical_slugs",
            "counts",
            "hashes",
            "non_target_writes",
        }
        if not required.issubset(self.receipt):
            self.error("receipt_required_fields_missing")
        if self.receipt.get("schema_version") != "career.research-receipt.v1":
            self.error("receipt_schema_version_mismatch")
        if self.receipt.get("validator_version") != VALIDATOR_VERSION:
            self.error("validator_version_mismatch")
        batch_id = self.receipt.get("batch_id")
        if not isinstance(batch_id, str) or not batch_id or batch_id != self.package_root.name:
            self.error("batch_id_path_mismatch")
        slugs = self.receipt.get("slugs")
        if not isinstance(slugs, list) or not slugs or any(not isinstance(item, str) or not SLUG.fullmatch(item) for item in slugs):
            self.error("receipt_slugs_invalid")
        elif len(slugs) != len(set(slugs)):
            self.error("receipt_slugs_duplicate")
        locales = self.receipt.get("locales")
        if not isinstance(locales, list) or not locales or any(not isinstance(item, str) or not item for item in locales):
            self.error("receipt_locales_invalid")
        jurisdiction = self.receipt.get("jurisdiction")
        if not isinstance(jurisdiction, dict) or not jurisdiction.get("primary"):
            self.error("receipt_jurisdiction_invalid")
        if parse_iso_date(self.receipt.get("research_as_of")) is None:
            self.error("research_as_of_invalid")
        if not isinstance(self.receipt.get("source_policy_version"), str) or not self.receipt.get("source_policy_version"):
            self.error("source_policy_version_missing")
        if self.receipt.get("authorized_content_scope") not in {"new_content", "explicit_update", "research_only"}:
            self.error("authorized_content_scope_invalid")
        canonical_slugs = self.receipt.get("canonical_slugs")
        if not isinstance(canonical_slugs, list) or not canonical_slugs or any(not isinstance(item, str) or not SLUG.fullmatch(item) for item in canonical_slugs):
            self.error("canonical_slug_set_invalid")
        elif isinstance(slugs, list) and not set(slugs).issubset(set(canonical_slugs)):
            self.error("target_slug_not_canonical")
        writes = self.receipt.get("non_target_writes")
        if not isinstance(writes, dict) or not NON_TARGET_WRITE_KEYS.issubset(writes):
            self.error("non_target_write_counts_missing")
        elif any(type(writes[key]) is not int or writes[key] != 0 for key in NON_TARGET_WRITE_KEYS):
            self.error("non_target_write_detected")

    def validate_sources(self) -> None:
        required = {
            "source_key",
            "publisher",
            "title",
            "url",
            "source_tier",
            "source_type",
            "jurisdiction",
            "retrieved_at",
            "data_year",
            "effective_at",
            "reviewed_at",
            "valid_through",
            "content_sha256",
            "scope",
        }
        research_as_of = parse_iso_date(self.receipt.get("research_as_of"))
        allowed_jurisdictions = {self.receipt.get("jurisdiction", {}).get("primary")}
        comparison = self.receipt.get("jurisdiction", {}).get("comparison", [])
        if isinstance(comparison, list):
            allowed_jurisdictions.update(comparison)
        for index, source in enumerate(self.sources, 1):
            label = f"source:{index}"
            if not required.issubset(source):
                self.error(f"source_required_fields_missing:{index}")
            key = source.get("source_key")
            if not isinstance(key, str) or not key:
                self.error(f"source_key_invalid:{index}")
            elif key in self.source_by_key:
                self.error(f"duplicate_source_key:{key}")
            else:
                self.source_by_key[key] = source
            parsed_url = urlparse(source.get("url") if isinstance(source.get("url"), str) else "")
            if parsed_url.scheme != "https" or not parsed_url.netloc:
                self.error(f"source_url_invalid:{index}")
            tier = source.get("source_tier")
            if type(tier) is not int or tier not in range(1, 6):
                self.error(f"source_tier_invalid:{index}")
            scope = source.get("scope")
            if scope not in SOURCE_SCOPES:
                self.error(f"source_scope_invalid:{index}")
            if tier == 4:
                if scope not in {"recruitment_proxy", "market_signal"}:
                    self.error(f"tier4_scope_invalid:{index}")
                if source.get("source_type") not in {"market_signal", "job_posting_sample", "recruitment_proxy"}:
                    self.error(f"tier4_source_type_invalid:{index}")
                if not isinstance(source.get("collection_query"), str) or not source.get("collection_query"):
                    self.error(f"tier4_query_missing:{index}")
                if type(source.get("sample_size")) is not int or source.get("sample_size", 0) <= 0:
                    self.error(f"tier4_sample_size_invalid:{index}")
            if tier == 5:
                if scope not in {"internal_rubric", "editorial_synthesis"}:
                    self.error(f"tier5_scope_invalid:{index}")
                if source.get("source_type") not in {"internal_rubric", "editorial_synthesis", "conditional_guidance"}:
                    self.error(f"tier5_source_type_invalid:{index}")
            source_jurisdiction = source.get("jurisdiction")
            if source_jurisdiction not in allowed_jurisdictions and not (
                tier == 5 and source_jurisdiction == "GLOBAL"
            ):
                self.error(f"source_jurisdiction_out_of_scope:{index}")
            if not isinstance(source.get("data_year"), int) and source.get("data_year") != "N/A":
                self.error(f"source_data_year_invalid:{index}")
            if not isinstance(source.get("content_sha256"), str) or not HEX64.fullmatch(source["content_sha256"]):
                self.error(f"source_content_hash_invalid:{index}")
            effective = parse_iso_date(source.get("effective_at"))
            reviewed = parse_iso_date(source.get("reviewed_at"))
            valid_through = parse_iso_date(source.get("valid_through"))
            retrieved = parse_iso_date(source.get("retrieved_at"))
            if None in {effective, reviewed, valid_through, retrieved}:
                self.error(f"source_date_invalid:{index}")
            elif not (effective <= reviewed <= valid_through):
                self.error(f"source_review_validity_invalid:{index}")
            if research_as_of is not None and (
                (reviewed is not None and reviewed > research_as_of)
                or (retrieved is not None and retrieved > research_as_of)
            ):
                self.error(f"source_research_date_boundary_invalid:{index}")
            if research_as_of is not None and valid_through is not None and valid_through < research_as_of:
                self.expired_source_count += 1
                self.error(f"expired_source:{key or label}")

    def validate_modules(self) -> None:
        slugs = self.receipt.get("slugs") if isinstance(self.receipt.get("slugs"), list) else []
        careers_root = self.package_root / "careers"
        actual_slugs = sorted(path.name for path in careers_root.iterdir() if path.is_dir()) if careers_root.is_dir() else []
        if set(actual_slugs) != set(slugs):
            self.error("career_directory_set_mismatch")
        for slug in slugs:
            career_root = careers_root / slug
            actual_files = sorted(path.name for path in career_root.glob("*.json")) if career_root.is_dir() else []
            actual_entries = sorted(path.name for path in career_root.iterdir()) if career_root.is_dir() else []
            if actual_files != sorted(MODULE_FILES) or actual_entries != sorted(MODULE_FILES):
                self.error(f"module_set_invalid:{slug}")
            for filename in MODULE_FILES:
                module_name = filename.removesuffix(".json")
                document = self.load_json(f"careers/{slug}/{filename}", dict)
                self.modules[(slug, module_name)] = document
                if document.get("slug") != slug:
                    self.error(f"module_slug_mismatch:{slug}:{module_name}")
                locale = document.get("locale")
                locales = self.receipt.get("locales", [])
                if isinstance(locale, str):
                    locale_ok = locale in locales and len(locales) == 1
                else:
                    locale_ok = isinstance(locale, list) and set(locale) == set(locales)
                if not locale_ok:
                    self.error(f"module_locale_invalid:{slug}:{module_name}")
                module_jurisdiction = document.get("jurisdiction")
                primary_jurisdiction = self.receipt.get("jurisdiction", {}).get("primary")
                if isinstance(module_jurisdiction, str):
                    jurisdiction_ok = module_jurisdiction == primary_jurisdiction
                else:
                    jurisdiction_ok = (
                        isinstance(module_jurisdiction, dict)
                        and module_jurisdiction.get("primary") == primary_jurisdiction
                    )
                if not jurisdiction_ok:
                    self.error(f"module_jurisdiction_missing:{slug}:{module_name}")

            identity = self.modules.get((slug, "identity"), {})
            is_combined = identity.get("combined_occupation") is True or identity.get("occupation_scope") == "combined_official"
            if is_combined:
                child_codes = identity.get("onet_child_codes")
                if not isinstance(child_codes, list) or len(set(child_codes)) < 2:
                    self.error(f"combined_occupation_child_codes_invalid:{slug}")

    def validate_claims(self) -> None:
        binding_pointers: set[tuple[str, str, str]] = set()
        allowed_locales = set(self.receipt.get("locales", []))
        allowed_jurisdictions = {self.receipt.get("jurisdiction", {}).get("primary")}
        comparison = self.receipt.get("jurisdiction", {}).get("comparison", [])
        if isinstance(comparison, list):
            allowed_jurisdictions.update(comparison)
        parent_proxy_slugs: set[str] = set()
        internal_rubric_slugs: set[str] = set()
        for index, claim in enumerate(self.claims, 1):
            required = {
                "slug",
                "locale",
                "module",
                "json_pointer",
                "claim_type",
                "source_keys",
                "transformation",
                "jurisdiction",
                "as_of",
                "review_status",
            }
            if not required.issubset(claim):
                self.error(f"claim_required_fields_missing:{index}")
                continue
            slug = claim.get("slug")
            module = claim.get("module")
            pointer = claim.get("json_pointer")
            if (slug, module) not in self.modules:
                self.error(f"claim_target_invalid:{index}")
                continue
            if claim.get("locale") not in allowed_locales:
                self.error(f"claim_locale_invalid:{index}")
            if claim.get("jurisdiction") not in allowed_jurisdictions:
                self.error(f"claim_jurisdiction_invalid:{index}")
            if not isinstance(claim.get("as_of"), (str, int)) or str(claim.get("as_of")) == "":
                self.error(f"claim_as_of_invalid:{index}")
            if claim.get("review_status") != "reviewed":
                self.error(f"claim_not_reviewed:{index}")
            try:
                resolve_json_pointer(self.modules[(slug, module)], pointer)
            except KeyError:
                self.error(f"claim_json_pointer_missing:{index}")
            if isinstance(pointer, str):
                binding_pointers.add((slug, module, pointer))
            transformation = claim.get("transformation")
            if transformation not in TRANSFORMATIONS:
                self.error(f"claim_transformation_invalid:{index}")
            source_keys = claim.get("source_keys")
            if not isinstance(source_keys, list) or any(not isinstance(item, str) for item in source_keys):
                self.error(f"claim_source_keys_invalid:{index}")
                source_keys = []
            missing_keys = [key for key in source_keys if key not in self.source_by_key]
            if missing_keys:
                self.error(f"claim_source_key_missing:{index}")
            external_source_optional = transformation == "editorial_synthesis" and claim.get("claim_type") in {
                "editorial_synthesis",
                "conditional_guidance",
            }
            if not source_keys and not external_source_optional:
                self.error(f"claim_source_required:{index}")
            source_scopes = {self.source_by_key[key].get("scope") for key in source_keys if key in self.source_by_key}
            if transformation in {"verbatim_fact", "normalized", "calculated"} and source_keys and source_scopes != {"exact"}:
                self.error(f"proxy_presented_as_exact:{index}")
            if transformation in PROXY_TRANSFORM_SCOPE and source_keys:
                if not source_scopes.issubset(PROXY_TRANSFORM_SCOPE[transformation]):
                    self.error(f"claim_source_scope_mismatch:{index}")
            if transformation == "calculated":
                inputs = claim.get("input_source_keys")
                if not isinstance(claim.get("formula"), str) or not claim.get("formula"):
                    self.error(f"calculated_formula_missing:{index}")
                if not isinstance(inputs, list) or not inputs or any(key not in self.source_by_key for key in inputs):
                    self.error(f"calculated_inputs_invalid:{index}")
                elif not set(inputs).issubset(set(source_keys)):
                    self.error(f"calculated_inputs_unbound:{index}")
            claim_jurisdiction = claim.get("jurisdiction")
            if any(
                self.source_by_key[key].get("jurisdiction") not in {claim_jurisdiction, "GLOBAL"}
                for key in source_keys
                if key in self.source_by_key
            ):
                self.error(f"claim_source_jurisdiction_mismatch:{index}")
            if transformation == "parent_proxy":
                parent_proxy_slugs.add(slug)
            if transformation == "internal_rubric":
                internal_rubric_slugs.add(slug)

        for (slug, module), document in self.modules.items():
            for pointer in leaf_claim_pointers(document):
                if (slug, module, pointer) not in binding_pointers:
                    self.error(f"sensitive_claim_unbound:{slug}:{module}:{pointer}")

        for slug in parent_proxy_slugs:
            salary = self.modules.get((slug, "salary"), {})
            names = [value for value in find_values_for_key(salary, "parent_occupation_name") if isinstance(value, str) and value.strip()]
            if not names:
                self.error(f"parent_proxy_name_missing:{slug}")
        for slug in internal_rubric_slugs:
            ai_impact = self.modules.get((slug, "ai-impact"), {})
            versions = [value for value in find_values_for_key(ai_impact, "rubric_version") if isinstance(value, str) and value.strip()]
            if not versions:
                self.error(f"ai_rubric_version_missing:{slug}")

    def validate_links(self) -> None:
        canonical = set(self.receipt.get("canonical_slugs", []))
        for slug in self.receipt.get("slugs", []):
            document = self.modules.get((slug, "compare-links"), {})
            for target in find_values_for_key(document, "target_slug"):
                if not isinstance(target, str) or target not in canonical:
                    self.error(f"non_canonical_compare_link:{slug}:{target}")

    def validate_coverage_and_unresolved(self) -> tuple[dict[str, Any], list[dict[str, Any]]]:
        coverage = self.load_json("module-coverage.json", dict)
        unresolved = self.load_json("unresolved-claims.json", list)
        if coverage.get("schema_version") != "career.research-module-coverage.v1":
            self.error("module_coverage_schema_version_mismatch")
        rows = coverage.get("modules") if isinstance(coverage, dict) else None
        expected = {(slug, module) for slug in self.receipt.get("slugs", []) for module in MODULE_NAMES}
        actual: set[tuple[str, str]] = set()
        if not isinstance(rows, list):
            self.error("module_coverage_rows_invalid")
            rows = []
        claim_counts: dict[tuple[str, str], int] = {}
        for claim in self.claims:
            identity = (claim.get("slug"), claim.get("module"))
            claim_counts[identity] = claim_counts.get(identity, 0) + 1
        unresolved_counts: dict[tuple[str, str], int] = {}
        if isinstance(unresolved, list):
            for item in unresolved:
                if isinstance(item, dict):
                    identity = (item.get("slug"), item.get("module"))
                    unresolved_counts[identity] = unresolved_counts.get(identity, 0) + 1
        for index, row in enumerate(rows, 1):
            required = {"slug", "module", "populated_field_count", "bound_claim_count", "unresolved_claim_count"}
            if not isinstance(row, dict) or not required.issubset(row):
                self.error(f"module_coverage_row_invalid:{index}")
                continue
            identity = (row.get("slug"), row.get("module"))
            if identity in actual:
                self.error(f"module_coverage_duplicate:{identity[0]}:{identity[1]}")
            actual.add(identity)
            for key in ("populated_field_count", "bound_claim_count", "unresolved_claim_count"):
                if type(row.get(key)) is not int or row[key] < 0:
                    self.error(f"module_coverage_count_invalid:{index}:{key}")
            document = self.modules.get(identity, {})
            expected_row_counts = {
                "populated_field_count": sum(value is not None for value in document.values()),
                "bound_claim_count": claim_counts.get(identity, 0),
                "unresolved_claim_count": unresolved_counts.get(identity, 0),
            }
            for key, expected_count in expected_row_counts.items():
                if row.get(key) != expected_count:
                    self.error(f"module_coverage_count_mismatch:{index}:{key}")
        if actual != expected:
            self.error("module_coverage_set_mismatch")

        for index, item in enumerate(unresolved, 1):
            required = {"slug", "locale", "module", "json_pointer", "reason", "status"}
            if not isinstance(item, dict) or not required.issubset(item):
                self.error(f"unresolved_claim_invalid:{index}")
                continue
            if item.get("status") != "blocker":
                self.error(f"unresolved_claim_not_blocker:{index}")
            if (item.get("slug"), item.get("module")) not in expected:
                self.error(f"unresolved_claim_target_invalid:{index}")
            if item.get("locale") not in self.receipt.get("locales", []):
                self.error(f"unresolved_claim_locale_invalid:{index}")
            if not item.get("reason"):
                self.error(f"unresolved_claim_reason_missing:{index}")
        if unresolved:
            self.error("unresolved_claim_blockers_present")
        return coverage, unresolved

    def validate_counts_and_hashes(self, unresolved: list[dict[str, Any]]) -> None:
        counts = self.receipt.get("counts")
        hashes = self.receipt.get("hashes")
        if not isinstance(counts, dict):
            self.error("receipt_counts_invalid")
            counts = {}
        expected_counts = {
            "slug_count": len(self.receipt.get("slugs", [])),
            "locale_count": len(self.receipt.get("locales", [])),
            "module_count": len(self.receipt.get("slugs", [])) * len(MODULE_FILES),
            "source_count": len(self.sources),
            "claim_count": len(self.claims),
            "unresolved_count": len(unresolved),
            "expired_source_count": self.expired_source_count,
        }
        for key, expected in expected_counts.items():
            if counts.get(key) != expected:
                self.error(f"receipt_count_mismatch:{key}")
        if not isinstance(hashes, dict):
            self.error("receipt_hashes_invalid")
            hashes = {}
        expected_hashes = {
            "source_registry_sha256": sha256_file(self.package_root / "source-registry.jsonl")
            if (self.package_root / "source-registry.jsonl").is_file()
            else None,
            "claim_bindings_sha256": sha256_file(self.package_root / "claim-bindings.jsonl")
            if (self.package_root / "claim-bindings.jsonl").is_file()
            else None,
            "candidate_tree_sha256": candidate_tree_sha256(self.package_root),
        }
        for key, expected in expected_hashes.items():
            if hashes.get(key) != expected:
                self.error(f"receipt_hash_mismatch:{key}")

    def validate(self) -> dict[str, Any]:
        if not self.package_root.is_dir():
            self.error("package_root_missing")
            return self.report()
        self.receipt = self.load_json("research-receipt.json", dict)
        self.validate_receipt_contract()
        self.validate_path_boundary()
        self.sources = self.load_jsonl("source-registry.jsonl")
        self.claims = self.load_jsonl("claim-bindings.jsonl")
        self.validate_sources()
        self.validate_modules()
        self.validate_claims()
        self.validate_links()
        _, unresolved = self.validate_coverage_and_unresolved()
        self.validate_counts_and_hashes(unresolved)
        return self.report()

    def report(self) -> dict[str, Any]:
        return {
            "ok": not self.errors,
            "validator_version": VALIDATOR_VERSION,
            "package_root": str(self.package_root),
            "counts": {
                "modules": len(self.modules),
                "sources": len(self.sources),
                "claims": len(self.claims),
                "expired_sources": self.expired_source_count,
            },
            "errors": sorted(self.errors),
            "warnings": sorted(self.warnings),
        }


def validate_package(package_root: str | Path) -> dict[str, Any]:
    return ResearchPackageValidator(Path(package_root)).validate()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("package_root", type=Path)
    args = parser.parse_args()
    result = validate_package(args.package_root)
    print(json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    sys.exit(main())
