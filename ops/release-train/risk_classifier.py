"""Risk classifier for release train scope and manifest drift checks."""

from __future__ import annotations

import fnmatch
import os


class RiskPattern:
    def __init__(self, risk_level: str, pattern: str, reason: str):
        self.risk_level = risk_level
        self.pattern = pattern
        self.reason = reason


RISK_PATTERNS = [
    # High risk
    RiskPattern("high", "backend/database/**", "Database schema and migration surface"),
    RiskPattern("high", "**/migrations/**", "Database migration file changes"),
    RiskPattern("high", "**/auth/**", "Authentication surface"),
    RiskPattern("high", "**/Auth/**", "Authentication surface"),
    RiskPattern("high", "**/session/**", "Session/auth cookie surface"),
    RiskPattern("high", "**/permission/**", "Permission/role surface"),
    RiskPattern("high", "**/Payment/**", "Payment processing surface"),
    RiskPattern("high", "**/payment/**", "Payment processing surface"),
    RiskPattern("high", "**/Order/**", "Order/commerce exposure"),
    RiskPattern("high", "**/order/**", "Order/commerce exposure"),
    RiskPattern("high", "**/entitlement/**", "Entitlement surface"),
    RiskPattern("high", "**/user-data/**", "User data handling"),
    RiskPattern("high", "**/env/**", "Environment/secret related area"),
    RiskPattern("high", "**/nginx/**", "Web server/proxy config"),
    RiskPattern("high", "deploy.php", "Deployment recipe"),
    RiskPattern("high", ".github/workflows/deploy.yml", "Production deploy workflow"),
    RiskPattern("high", "backend/scripts/deploy/**", "Deploy script surface"),
    RiskPattern("high", "**/queue/**", "Queue/scheduler runtime surface"),
    RiskPattern("high", "**/horizon/**", "Queue/scheduler runtime surface"),
    RiskPattern("high", "**/search-channel/**", "Search Channel surface"),
    RiskPattern("high", "**/search_channel/**", "Search Channel surface"),
    RiskPattern("high", "**/url_submission/**", "URL submission surface"),
    RiskPattern("high", "**/clinical_depression/**", "Clinical content surface"),
    RiskPattern("high", "*depression*clinical*", "Clinical/depression exposure"),
    RiskPattern("high", "*software-developers*", "Held slug surface"),
    RiskPattern("high", "**/career/raw/**", "Raw career asset exposure"),
    RiskPattern("high", "**/route:cache/**", "Route cache/build tooling path"),
    RiskPattern("high", "**/config:cache/**", "Config cache/build tooling path"),

    # Medium risk
    RiskPattern("medium", "**/sitemap/**", "Sitemap changes"),
    RiskPattern("medium", "**/sitemap*.php", "Sitemap changes"),
    RiskPattern("medium", "**/*llms*.php", "LLMS exposure surface"),
    RiskPattern("medium", "**/canonical**", "Canonical / SEO metadata surface"),
    RiskPattern("medium", "**/hreflang**", "Hreflang surface"),
    RiskPattern("medium", "**/trust**", "Trust pages"),
    RiskPattern("medium", "backend/app/Http/Controllers/API/**", "Public API changes"),
    RiskPattern("medium", "backend/routes/api.php", "Public API route changes"),
    RiskPattern("medium", "backend/app/Services/SEO/**", "SEO service changes"),
    RiskPattern("medium", "lib/seo/**", "SEO surface"),
    RiskPattern("medium", "**/schema/**", "API/schema surface"),
    RiskPattern("medium", "**/FAQ**", "Contract doc and API behavior changes"),
    RiskPattern("medium", "**/evidence/**", "Evidence/publish behavior"),
]


def _normalize(path: str) -> str:
    return os.path.normpath(path).replace("\\", "/").lower()


def classify_path(path: str) -> dict:
    normalized = _normalize(path)
    matched = []

    for item in RISK_PATTERNS:
        if item.risk_level == "high":
            if fnmatch.fnmatch(normalized, item.pattern):
                matched.append(item)
        else:
            if fnmatch.fnmatch(normalized, item.pattern):
                matched.append(item)

    if matched:
        highest = "medium"
        if any(item.risk_level == "high" for item in matched):
            highest = "high"
        elif any(item.risk_level == "medium" for item in matched):
            highest = "medium"
        reasons = [item.reason for item in matched]
        return {
            "risk_level": highest,
            "matched_patterns": [item.pattern for item in matched],
            "manual_approval_required": highest == "high",
            "reasons": reasons,
        }

    return {
        "risk_level": "low",
        "matched_patterns": [],
        "manual_approval_required": False,
        "reasons": ["No declared risk patterns matched"],
    }


def classify_paths(paths: list[str]) -> dict:
    summary = {"high": [], "medium": [], "low": []}
    by_path = {}

    for raw in paths:
        result = classify_path(raw)
        by_path[raw] = result
        summary[result["risk_level"]].append(raw)

    return {
        "summary": summary,
        "by_path": by_path,
    }


def classify_manifest_item(item: dict) -> dict:
    paths = []
    paths.extend(item.get("allowed_files", []) or [])
    paths.extend(item.get("allowed_generated_paths", []) or [])

    classifications = classify_paths(paths)
    manual_required = False
    reasons = set()

    for data in classifications["by_path"].values():
        if data["manual_approval_required"]:
            manual_required = True
        reasons.update(data["reasons"])

    declared_risk = (item.get("risk_level") or "medium").lower()
    effective = declared_risk
    if manual_required:
        effective = "high"

    if declared_risk not in {"low", "medium", "high"}:
        declared_risk = "medium"
        effective = "medium"

    if declared_risk == "high":
        effective = "high"
        manual_required = True

    if declared_risk == "low" and effective == "high":
        reasons.add("Declared risk level raised by path profile")

    return {
        "declared_risk": declared_risk,
        "risk_level": effective,
        "manual_approval_required": manual_required or effective == "high",
        "matched_patterns": [p for values in classifications["by_path"].values() for p in values["matched_patterns"]],
        "reasons": sorted(reasons),
        "path_classifications": classifications["by_path"],
    }
