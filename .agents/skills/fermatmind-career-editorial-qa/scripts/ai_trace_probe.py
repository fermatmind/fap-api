#!/usr/bin/env python3
"""Deterministic advisory scan for repetitive AI-style Chinese Career copy."""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

VERSION = "career.editorial.ai-trace.v1"
WARN_THRESHOLD = 60
BLOCK_THRESHOLD = 80

SIGNALS = {
    "formulaic_transition": (5, ["值得注意的是", "总而言之", "综上所述", "不难发现"]),
    "connector_pile": (3, ["然而", "此外", "与此同时", "另一方面"]),
    "vague_attribution": (8, ["研究表明", "专家认为", "众所周知", "有数据显示"]),
    "promotional_language": (6, ["颠覆", "划时代", "无与伦比", "一站式", "革命性"]),
    "inflated_significance": (4, ["至关重要", "不可磨灭", "深远意义", "标志着"]),
}
PATTERNS = {
    "three_part_formula": (10, re.compile(r"首先.{0,80}其次.{0,80}(?:最后|再次)")),
    "parallel_formula": (6, re.compile(r"(?:不仅.{0,40}而且|既.{0,40}又|不是.{0,40}而是)")),
    "dash_abuse": (4, re.compile(r"——+")),
}


def flatten(value: object) -> str:
    if isinstance(value, str):
        return value
    if isinstance(value, list):
        return "\n".join(flatten(item) for item in value)
    if isinstance(value, dict):
        return "\n".join(flatten(value[key]) for key in sorted(value))
    return ""


def read_text(path: Path) -> str:
    if path.suffix.lower() == ".json":
        return flatten(json.loads(path.read_text(encoding="utf-8")))
    return path.read_text(encoding="utf-8")


def analyze(text: str, source: str) -> dict[str, object]:
    findings: dict[str, dict[str, object]] = {}
    score = 0
    for name, (weight, phrases) in SIGNALS.items():
        matches = {phrase: text.count(phrase) for phrase in phrases if phrase in text}
        count = sum(matches.values())
        penalty = min(20, count * weight)
        findings[name] = {"count": count, "matches": matches, "penalty": penalty}
        score += penalty
    for name, (weight, pattern) in PATTERNS.items():
        matches = pattern.findall(text)
        count = len(matches)
        penalty = min(20, count * weight)
        findings[name] = {"count": count, "penalty": penalty}
        score += penalty
    score = min(100, score)
    verdict = "BLOCKED" if score >= BLOCK_THRESHOLD else "WARN" if score >= WARN_THRESHOLD else "PASS"
    return {
        "contract_version": VERSION,
        "source": source,
        "text_length": len(text),
        "score": score,
        "verdict": verdict,
        "thresholds": {"warn": WARN_THRESHOLD, "blocked": BLOCK_THRESHOLD},
        "findings": findings,
        "advisory_only": True,
        "generated_at": None,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--text")
    group.add_argument("--input", type=Path)
    parser.add_argument("--out", type=Path)
    args = parser.parse_args()

    if args.text is not None:
        report = analyze(args.text, "<text>")
    else:
        report = analyze(read_text(args.input), str(args.input))
    payload = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    if args.out:
        args.out.write_text(payload, encoding="utf-8")
    else:
        print(payload, end="")
    return {"PASS": 0, "WARN": 2, "BLOCKED": 1}[str(report["verdict"])]


if __name__ == "__main__":
    raise SystemExit(main())
