#!/usr/bin/env python3
"""Read-only public Enneagram search readiness scanner."""

from __future__ import annotations

import concurrent.futures
import datetime
import html
import json
import re
import time
import urllib.error
import urllib.request
from pathlib import Path


API = "https://api.fermatmind.com/api/v0.5/personality-content-assets"
HOST = "https://fermatmind.com"
USER_AGENT = "FermatMind-Enneagram-Readiness-Audit/1.0"


def fetch(url: str, timeout: int = 30) -> tuple[int, str, str]:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": USER_AGENT, "Accept": "text/html,application/json,text/plain,*/*"},
    )
    last_error = "unknown"
    for attempt in range(3):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.status, response.geturl(), response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, url, error.read().decode("utf-8", "replace")
        except Exception as error:  # noqa: BLE001 - evidence records the safe exception class only.
            last_error = type(error).__name__
            if attempt < 2:
                time.sleep(attempt + 1)
    return 0, url, last_error


def load_assets() -> list[dict]:
    assets: list[dict] = []
    for locale in ("en", "zh-CN"):
        status, _, body = fetch(f"{API}?framework=enneagram&locale={locale}&per_page=100")
        if status != 200:
            raise RuntimeError(f"public API failed for locale {locale}: HTTP {status}")
        payload = json.loads(body)
        if payload.get("pagination", {}).get("total") != 58:
            raise RuntimeError(f"expected 58 {locale} assets")
        assets.extend(payload["items"])
    return assets


def first_attribute(body: str, rel_or_name: str, attribute: str) -> str | None:
    patterns = (
        rf'<[^>]+(?:rel|name)=["\']{rel_or_name}["\'][^>]+{attribute}=["\']([^"\']+)',
        rf'<[^>]+{attribute}=["\']([^"\']+)["\'][^>]+(?:rel|name)=["\']{rel_or_name}["\']',
    )
    for pattern in patterns:
        match = re.search(pattern, body, re.IGNORECASE)
        if match:
            return html.unescape(match.group(1))
    return None


def alternate_languages(body: str) -> set[str]:
    return set(re.findall(r'hrefLang=["\']([^"\']+)', body, re.IGNORECASE))


def scan_asset(asset: dict, sitemap: str, llms: str, llms_full: str) -> dict:
    path = asset.get("canonical_path") or asset.get("canonical", {}).get("path")
    url = HOST + path
    status, final_url, body = fetch(url)
    canonical = first_attribute(body, "canonical", "href")
    robots = first_attribute(body, "robots", "content")
    robots = robots.lower().replace(" ", "") if robots else None
    languages = alternate_languages(body)
    faq = asset.get("faq") if isinstance(asset.get("faq"), list) else []
    links = asset.get("internal_links") if isinstance(asset.get("internal_links"), list) else []
    link_paths = [row.get("href") for row in links if isinstance(row, dict) and isinstance(row.get("href"), str)]
    faq_rendered = sum(
        1
        for row in faq
        if isinstance(row, dict) and html.escape(str(row.get("question", "")), quote=True) in body
    )
    links_rendered = sum(1 for link in link_paths if f'href="{html.escape(link, quote=True)}"' in body)
    source_ok = bool(
        asset.get("source_package")
        and re.fullmatch(r"[0-9a-f]{64}", str(asset.get("source_hash", "")))
        and asset.get("evidence_notes")
    )
    api_ok = (
        asset.get("launch_state") == "published"
        and asset.get("robots") == "index,follow"
        and asset.get("is_public") is True
        and asset.get("index_eligible") is True
        and asset.get("sitemap_eligible") is True
        and asset.get("llms_eligible") is False
        and asset.get("schema_runtime_eligible") is True
    )
    unsafe_path = bool(
        re.search(
            r"/(results?|orders?|payments?|pay|account|history|private)(/|$)|[?&](token|session|result_id|report_id|order_no)=",
            path,
            re.IGNORECASE,
        )
    )
    return {
        "path": path,
        "locale": asset.get("locale"),
        "entity_type": asset.get("entity_type"),
        "code": asset.get("code"),
        "http_status": status,
        "final_host_ok": final_url.startswith(HOST + "/"),
        "canonical_ok": canonical == url,
        "robots_ok": robots == "index,follow",
        "hreflang_en": "en" in languages,
        "hreflang_zh_cn": "zh-CN" in languages,
        "faq_expected": len(faq),
        "faq_rendered": faq_rendered,
        "faqpage_present": '"@type":"FAQPage"' in body,
        "internal_links_expected": len(link_paths),
        "internal_links_rendered": links_rendered,
        "api_authority_ok": api_ok,
        "source_provenance_present": source_ok,
        "sitemap_member": url in sitemap,
        "llms_member": url in llms,
        "llms_full_member": url in llms_full,
        "unsafe_public_path": unsafe_path,
        "search_queue_plan": "not_observed_production",
        "search_queue_duplicate_state": "not_observed_production",
    }


def main() -> None:
    print("loading_public_authority", flush=True)
    assets = load_assets()
    print(f"assets_loaded={len(assets)}", flush=True)
    sitemap_status, _, sitemap = fetch(HOST + "/sitemap.xml")
    llms_status, _, llms = fetch(HOST + "/llms.txt")
    llms_full_status, _, llms_full = fetch(HOST + "/llms-full.txt")
    print("scanning_runtime", flush=True)
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as executor:
        rows = list(executor.map(lambda asset: scan_asset(asset, sitemap, llms, llms_full), assets))
    print(f"runtime_rows={len(rows)}", flush=True)
    rows.sort(key=lambda row: (row["locale"], row["path"]))
    counts = {
        "target_count": len(rows),
        "unique_path_count": len({row["path"] for row in rows}),
        "http_200": sum(row["http_status"] == 200 for row in rows),
        "canonical_ok": sum(row["canonical_ok"] for row in rows),
        "robots_ok": sum(row["robots_ok"] for row in rows),
        "hreflang_pair_ok": sum(row["hreflang_en"] and row["hreflang_zh_cn"] for row in rows),
        "api_authority_ok": sum(row["api_authority_ok"] for row in rows),
        "source_provenance_present": sum(row["source_provenance_present"] for row in rows),
        "sitemap_members": sum(row["sitemap_member"] for row in rows),
        "faqpage_present": sum(row["faqpage_present"] for row in rows),
        "faq_expected": sum(row["faq_expected"] for row in rows),
        "faq_rendered": sum(row["faq_rendered"] for row in rows),
        "internal_links_expected": sum(row["internal_links_expected"] for row in rows),
        "internal_links_rendered": sum(row["internal_links_rendered"] for row in rows),
        "unsafe_public_paths": sum(row["unsafe_public_path"] for row in rows),
        "llms_members": sum(row["llms_member"] for row in rows),
        "llms_full_members": sum(row["llms_full_member"] for row in rows),
        "queue_plans_observed": 0,
    }
    public_contract_pass = (
        counts["target_count"] == 116
        and counts["unique_path_count"] == 116
        and all(
            counts[key] == 116
            for key in (
                "http_200",
                "canonical_ok",
                "robots_ok",
                "hreflang_pair_ok",
                "api_authority_ok",
                "source_provenance_present",
                "sitemap_members",
                "faqpage_present",
            )
        )
        and counts["faq_expected"] == counts["faq_rendered"]
        and counts["internal_links_expected"] == counts["internal_links_rendered"]
        and counts["unsafe_public_paths"] == 0
    )
    issues = [] if public_contract_pass else ["public_surface_contract_incomplete"]
    issues.append("production_search_queue_plan_and_duplicate_state_not_observed")
    report = {
        "schema_version": "enneagram-search-release-readiness.v1",
        "pr_id": "ENNEAGRAM-SEARCH-RELEASE-READINESS-01",
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "authority": {
            "api": API,
            "public_host": HOST,
            "target_set": "58 en + 58 zh-CN published Enneagram API assets",
        },
        "final_decision": "NO_GO_SEARCH_RELEASE",
        "decision_reason": "Public discoverability is evaluated independently, but production IndexNow queue planning and duplicate state are not safely observable from the approved report-only scope.",
        "counts": counts,
        "surface_status": {
            "sitemap_http_status": sitemap_status,
            "llms_http_status": llms_status,
            "llms_full_http_status": llms_full_status,
            "public_contract_pass": public_contract_pass,
        },
        "search_queue": {
            "channel": "indexnow",
            "mode": "read_only_assessment",
            "production_plan_observed": False,
            "duplicate_state_observed": False,
            "blocker": "No approved read-only production queue execution surface is available in this report-only scope.",
            "writes_attempted": False,
            "writes_committed": False,
            "enqueue_attempted": False,
            "enqueue_committed": False,
            "approve_attempted": False,
            "external_calls_attempted": False,
            "search_submission_attempted": False,
        },
        "negative_guarantees": {
            "database_write": False,
            "cms_write": False,
            "search_queue_write": False,
            "search_queue_approve": False,
            "search_submission": False,
            "external_search_api_call": False,
            "llms_release": False,
            "cache_warm": False,
            "deploy": False,
        },
        "issues": issues,
        "recommended_next_action": "Add or authorize a separate non-deploy read-only production queue-inspection surface, then rerun this gate before any enqueue authorization.",
        "targets": rows,
    }
    output = Path(__file__).with_name("readiness.json")
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"decision": report["final_decision"], "counts": counts, "public_contract_pass": public_contract_pass, "issues": issues}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
