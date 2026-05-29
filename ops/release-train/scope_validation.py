"""Scope validation utilities for release-train item files."""

from __future__ import annotations

import fnmatch
import os
import sys
from pathlib import Path

CURRENT_DIR = Path(__file__).resolve().parent
if str(CURRENT_DIR) not in sys.path:
    sys.path.insert(0, str(CURRENT_DIR))
    
from risk_classifier import classify_paths


def _norm(path: str) -> str:
    return path.replace("\\", "/")


def _matches(pattern: str, path: str) -> bool:
    return fnmatch.fnmatch(path, pattern)


def find_unexpected_files(changed_files: list[str], allowed_files: list[str], allowed_generated_paths: list[str]) -> list[str]:
    allowed_patterns = [p for p in allowed_files or []]
    generated_patterns = [p for p in allowed_generated_paths or []]

    unexpected = []
    for item in changed_files:
        path = _norm(item)
        if any(_matches(pattern, path) for pattern in allowed_patterns):
            continue
        if any(_matches(pattern, path) for pattern in generated_patterns):
            continue
        unexpected.append(path)

    return sorted(unexpected)


def detect_high_risk_out_of_scope(changed_files: list[str], allowed_files: list[str], allowed_generated_paths: list[str]) -> list[str]:
    allowed_patterns = [p for p in allowed_files or []]
    generated_patterns = [p for p in allowed_generated_paths or []]
    result = []

    classified = classify_paths(changed_files)
    for path in changed_files:
        norm = _norm(path)
        if any(_matches(pattern, norm) for pattern in allowed_patterns):
            continue
        if any(_matches(pattern, norm) for pattern in generated_patterns):
            continue
        if classified["by_path"][path]["risk_level"] == "high":
            result.append(path)

    return result


def validate_changed_files(changed_files: list[str], allowed_files: list[str], allowed_generated_paths: list[str]) -> dict:
    changed_files = [ _norm(item) for item in (changed_files or []) ]
    allowed_files = [ _norm(item) for item in (allowed_files or []) ]
    allowed_generated_paths = [ _norm(item) for item in (allowed_generated_paths or []) ]

    if changed_files is None:
        changed_files = []

    if not allowed_files:
        unexpected = changed_files
        return {
            "ok": False,
            "unexpected_files": unexpected,
            "generated_files": [],
            "high_risk_files": unexpected if unexpected else [],
            "warnings": ["allowed_files is empty; explicit scope is required"],
        }

    unexpected = find_unexpected_files(changed_files, allowed_files, allowed_generated_paths)
    generated_files = [
        path for path in changed_files
        if any(_matches(pattern, path) for pattern in allowed_generated_paths)
    ]
    high_risk_files = detect_high_risk_out_of_scope(changed_files, allowed_files, allowed_generated_paths)

    return {
        "ok": len(unexpected) == 0,
        "unexpected_files": unexpected,
        "generated_files": sorted(set(generated_files)),
        "high_risk_files": sorted(set(high_risk_files)),
        "warnings": [],
    }
