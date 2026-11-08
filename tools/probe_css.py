#!/usr/bin/env python3
"""Fetch the key URLs and check that every stylesheet and script responds.

Exit 1 when any asset returns 404. Other non-200 statuses are printed.
"""

from __future__ import annotations

import http.client
import re
import ssl
import sys
import urllib.parse
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
URLS_PATH = ROOT / "tools" / "urls.txt"
MOBILE_UA = (
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
    "AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)"
)
DESKTOP_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)"
LINK_RE = re.compile(r"<link\b[^>]*>", re.I)
SCRIPT_RE = re.compile(r"<script\b[^>]*\bsrc=[\"']([^\"']+)", re.I)
HREF_RE = re.compile(r"href=[\"']([^\"']+)", re.I)
STYLESHEET_RE = re.compile(r"rel=[\"'][^\"']*stylesheet", re.I)


def request(method: str, url: str, timeout: int = 60) -> tuple[int, bytes]:
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme not in ("http", "https"):
        raise ValueError(parsed.scheme)
    conn_cls = http.client.HTTPSConnection if parsed.scheme == "https" else http.client.HTTPConnection
    kwargs = {"timeout": timeout}
    if parsed.scheme == "https":
        kwargs["context"] = ssl.create_default_context()
    conn = conn_cls(parsed.hostname, parsed.port, **kwargs)
    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    conn.request(method, path, headers={"User-Agent": MOBILE_UA, "Accept": "*/*", "Accept-Encoding": "identity"})
    resp = conn.getresponse()
    body = resp.read() if method == "GET" else b""
    status = resp.status
    conn.close()
    return status, body


def page_html(url: str, user_agent: str) -> tuple[int, str]:
    parsed = urllib.parse.urlparse(url)
    conn = http.client.HTTPSConnection(parsed.hostname, timeout=60, context=ssl.create_default_context())
    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    conn.request(
        "GET",
        path,
        headers={"User-Agent": user_agent, "Accept": "text/html", "Accept-Encoding": "identity"},
    )
    resp = conn.getresponse()
    body = resp.read()
    status = resp.status
    conn.close()
    return status, body.decode("utf-8", "replace")


def absolutize(page: str, asset: str) -> str | None:
    asset = asset.strip()
    if not asset or asset.startswith(("data:", "blob:", "javascript:", "mailto:")):
        return None
    return urllib.parse.urljoin(page, asset)


def collect(page: str, html: str) -> set[str]:
    found: set[str] = set()
    for tag in LINK_RE.findall(html):
        if not STYLESHEET_RE.search(tag):
            continue
        match = HREF_RE.search(tag)
        if not match:
            continue
        url = absolutize(page, match.group(1))
        if url:
            found.add(url)
    for src in SCRIPT_RE.findall(html):
        url = absolutize(page, src)
        if url:
            found.add(url)
    return found


def asset_status(url: str) -> int:
    try:
        status, _body = request("HEAD", url)
    except Exception:
        status = 0
    if status == 200:
        return status
    try:
        status, _body = request("GET", url)
    except Exception:
        return status or 0
    return status


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    pages = [
        line.strip()
        for line in URLS_PATH.read_text(encoding="utf-8").splitlines()
        if line.strip() and not line.strip().startswith("#")
    ]
    assets: set[str] = set()
    for page in pages:
        for label, agent in (("mobile", MOBILE_UA), ("desktop", DESKTOP_UA)):
            status, html = page_html(page, agent)
            found = collect(page, html)
            assets.update(found)
            print(f"{status}  {label:7}  {len(found):3} assets  {page}")
    print(f"Checking {len(assets)} unique assets")
    missing = 0
    other = 0
    for url in sorted(assets):
        status = asset_status(url)
        if status == 404:
            missing += 1
            print(f"404  {url}")
        elif status != 200:
            other += 1
            print(f"{status}  {url}")
    print(f"404={missing} other_non_200={other}")
    if missing:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
