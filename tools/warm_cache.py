#!/usr/bin/env python3
"""Owner-run full-site cache warmer. Do not run this from an agent session.

Reads the odella.ge sitemap and requests each URL once with a mobile UA and
once with a desktop UA, slowly enough that LiteSpeed can store the response.
"""

from __future__ import annotations

import argparse
import http.client
import ssl
import time
import urllib.parse
import xml.etree.ElementTree as ET
from pathlib import Path

SITEMAP = "https://odella.ge/sitemap_index.xml"
NS = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
MOBILE_UA = (
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
    "AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)"
)
DESKTOP_UA = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)"


def fetch(url: str, user_agent: str, timeout: int = 90) -> tuple[int, float, str, bytes]:
    parsed = urllib.parse.urlparse(url)
    conn = http.client.HTTPSConnection(parsed.hostname, timeout=timeout, context=ssl.create_default_context())
    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    start = time.perf_counter()
    conn.request(
        "GET",
        path,
        headers={"User-Agent": user_agent, "Accept": "*/*", "Accept-Encoding": "identity"},
    )
    resp = conn.getresponse()
    ttfb = time.perf_counter() - start
    body = resp.read()
    cache = resp.getheader("x-litespeed-cache", "")
    status = resp.status
    conn.close()
    return status, ttfb, cache, body


def locs(xml_bytes: bytes) -> list[str]:
    root = ET.fromstring(xml_bytes)
    found = [el.text.strip() for el in root.findall(".//sm:loc", NS) if el.text]
    if found:
        return found
    return [el.text.strip() for el in root.iter() if el.tag.endswith("loc") and el.text]


def sitemap_kind(url: str) -> str | None:
    name = urllib.parse.urlparse(url).path.rsplit("/", 1)[-1]
    if name.startswith("page-sitemap"):
        return "pages"
    if name.startswith("product-sitemap"):
        return "products"
    return None


def collect_urls(only: str | None) -> list[str]:
    _status, _ttfb, _cache, body = fetch(SITEMAP, DESKTOP_UA)
    urls: list[str] = []
    for child in locs(body):
        kind = sitemap_kind(child)
        if kind is None or (only and kind != only):
            continue
        _status, _ttfb, _cache, child_body = fetch(child, DESKTOP_UA)
        urls.extend(locs(child_body))
        time.sleep(0.2)
    return urls


def main() -> None:
    parser = argparse.ArgumentParser(description="Warm the LiteSpeed cache for odella.ge sitemap URLs.")
    parser.add_argument("--limit", type=int, default=0, help="Stop after this many URLs (0 = all).")
    parser.add_argument("--only", choices=["pages", "products"], help="Warm only page or product sitemaps.")
    parser.add_argument("--delay", type=float, default=1.0, help="Seconds between requests.")
    args = parser.parse_args()

    urls = collect_urls(args.only)
    if args.limit > 0:
        urls = urls[: args.limit]
    print(f"Warming {len(urls)} URLs, delay {args.delay:.1f}s")
    hits = misses = 0
    samples: list[tuple[float, str, str, int]] = []
    for index, url in enumerate(urls, start=1):
        for label, agent in (("mobile", MOBILE_UA), ("desktop", DESKTOP_UA)):
            status, ttfb, cache, _body = fetch(url, agent)
            token = (cache or "").split(",")[0].strip().lower()
            if token == "hit":
                hits += 1
            elif token == "miss":
                misses += 1
            samples.append((ttfb, url, label, status))
            time.sleep(args.delay)
        if index % 25 == 0:
            print(f"{index}/{len(urls)} URLs  hit={hits} miss={misses}")
    samples.sort(reverse=True)
    print(f"Done. URLs={len(urls)} requests={len(samples)} hit={hits} miss={misses}")
    print("Slowest 10:")
    for ttfb, url, label, status in samples[:10]:
        print(f"  {ttfb * 1000:.0f} ms  {status}  {label}  {url}")


if __name__ == "__main__":
    main()
