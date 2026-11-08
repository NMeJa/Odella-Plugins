#!/usr/bin/env python3
"""Owner-run PageSpeed Insights report. Do not run this from an agent session.

python tools/psi.py
python tools/psi.py <url> [<url> ...]

Default URLs are lines 1, 2 and 4 of tools/urls.txt (home, store, product).
Writes reports/psi-YYYYMMDD-HHMM.md and raw JSON under reports/raw/ (gitignored).
"""

from __future__ import annotations

import json
import sys
import urllib.parse
import urllib.request
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
URLS_PATH = ROOT / "tools" / "urls.txt"
ENV_PATH = ROOT / ".env"
REPORTS = ROOT / "reports"
RAW = REPORTS / "raw"
API = "https://www.googleapis.com/pagespeedonline/v5/runPagespeed"
AUDITS = (
    ("first-contentful-paint", "FCP"),
    ("largest-contentful-paint", "LCP"),
    ("total-blocking-time", "TBT"),
    ("cumulative-layout-shift", "CLS"),
    ("speed-index", "Speed Index"),
    ("server-response-time", "Server response"),
    ("total-byte-weight", "Total bytes"),
    ("dom-size", "DOM size"),
)


def load_env() -> dict[str, str]:
    env: dict[str, str] = {}
    if not ENV_PATH.is_file():
        return env
    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
    return env


def default_urls() -> list[str]:
    lines = [
        line.strip()
        for line in URLS_PATH.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.strip().startswith("#")
    ]
    picked = []
    for number in (1, 2, 4):
        if number <= len(lines):
            picked.append(lines[number - 1])
    return picked


def run_pagespeed(url: str, strategy: str, key: str) -> dict:
    query = urllib.parse.urlencode(
        {"url": url, "strategy": strategy, "category": "performance", "key": key}
    )
    with urllib.request.urlopen(f"{API}?{query}", timeout=120) as resp:
        return json.loads(resp.read().decode("utf-8"))


def audit_map(payload: dict) -> dict:
    return (payload.get("lighthouseResult") or {}).get("audits") or {}


def display(audits: dict, audit_id: str) -> str:
    audit = audits.get(audit_id) or {}
    value = audit.get("displayValue")
    if value:
        return str(value)
    numeric = audit.get("numericValue")
    return "n/a" if numeric is None else str(numeric)


def score(payload: dict) -> str:
    raw = (
        ((payload.get("lighthouseResult") or {}).get("categories") or {})
        .get("performance", {})
        .get("score")
    )
    if raw is None:
        return "n/a"
    return str(int(round(float(raw) * 100)))


def grouped(payload: dict, group: str) -> list[dict]:
    result = payload.get("lighthouseResult") or {}
    audits = result.get("audits") or {}
    refs = ((result.get("categories") or {}).get("performance") or {}).get("auditRefs") or []
    rows = []
    for ref in refs:
        if ref.get("group") != group:
            continue
        audit = audits.get(ref.get("id")) or {}
        details = audit.get("details") or {}
        if details.get("overallSavingsMs"):
            savings, unit = details["overallSavingsMs"], "ms"
        elif details.get("overallSavingsBytes"):
            savings, unit = details["overallSavingsBytes"], "bytes"
        else:
            savings, unit = 0, "ms"
        urls = []
        for item in details.get("items") or []:
            item_url = item.get("url")
            if item_url:
                urls.append(item_url)
            if len(urls) == 3:
                break
        rows.append(
            {"title": audit.get("title") or ref.get("id"), "savings": savings or 0, "unit": unit, "urls": urls}
        )
    rows.sort(key=lambda row: row["savings"], reverse=True)
    return rows[:5]


def lcp_snippet(audits: dict) -> str:
    details = (audits.get("largest-contentful-paint-element") or {}).get("details") or {}
    for item in details.get("items") or []:
        snippet = ((item.get("node") or {}).get("snippet")) or item.get("snippet")
        if snippet:
            return " ".join(str(snippet).split())
    return "n/a"


def savings_label(value, unit: str) -> str:
    if unit == "bytes":
        return f"{int(value)} bytes"
    if isinstance(value, (int, float)) and value >= 1000:
        return f"{value / 1000:.1f} s"
    return f"{value:.0f} ms" if isinstance(value, (int, float)) else str(value)


def write_report(stamp: str, results: list[tuple[str, dict, dict]]) -> Path:
    lines = [f"# PageSpeed {stamp}", ""]
    for url, mobile, desktop in results:
        lines.append(f"## {url}")
        lines.append("")
        lines.append("| Metric | Mobile | Desktop |")
        lines.append("|---|---:|---:|")
        lines.append(f"| Score | {score(mobile)} | {score(desktop)} |")
        mobile_audits = audit_map(mobile)
        desktop_audits = audit_map(desktop)
        for audit_id, label in AUDITS:
            lines.append(
                f"| {label} | {display(mobile_audits, audit_id)} | {display(desktop_audits, audit_id)} |"
            )
        lines.append("")
        lines.append(f"LCP element (mobile): `{lcp_snippet(mobile_audits)}`")
        lines.append("")
        lines.append("Top opportunities / diagnostics by savings (mobile):")
        rows = grouped(mobile, "opportunities") + grouped(mobile, "diagnostics")
        rows.sort(key=lambda row: row["savings"], reverse=True)
        for row in rows[:5]:
            extra = ", ".join(row["urls"]) if row["urls"] else "no URL"
            lines.append(f"- {row['title']}: {savings_label(row['savings'], row['unit'])} — {extra}")
        lines.append("")
    path = REPORTS / f"psi-{stamp}.md"
    path.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")
    return path


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    key = load_env().get("PSI_API_KEY", "").strip()
    if not key:
        print("PSI_API_KEY is missing from .env")
        raise SystemExit(1)
    urls = sys.argv[1:] or default_urls()
    if not urls:
        print(f"No URLs. Pass some, or fill {URLS_PATH}")
        raise SystemExit(1)
    REPORTS.mkdir(parents=True, exist_ok=True)
    RAW.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d-%H%M")
    results = []
    raw: dict[str, dict] = {}
    for url in urls:
        print(f"Measuring {url}")
        mobile = run_pagespeed(url, "mobile", key)
        desktop = run_pagespeed(url, "desktop", key)
        results.append((url, mobile, desktop))
        raw[url] = {"mobile": mobile, "desktop": desktop}
        print(f"  mobile {score(mobile)}  desktop {score(desktop)}")
    raw_path = RAW / f"psi-{stamp}.json"
    raw_path.write_text(json.dumps(raw), encoding="utf-8")
    report = write_report(stamp, results)
    text = report.read_text(encoding="utf-8")
    print(f"Wrote {report} ({len(text.splitlines())} lines)")
    print(f"Raw JSON: {raw_path}")


if __name__ == "__main__":
    main()
