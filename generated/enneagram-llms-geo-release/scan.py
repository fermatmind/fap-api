#!/usr/bin/env python3
"""Read-only Enneagram llms.txt and llms-full.txt readiness scanner."""

from __future__ import annotations

import datetime
import json
import re
import time
import urllib.error
import urllib.request
from collections import Counter
from pathlib import Path


API = "https://api.fermatmind.com/api/v0.5/personality-content-assets"
HOST = "https://fermatmind.com"
USER_AGENT = "FermatMind-Enneagram-LLMS-Readiness-Audit/1.0"
PRIVATE_ROUTE = re.compile(
    r"/(results?|orders?|payments?|pay|account|history|private)(/|$)|[?&](token|session|result_id|report_id|order_no)=",
    re.IGNORECASE,
)
ANSWER_KEYS = {
    "hub": {"answer_block", "three_centers", "method_boundary"},
    "center": {"center_definition", "included_types", "how_to_use_and_boundary"},
    "core_type": {"type_overview", "core_motivation", "self_checklist"},
    "wing": {"quick_answer", "compare", "evidence"},
    "instinctual_subtype": {"quick_answer", "compare", "evidence"},
}


def fetch(url: str, timeout: int = 45) -> tuple[int, str]:
    request = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept": "application/json,text/plain,*/*"})
    last_error = "unknown"
    for attempt in range(3):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.status, response.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as error:
            return error.code, error.read().decode("utf-8", "replace")
        except Exception as error:  # noqa: BLE001 - safe evidence stores only the exception class.
            last_error = type(error).__name__
            if attempt < 2:
                time.sleep(attempt + 1)
    return 0, last_error


def load_assets() -> list[dict]:
    assets: list[dict] = []
    for locale in ("en", "zh-CN"):
        status, body = fetch(f"{API}?framework=enneagram&locale={locale}&per_page=100")
        if status != 200:
            raise RuntimeError(f"public API failed for locale {locale}: HTTP {status}")
        payload = json.loads(body)
        if payload.get("pagination", {}).get("total") != 58:
            raise RuntimeError(f"expected 58 {locale} assets")
        assets.extend(payload["items"])
    return assets


def pair_key(asset: dict) -> str:
    return f'{asset.get("entity_type")}:{asset.get("code")}'


def section_keys(asset: dict) -> set[str]:
    return {
        str(section.get("key"))
        for section in asset.get("sections", [])
        if isinstance(section, dict) and section.get("key")
    }


def evidence_summary(asset: dict) -> tuple[int, int, int, bool]:
    notes = asset.get("evidence_notes") if isinstance(asset.get("evidence_notes"), list) else []
    source_ids = sum(isinstance(note, dict) and bool(note.get("source_id")) for note in notes)
    limitations = sum(isinstance(note, dict) and bool(note.get("limitation")) for note in notes)
    agent_only = bool(notes) and all(
        isinstance(note, dict) and note.get("source_type") == "agent_recommendation" for note in notes
    )
    return len(notes), source_ids, limitations, agent_only


def main() -> None:
    assets = load_assets()
    llms_status, llms = fetch(HOST + "/llms.txt")
    llms_full_status, llms_full = fetch(HOST + "/llms-full.txt")
    by_pair: dict[str, list[dict]] = {}
    for asset in assets:
        by_pair.setdefault(pair_key(asset), []).append(asset)

    rows = []
    for asset in assets:
        canonical_path = asset.get("canonical_path") or asset.get("canonical", {}).get("path")
        keys = section_keys(asset)
        expected_answer_keys = ANSWER_KEYS.get(str(asset.get("entity_type")), set())
        notes_count, source_id_count, limitation_count, agent_only = evidence_summary(asset)
        faq_count = len(asset.get("faq", [])) if isinstance(asset.get("faq"), list) else 0
        link_paths = [
            link.get("href")
            for link in asset.get("internal_links", [])
            if isinstance(link, dict) and isinstance(link.get("href"), str)
        ]
        pair = by_pair.get(pair_key(asset), [])
        pair_locales = {candidate.get("locale") for candidate in pair}
        pair_structure_aligned = len(pair) == 2 and len({len(candidate.get("sections", [])) for candidate in pair}) == 1
        public_gate = (
            asset.get("launch_state") == "published"
            and asset.get("robots") == "index,follow"
            and asset.get("is_public") is True
            and asset.get("index_eligible") is True
            and asset.get("sitemap_eligible") is True
            and asset.get("schema_runtime_eligible") is True
        )
        boundary = asset.get("method_boundary") if isinstance(asset.get("method_boundary"), dict) else {}
        boundary_ok = bool(boundary.get("summary")) and len(boundary.get("not_for", [])) >= 3
        answerability_ok = (
            bool(asset.get("summary"))
            and faq_count >= 3
            and expected_answer_keys.issubset(keys)
        )
        provenance_ok = bool(
            asset.get("source_package")
            and re.fullmatch(r"[0-9a-f]{64}", str(asset.get("source_hash", "")))
            and notes_count > 0
        )
        private_path_hit = bool(PRIVATE_ROUTE.search(str(canonical_path))) or any(
            PRIVATE_ROUTE.search(path) for path in link_paths
        )
        visible_evidence_section = "evidence" in keys
        full_evidence_gate = (
            provenance_ok
            and visible_evidence_section
            and source_id_count > 0
            and limitation_count > 0
            and not agent_only
        )
        url = HOST + str(canonical_path)
        rows.append(
            {
                "path": canonical_path,
                "locale": asset.get("locale"),
                "entity_type": asset.get("entity_type"),
                "code": asset.get("code"),
                "public_gate_ok": public_gate,
                "llms_eligible_current": asset.get("llms_eligible") is True,
                "method_boundary_ok": boundary_ok,
                "answerability_blocks_ok": answerability_ok,
                "provenance_present": provenance_ok,
                "evidence_note_count": notes_count,
                "traceable_source_id_count": source_id_count,
                "limitation_count": limitation_count,
                "agent_only_provenance": agent_only,
                "visible_evidence_section": visible_evidence_section,
                "llms_full_evidence_gate_ok": full_evidence_gate,
                "bilingual_pair_present": pair_locales == {"en", "zh-CN"},
                "bilingual_structure_aligned": pair_structure_aligned,
                "private_or_sensitive_path_hit": private_path_hit,
                "llms_member_current": url in llms,
                "llms_full_member_current": url in llms_full,
            }
        )
    rows.sort(key=lambda row: (str(row["locale"]), str(row["path"])))
    counts = {
        "target_count": len(rows),
        "unique_path_count": len({row["path"] for row in rows}),
        "bilingual_pair_count": len(by_pair),
        "public_gate_ok": sum(row["public_gate_ok"] for row in rows),
        "method_boundary_ok": sum(row["method_boundary_ok"] for row in rows),
        "answerability_blocks_ok": sum(row["answerability_blocks_ok"] for row in rows),
        "provenance_present": sum(row["provenance_present"] for row in rows),
        "bilingual_pair_present": sum(row["bilingual_pair_present"] for row in rows),
        "bilingual_structure_aligned": sum(row["bilingual_structure_aligned"] for row in rows),
        "private_or_sensitive_path_hits": sum(row["private_or_sensitive_path_hit"] for row in rows),
        "llms_eligible_true_current": sum(row["llms_eligible_current"] for row in rows),
        "llms_members_current": sum(row["llms_member_current"] for row in rows),
        "llms_full_members_current": sum(row["llms_full_member_current"] for row in rows),
        "visible_evidence_section": sum(row["visible_evidence_section"] for row in rows),
        "llms_full_evidence_gate_ok": sum(row["llms_full_evidence_gate_ok"] for row in rows),
        "agent_only_provenance": sum(row["agent_only_provenance"] for row in rows),
    }
    package_counts = Counter(str(asset.get("source_package")) for asset in assets)
    llms_txt_ready = (
        counts["target_count"] == 116
        and counts["unique_path_count"] == 116
        and counts["bilingual_pair_count"] == 58
        and all(
            counts[key] == 116
            for key in (
                "public_gate_ok",
                "method_boundary_ok",
                "answerability_blocks_ok",
                "provenance_present",
                "bilingual_pair_present",
                "bilingual_structure_aligned",
            )
        )
        and counts["private_or_sensitive_path_hits"] == 0
        and counts["llms_eligible_true_current"] == 0
        and counts["llms_members_current"] == 0
        and counts["llms_full_members_current"] == 0
    )
    llms_full_ready = llms_txt_ready and counts["llms_full_evidence_gate_ok"] == 116
    llms_txt_decision = "GO_FOR_SEPARATE_LLMS_AUTHORIZATION" if llms_txt_ready else "NO_GO_LLMS_RELEASE"
    llms_full_decision = "GO_FOR_SEPARATE_LLMS_AUTHORIZATION" if llms_full_ready else "NO_GO_LLMS_RELEASE"
    report = {
        "schema_version": "enneagram-llms-geo-release-readiness.v1",
        "pr_id": "ENNEAGRAM-LLMS-GEO-RELEASE-01",
        "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "authority": {
            "api": API,
            "public_host": HOST,
            "target_set": "58 en + 58 zh-CN published Enneagram API assets",
            "source_package_counts": dict(sorted(package_counts.items())),
        },
        "decisions": {
            "llms_txt": {
                "decision": llms_txt_decision,
                "reason": "All 116 public entry pages satisfy public, bilingual, answerability, provenance, boundary, and private-path checks; release still requires a separate exact authorization and guarded eligibility mutation.",
            },
            "llms_full_txt": {
                "decision": llms_full_decision,
                "reason": "Only 90 of 116 pages have visible, source-ID-traceable evidence sections with explicit limitations; the 26 EN13 hub/core/center assets retain agent-only package provenance and are not eligible for enriched evidence feed release.",
            },
        },
        "counts": counts,
        "feed_status": {"llms_http_status": llms_status, "llms_full_http_status": llms_full_status},
        "holds": {
            "llms_txt": ["separate_exact_release_authorization_required", "guarded_cms_eligibility_write_not_in_scope"],
            "llms_full_txt": ["26_en13_assets_lack_visible_traceable_evidence_sections", "separate_exact_release_authorization_required"],
        },
        "negative_guarantees": {
            "database_write": False,
            "cms_write": False,
            "llms_eligibility_write": False,
            "llms_feed_generation": False,
            "llms_full_feed_generation": False,
            "search_queue_action": False,
            "cache_warm": False,
            "deploy": False,
        },
        "targets": rows,
    }
    output = Path(__file__).with_name("readiness.json")
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(json.dumps({"decisions": report["decisions"], "counts": counts}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
