#!/usr/bin/env python3
"""Odella Local Product Sheet Editor — zero-dependency Python 3.12 server."""

from __future__ import annotations

import argparse
import base64
import csv
import datetime
import io
import json
import mimetypes
import os
import re
import shutil
import socket
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import webbrowser
import zipfile
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

from xlsx_io import read_xlsx, write_xlsx

IS_FROZEN = bool(getattr(sys, "frozen", False))
if IS_FROZEN:
    BUNDLE_DIR = Path(getattr(sys, "_MEIPASS", Path(sys.executable).resolve().parent))
    EXE_DIR = Path(sys.executable).resolve().parent
    EDITOR_DIR = EXE_DIR
    STATIC_DIR = BUNDLE_DIR / "static"
    SETTINGS_PATH = EXE_DIR / "odella-editor-settings.json"
    EXCEL_DIR = EXE_DIR / "Odella-Workbooks"
    EXCEL_DIR.mkdir(parents=True, exist_ok=True)
else:
    EDITOR_DIR = Path(__file__).resolve().parent
    STATIC_DIR = EDITOR_DIR / "static"
    SETTINGS_PATH = EDITOR_DIR / "settings.json"
    REPO_ROOT = EDITOR_DIR.parent.parent
    EXCEL_DIR = REPO_ROOT / "Odella-excel"

DEFAULT_GEMINI_PROMPT = """You are an expert luxury fashion & lifestyle e-commerce translator for Odella (odella.ge), a Georgian brand selling silk scarves, kimonos, kaftans, beachwear, vests, bags, clutches, pocket squares, and handmade ceramic/seasonal home decor.

Translate the given Georgian product fields (name, short_description, description) into natural, refined, idiomatic English suitable for a boutique fashion storefront.

Rules:
1. Preserve brand & model proper nouns accurately (e.g. Pera, Sakura, Kaleidoscope, Neo, Bla, Gra, Zon, O'della).
2. Translate Georgian product types accurately (e.g. აბრეშუმის თავსაბურავი -> Silk Scarf, მოსაცმელი -> Kimono, კაფტანი -> Kaftan, პარეო -> Pareo, ჟილეტი -> Vest, საგულე -> Pocket Square, ქლაჩი -> Clutch, ჩანთა -> Bag, ბაფი -> Buff, სანათი -> Lamp, ლარნაკი -> Vase, რანერი -> Table Runner).
3. Preserve any HTML tags (<strong>, <em>, <ul>, <li>, <br>, <p>, <a>) and line breaks cleanly.
4. Keep measurements, care instructions (e.g. Hand wash at 30°C), and fabric compositions (e.g. 90% Silk, 10% Polyester) clear and natural.
5. Return ONLY valid JSON matching the requested schema, with no extra commentary."""

DEFAULT_SETTINGS: dict[str, Any] = {
    "theme": "dark",
    "ui_lang": "en",
    "font_size": 13,
    "zoom": 100,
    "row_density": "normal",
    "extra_blank_rows": 50,
    "auto_backup": True,
    "gemini_api_key": "",
    "gemini_model": "gemini-3.8-flash",
    "gemini_batch_size": 12,
    "gemini_prompt": DEFAULT_GEMINI_PROMPT,
    "recent_files": [],
}


def native_open_file_dialog() -> str:
    """Open native Windows File Explorer picker via comdlg32.dll and return exact full path."""
    if os.name != "nt":
        return ""
    import ctypes
    from ctypes import wintypes

    class OPENFILENAMEW(ctypes.Structure):
        _fields_ = [
            ("lStructSize", wintypes.DWORD),
            ("hwndOwner", wintypes.HWND),
            ("hInstance", wintypes.HINSTANCE),
            ("lpstrFilter", wintypes.LPCWSTR),
            ("lpstrCustomFilter", wintypes.LPWSTR),
            ("nMaxCustFilter", wintypes.DWORD),
            ("nFilterIndex", wintypes.DWORD),
            ("lpstrFile", wintypes.LPWSTR),
            ("nMaxFile", wintypes.DWORD),
            ("lpstrFileTitle", wintypes.LPWSTR),
            ("nMaxFileTitle", wintypes.DWORD),
            ("lpstrInitialDir", wintypes.LPCWSTR),
            ("lpstrTitle", wintypes.LPCWSTR),
            ("Flags", wintypes.DWORD),
            ("nFileOffset", wintypes.WORD),
            ("nFileExtension", wintypes.WORD),
            ("lpstrDefExt", wintypes.LPCWSTR),
            ("lCustData", wintypes.LPARAM),
            ("lpfnHook", wintypes.LPVOID),
            ("lpTemplateName", wintypes.LPCWSTR),
            ("pvReserved", wintypes.LPVOID),
            ("dwReserved", wintypes.DWORD),
            ("FlagsEx", wintypes.DWORD),
        ]

    buf = ctypes.create_unicode_buffer(4096)
    ofn = OPENFILENAMEW()
    ofn.lStructSize = ctypes.sizeof(OPENFILENAMEW)
    ofn.lpstrFilter = "Excel Workbooks (*.xlsx)\0*.xlsx\0All Files (*.*)\0*.*\0\0"
    ofn.lpstrFile = ctypes.cast(buf, wintypes.LPWSTR)
    ofn.nMaxFile = len(buf)
    ofn.lpstrTitle = "Open Odella Product Spreadsheet (.xlsx)"
    # OFN_FILEMUSTEXIST | OFN_PATHMUSTEXIST | OFN_NOCHANGEDIR
    ofn.Flags = 0x00001000 | 0x00000800 | 0x00000008
    if ctypes.windll.comdlg32.GetOpenFileNameW(ctypes.byref(ofn)):
        return buf.value
    return ""


def remember_recent_file(path_str: str) -> None:
    s = load_settings()
    recents = [p for p in (s.get("recent_files") or []) if isinstance(p, str) and p.lower() != path_str.lower() and Path(p).is_file()]
    recents.insert(0, path_str)
    save_settings({"recent_files": recents[:15]})


def load_settings() -> dict[str, Any]:
    merged = dict(DEFAULT_SETTINGS)
    if SETTINGS_PATH.is_file():
        try:
            stored = json.loads(SETTINGS_PATH.read_text(encoding="utf-8"))
            if isinstance(stored, dict):
                merged.update(stored)
        except Exception:
            pass
    return merged


def save_settings(updates: dict[str, Any]) -> dict[str, Any]:
    current = load_settings()
    for k, v in updates.items():
        if k in DEFAULT_SETTINGS:
            current[k] = v
    SETTINGS_PATH.write_text(json.dumps(current, ensure_ascii=False, indent=2), encoding="utf-8")
    return current


def create_backup_if_exists(target_path: Path) -> str | None:
    if not target_path.is_file():
        return None
    bdir = EXCEL_DIR / "_backups"
    bdir.mkdir(parents=True, exist_ok=True)
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    bpath = bdir / f"{target_path.stem}-{stamp}{target_path.suffix}"
    shutil.copy2(target_path, bpath)
    # Keep newest 25 backups per file stem
    existing = sorted(bdir.glob(f"{target_path.stem}-*{target_path.suffix}"), key=lambda p: p.stat().st_mtime, reverse=True)
    for old in existing[25:]:
        try:
            old.unlink()
        except Exception:
            pass
    return str(bpath)


def call_gemini_translate(api_key: str, model: str, system_prompt: str, items: list[dict[str, Any]]) -> dict[str, Any]:
    """Call Google Gemini API (Free tier compatible) with automatic retry on 429."""
    if not api_key.strip():
        return {"ok": False, "error": "Gemini API key is empty. Add your free key in Settings."}
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{urllib.parse.quote(model.strip())}:generateContent?key={urllib.parse.quote(api_key.strip())}"

    user_payload = json.dumps(items, ensure_ascii=False)
    prompt_text = (
        "Translate each product object in the JSON array below from Georgian to English.\n"
        "Return a JSON object with a single key \"translations\" containing an array of objects, "
        "each with keys: \"row_idx\" (integer, exact match), \"name_en\" (string), "
        "\"short_description_en\" (string), \"description_en\" (string).\n"
        "If an input field is empty, return an empty string for that field.\n\n"
        f"Input JSON:\n{user_payload}"
    )

    req_body = {
        "systemInstruction": {"parts": [{"text": system_prompt or DEFAULT_GEMINI_PROMPT}]},
        "contents": [{"role": "user", "parts": [{"text": prompt_text}]}],
        "generationConfig": {
            "temperature": 0.2,
            "responseMimeType": "application/json",
        },
    }
    data = json.dumps(req_body, ensure_ascii=False).encode("utf-8")

    last_err = ""
    for attempt in range(3):
        req = urllib.request.Request(url, data=data, headers={"Content-Type": "application/json"}, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=180) as resp:
                raw = json.loads(resp.read().decode("utf-8"))
            text = ""
            cands = raw.get("candidates") or []
            if cands:
                parts = (cands[0].get("content") or {}).get("parts") or []
                text = "".join(p.get("text", "") for p in parts)
            text = text.strip()
            if text.startswith("```"):
                text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text, flags=re.S).strip()
            parsed = json.loads(text)
            translations = parsed.get("translations") if isinstance(parsed, dict) else parsed
            if not isinstance(translations, list):
                return {"ok": False, "error": "Unexpected Gemini response format."}
            return {"ok": True, "translations": translations}
        except urllib.error.HTTPError as he:
            err_body = he.read().decode("utf-8", "replace")
            if he.code == 429 and attempt < 2:
                time.sleep(6 * (attempt + 1))
                last_err = f"Rate limited (429): {err_body[:200]}"
                continue
            return {"ok": False, "error": f"Gemini HTTP {he.code}: {err_body[:300]}", "status": he.code}
        except Exception as exc:
            last_err = str(exc)
            if attempt < 2:
                time.sleep(2)
                continue
    return {"ok": False, "error": last_err or "Gemini request failed."}


def parse_uploaded_csv(raw_bytes: bytes) -> list[list[str]]:
    text = raw_bytes.decode("utf-8-sig", errors="replace")
    reader = csv.reader(io.StringIO(text))
    return [list(r) for r in reader if any(c.strip() for c in r)]

IMAGE_EXTS = {".jpg", ".jpeg", ".png", ".gif", ".webp"}

DEFAULT_FILEBIRD_FOLDERS = [
    "Products/Beach Bag",
    "Products/Beach Dress",
    "Products/Beach Mat",
    "Products/Coat",
    "Products/Gift Set",
    "Products/Kaftan",
    "Products/Long Double-sided Scarf",
    "Products/Long Kimono",
    "Products/Pareo",
    "Products/Short Kimono",
    "Products/Silk Scarf",
    "Products/Vest",
    "Products",
    "Header & Footer",
    "Icons",
    "VIdeo",
]

KA_TO_EN_CATEGORIES = {
    "მამაკაცი": "men",
    "მამაკაცი->აქსესუარები": "men->Accessories",
    "მამაკაცი->აქსესუარები->ბაფი": "men->Accessories->Buff",
    "მამაკაცი->აქსესუარები->საგულე": "men->Accessories->Pocket Square",
    "მამაკაცი->ჟილეტი": "men->Vest",
    "მამაკაცი->სხვა": "men->Other",
    "მამაკაცი->ხალათი": "men->Robe",
    "სასაჩუქრე ნაკრები": "Gift Sets",
    "სეზონური": "Seasonal",
    "სეზონური->აღდგომა": "Seasonal->Easter",
    "სეზონური->კერამიკა": "Seasonal->Pottery",
    "სეზონური->მატი": "Seasonal->Beach Mat",
    "სეზონური->რანერი": "Seasonal->Runner",
    "სეზონური->სხვა დღესასწაულები": "Seasonal->Other Holidays",
    "სეზონური->შობა / ახალი წელი": "Seasonal->Christmas / New Year",
    "სხვა": "Other",
    "ქალი": "Women",
    "ქალი->აქსესუარები": "Women->Accessories",
    "ქალი->აქსესუარები->ბაფი": "Women->Accessories->Buff",
    "ქალი->აქსესუარები->ზღვის თავსაბურავი": "Women->Accessories->Head Band",
    "ქალი->აქსესუარები->თმისამაგრი": "Women->Accessories->Scrunchie",
    "ქალი->აქსესუარები->სხვა": "Women->Accessories->Other",
    "ქალი->აქსესუარები->ქამარი / წვრილი შარფი": "Women->Accessories->Belt / Skinny Scarf",
    "ქალი->ზღვის სამოსი": "Women->Beachwear",
    "ქალი->ზღვის სამოსი->კაბა": "Women->Beachwear->Beach Dress",
    "ქალი->ზღვის სამოსი->კაფტანი": "Women->Beachwear->Kaftan",
    "ქალი->ზღვის სამოსი->პარეო": "Women->Beachwear->Pareo",
    "ქალი->ზღვის სამოსი->საცურაო კოსტუმი": "Women->Beachwear->Swimsuit",
    "ქალი->ზღვის სამოსი->ჩანთა": "Women->Beachwear->Bag",
    "ქალი->თავსაბურავი": "Women->Scarf",
    "ქალი->თავსაბურავი->გრძელი ორმხრივი": "Women->Scarf->Long Double Sided",
    "ქალი->თავსაბურავი->ოთხკუთხედი": "Women->Scarf->Square",
    "ქალი->მოსაცმელი": "Women->Kimono",
    "ქალი->მოსაცმელი->გრძელი": "Women->Kimono->Long",
    "ქალი->მოსაცმელი->მოკლე": "Women->Kimono->Short",
    "ქალი->პალტო": "Women->Coat",
    "ქალი->ჟილეტი": "Women->Vest",
    "ქალი->სამკაულები": "Women->Jewellery",
    "ქალი->სამკაულები->ბეჭედი": "Women->Jewellery->Ring",
    "ქალი->სამკაულები->სამაჯური": "Women->Jewellery->Bracelet",
    "ქალი->სამკაულები->საყურე": "Women->Jewellery->Earring",
    "ქალი->სამკაულები->ყელსაბამი": "Women->Jewellery->Necklace",
    "ქალი->სხვა": "Women->Other",
    "ქალი->ჩანთა": "Women->Bag",
    "ქალი->ჩანთა->გადასაკიდი": "Women->Bag->Shoulder",
    "ქალი->ჩანთა->ზღვის": "Women->Bag->Beach",
    "ქალი->ჩანთა->ლეპტოპის": "Women->Bag->Laptop",
    "ქალი->ჩანთა->ქლაჩი": "Women->Bag->Clutch",
}
KA_TO_EN_LOWER = {k.lower(): v for k, v in KA_TO_EN_CATEGORIES.items()}


def translate_categories_to_en(ka_cell: str) -> str:
    parts = [p.strip() for p in ka_cell.split(",") if p.strip()]
    out: list[str] = []
    for p in parts:
        en = KA_TO_EN_LOWER.get(p.lower(), "")
        if en and en not in out:
            out.append(en)
    return ", ".join(out)

GEORGIAN_TO_LATIN = {
    "ა": "a", "ბ": "b", "გ": "g", "დ": "d", "ე": "e", "ვ": "v", "ზ": "z",
    "თ": "t", "ი": "i", "კ": "k", "ლ": "l", "მ": "m", "ნ": "n", "ო": "o",
    "პ": "p", "ჟ": "zh", "რ": "r", "ს": "s", "ტ": "t", "უ": "u", "ფ": "p",
    "ქ": "k", "ღ": "gh", "ყ": "q", "შ": "sh", "ჩ": "ch", "ც": "ts", "ძ": "dz",
    "წ": "ts", "ჭ": "ch", "ხ": "kh", "ჯ": "j", "ჰ": "h",
}


def transliterate_slug(text: str) -> str:
    """Transliterate Georgian to Latin and sanitize for safe filenames."""
    out = []
    for ch in text.strip():
        if ch in GEORGIAN_TO_LATIN:
            out.append(GEORGIAN_TO_LATIN[ch])
        elif ch.isascii() and (ch.isalnum() or ch in ("-", "_")):
            out.append(ch)
        elif ch in (" ", ".", "/", "\\", "–", "—"):
            out.append("_")
    slug = re.sub(r"_+", "_", "".join(out)).strip("_")
    return slug or "product"


def is_ignored_header(header: str) -> bool:
    h = re.sub(r"\s+", " ", header.strip()).lower()
    if h.endswith(" preview") or h in ("image preview", "სურათის ნახვა", "add category", "add tag"):
        return True
    if re.match(r"^gallery preview \d+$", h) or re.match(r"^gallery url \d+$", h):
        return True
    return False


def split_picture_tokens(cell: str) -> list[str]:
    cell = cell.replace("\r\n", "\n").replace("\r", "\n").strip()
    if not cell:
        return []
    parts = re.split(r"\s*(?:\||,|\n)\s*", cell)
    return [p.strip() for p in parts if p.strip()]


def parse_token(token: str) -> tuple[str, str, str]:
    """Return (kind, folder, value) where kind is 'url' | 'clear' | 'file'."""
    t = token.strip()
    if t.lower() == "(clear)":
        return ("clear", "", "")
    if re.match(r"^https?://", t, re.I):
        return ("url", "", t)
    if "/" in t:
        folder, fname = t.rsplit("/", 1)
        return ("file", folder.strip("/ "), fname.strip())
    return ("file", "", t)


def detect_default_images_root() -> str:
    if EXCEL_DIR.is_dir():
        for child in EXCEL_DIR.iterdir():
            if child.is_dir() and not child.name.startswith("_") and not child.name.startswith("."):
                return str(child)
    return str(EXCEL_DIR)


def sidecar_path(xlsx_path: Path) -> Path:
    return xlsx_path.with_suffix(xlsx_path.suffix + ".odella-paths.json")


def load_sidecar(xlsx_path: Path) -> dict[str, Any]:
    sp = sidecar_path(xlsx_path)
    if sp.is_file():
        try:
            data = json.loads(sp.read_text(encoding="utf-8"))
            if isinstance(data, dict):
                return data
        except Exception:
            pass
    return {"paths": {}, "images_root": detect_default_images_root()}


def save_sidecar(xlsx_path: Path, data: dict[str, Any]) -> None:
    sp = sidecar_path(xlsx_path)
    sp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def find_local_file(token: str, path_map: dict[str, str], images_root: str) -> Path | None:
    """Resolve a local image token (with or without FileBird folder prefix) to a disk Path."""
    for key in (token, parse_token(token)[2]):
        mapped = path_map.get(key)
        if mapped:
            p = Path(mapped)
            if p.is_file():
                return p
    _, _, fname = parse_token(token)
    if not fname:
        return None
    root = Path(images_root) if images_root else EXCEL_DIR
    if root.is_dir():
        target_lower = fname.lower()
        for dirpath, _, filenames in os.walk(root):
            for fn in filenames:
                if fn.lower() == target_lower:
                    return Path(dirpath) / fn
    dropped = EXCEL_DIR / "_dropped_images" / fname
    if dropped.is_file():
        return dropped
    return None


def load_workbook_payload(xlsx_path: Path) -> dict[str, Any]:
    raw_sheets = read_xlsx(xlsx_path)
    sidecar = load_sidecar(xlsx_path)

    # Filter ignored helper/preview columns from Products
    prod_rows = raw_sheets.get("Products", [])
    if prod_rows:
        orig_headers = prod_rows[0]
        keep_indices = [i for i, h in enumerate(orig_headers) if not is_ignored_header(h)]
        headers = [orig_headers[i] for i in keep_indices]
        products = []
        for r in prod_rows[1:]:
            row_vals = [r[i] if i < len(r) else "" for i in keep_indices]
            if any(v.strip() for v in row_vals):
                products.append(row_vals)
    else:
        headers = [
            "ID", "SKU", "Name", "Name (EN)", "Categories", "Categories (EN)", "Product type",
            "Hover image", "Featured image", "Gallery images",
            "Status", "Regular price (GEL)", "Sale price (GEL)",
            "Short description", "Short description (EN)", "Description", "Description (EN)",
            "EUR regular price", "EUR sale price", "USD regular price", "USD sale price",
            "Attributes",
        ]
        products = []

    # Ensure EN neighbor columns exist right next to Name, Categories, Short description, Description
    en_pairs = [
        ("Name", "Name (EN)"),
        ("Categories", "Categories (EN)"),
        ("Short description", "Short description (EN)"),
        ("Description", "Description (EN)"),
    ]
    for ka_h, en_h in en_pairs:
        has_en = any(h.strip().lower() in (en_h.lower(), ka_h.lower() + "_en") for h in headers)
        if not has_en:
            ka_idx = next((i for i, h in enumerate(headers) if h.strip().lower() == ka_h.lower()), -1)
            if ka_idx >= 0:
                headers.insert(ka_idx + 1, en_h)
                for row in products:
                    row.insert(ka_idx + 1, "")

    # Auto-populate Categories (EN) from Categories via Polylang KA->EN map
    cat_idx = next((i for i, h in enumerate(headers) if h.strip().lower() == "categories"), -1)
    cat_en_idx = next((i for i, h in enumerate(headers) if h.strip().lower() in ("categories (en)", "categories_en")), -1)
    if cat_idx >= 0 and cat_en_idx >= 0:
        for row in products:
            if row[cat_idx].strip():
                row[cat_en_idx] = translate_categories_to_en(row[cat_idx])

    categories = [r[0].strip() for r in raw_sheets.get("Categories", [])[1:] if r and r[0].strip()]
    tags = [r[0].strip() for r in raw_sheets.get("Tags", [])[1:] if r and r[0].strip()]

    attr_rows = raw_sheets.get("Attributes", [])
    attr_tokens: list[str] = []
    attr_tokens_seen: set[str] = set()
    attr_groups: dict[str, list[str]] = {}
    if attr_rows:
        a_headers = attr_rows[0]
        for r in attr_rows[1:]:
            if r and r[0].strip():
                tok = r[0].strip()
                if tok.lower() not in attr_tokens_seen:
                    attr_tokens_seen.add(tok.lower())
                    attr_tokens.append(tok)
            for ci in range(1, len(a_headers)):
                group_name = a_headers[ci].strip()
                if not group_name:
                    continue
                val = r[ci].strip() if ci < len(r) else ""
                if val:
                    attr_groups.setdefault(group_name, [])
                    if val not in attr_groups[group_name]:
                        attr_groups[group_name].append(val)

    var_rows = raw_sheets.get("Variations", [])
    if var_rows:
        var_headers = list(var_rows[0])
        variations = [
            [r[i] if i < len(r) else "" for i in range(len(var_headers))]
            for r in var_rows[1:]
            if any(v.strip() for v in r)
        ]
        # Ensure Parent SKU column exists in Variations for brand-new variable products
        if not any(h.strip().lower() == "parent sku" for h in var_headers):
            insert_at = 1 if (var_headers and var_headers[0].strip().lower() == "parent id") else 0
            var_headers.insert(insert_at, "Parent SKU")
            # Backfill Parent SKU from Products where Parent ID matches
            id_to_sku = {}
            id_col = next((i for i, h in enumerate(headers) if h.strip().lower() == "id"), -1)
            sku_col = next((i for i, h in enumerate(headers) if h.strip().lower() == "sku"), -1)
            if id_col >= 0 and sku_col >= 0:
                for p in products:
                    pid = p[id_col].strip()
                    if pid:
                        id_to_sku[pid] = p[sku_col].strip()
            for vr in variations:
                pid = vr[0].strip() if vr else ""
                vr.insert(insert_at, id_to_sku.get(pid, ""))
    else:
        var_headers = ["Parent ID", "Parent SKU", "Variation ID", "SKU", *list(attr_groups.keys()), "Regular price (GEL)", "Sale price (GEL)", "Status"]
        variations = []

    # Auto-sync Products.Attributes from Variations so variable products never lose their parent attributes
    sync_product_attributes_from_variations(headers, products, var_headers, variations, attr_groups)

    fb_rows = raw_sheets.get("FileBird", [])
    filebird = [r[0].strip() for r in fb_rows[1:] if r and r[0].strip()]
    if not filebird:
        filebird = list(DEFAULT_FILEBIRD_FOLDERS)

    lists_rows = raw_sheets.get("Lists", [
        ["publish", "simple"],
        ["draft", "variable"],
        ["", "grouped"],
        ["", "external"],
    ])

    return {
        "file": str(xlsx_path),
        "filename": xlsx_path.name,
        "headers": headers,
        "products": products,
        "categories": categories,
        "tags": tags,
        "attribute_tokens": attr_tokens,
        "attribute_groups": attr_groups,
        "variation_headers": var_headers,
        "variations": variations,
        "lists": lists_rows,
        "filebird": filebird,
        "category_en_map": KA_TO_EN_CATEGORIES,
        "paths": sidecar.get("paths", {}),
        "images_root": sidecar.get("images_root") or detect_default_images_root(),
    }


def sync_product_attributes_from_variations(
    headers: list[str],
    products: list[list[str]],
    var_headers: list[str],
    variations: list[list[str]],
    attr_groups: dict[str, list[str]],
) -> None:
    """Auto-populate/merge Products.Attributes from the Variations rows for every variable product."""
    if not headers or not products or not var_headers or not variations:
        return
    h_low = [h.strip().lower() for h in headers]
    vh_low = [h.strip().lower() for h in var_headers]

    def find_col(cols: list[str], name: str) -> int:
        try:
            return cols.index(name)
        except ValueError:
            return -1

    id_col = find_col(h_low, "id")
    sku_col = find_col(h_low, "sku")
    attr_col = find_col(h_low, "attributes")
    if attr_col < 0:
        return

    v_pid_col = find_col(vh_low, "parent id")
    v_psku_col = find_col(vh_low, "parent sku")

    # Map attribute group columns present in Variations sheet
    fixed_vcols = {"parent id", "parent sku", "variation id", "sku", "regular price (gel)", "sale price (gel)", "status"}
    attr_col_defs: list[tuple[str, int]] = []
    seen_groups: set[str] = set()
    for gname in attr_groups.keys():
        gi = find_col(vh_low, gname.strip().lower())
        if gi >= 0 and gname.strip().lower() not in seen_groups:
            seen_groups.add(gname.strip().lower())
            attr_col_defs.append((gname.strip(), gi))
    for gi, vh in enumerate(var_headers):
        gl = vh.strip().lower()
        if gl and gl not in fixed_vcols and gl not in seen_groups:
            seen_groups.add(gl)
            attr_col_defs.append((vh.strip(), gi))

    if not attr_col_defs:
        return

    # Collect attribute tokens per parent (keyed by id and lowercase sku)
    tokens_by_pid: dict[str, list[str]] = {}
    tokens_by_psku: dict[str, list[str]] = {}
    groups_by_pid: dict[str, set[str]] = {}
    groups_by_psku: dict[str, set[str]] = {}

    def add_tok(store: dict[str, list[str]], gstore: dict[str, set[str]], key: str, group: str, val: str) -> None:
        tok = f"{group}: {val}"
        lst = store.setdefault(key, [])
        if not any(x.lower() == tok.lower() for x in lst):
            lst.append(tok)
        gstore.setdefault(key, set()).add(group.lower())

    for vr in variations:
        vpid = (vr[v_pid_col] if 0 <= v_pid_col < len(vr) else "").strip()
        if vpid.endswith(".0"):
            vpid = vpid[:-2]
        vpsku = (vr[v_psku_col] if 0 <= v_psku_col < len(vr) else "").strip().lower()
        if not vpid and not vpsku:
            continue
        for gname, gi in attr_col_defs:
            val = (vr[gi] if gi < len(vr) else "").strip()
            if not val:
                continue
            if vpid:
                add_tok(tokens_by_pid, groups_by_pid, vpid, gname, val)
            if vpsku:
                add_tok(tokens_by_psku, groups_by_psku, vpsku, gname, val)

    for row in products:
        if attr_col >= len(row):
            continue
        pid = (row[id_col] if 0 <= id_col < len(row) else "").strip()
        if pid.endswith(".0"):
            pid = pid[:-2]
        sku = (row[sku_col] if 0 <= sku_col < len(row) else "").strip().lower()
        var_tokens = tokens_by_pid.get(pid) if pid and pid in tokens_by_pid else (tokens_by_psku.get(sku) if sku else None)
        if not var_tokens:
            continue
        used_groups = groups_by_pid.get(pid, set()) if pid and pid in groups_by_pid else groups_by_psku.get(sku, set())
        # Keep any existing non-variation attribute tokens (e.g. Color), and set exact variation attribute tokens
        existing_tokens = [t.strip() for t in str(row[attr_col] or "").split(",") if t.strip()]
        merged: list[str] = []
        merged_low: set[str] = set()
        for t in existing_tokens:
            g = t.split(":", 1)[0].strip().lower() if ":" in t else ""
            if g and g in used_groups:
                continue
            if t.lower() not in merged_low:
                merged_low.add(t.lower())
                merged.append(t)
        for t in var_tokens:
            if t.lower() not in merged_low:
                merged_low.add(t.lower())
                merged.append(t)
        row[attr_col] = ", ".join(merged)


def save_workbook_payload(target_path: Path, payload: dict[str, Any]) -> str | None:
    headers = payload["headers"]
    # Strip completely empty scratch rows before saving to .xlsx
    products = [r for r in payload["products"] if any(str(c or "").strip() for c in r)]
    categories = payload.get("categories", [])
    tags = payload.get("tags", [])
    attr_tokens = payload.get("attribute_tokens", [])
    attr_groups = payload.get("attribute_groups", {})
    var_headers = payload.get("variation_headers", [])
    variations = payload.get("variations", [])
    # Always keep Products.Attributes synced with Variations before writing .xlsx
    sync_product_attributes_from_variations(headers, products, var_headers, variations, attr_groups)
    lists_rows = payload.get("lists", [
        ["publish", "simple"],
        ["draft", "variable"],
        ["", "grouped"],
        ["", "external"],
    ])
    filebird = payload.get("filebird", DEFAULT_FILEBIRD_FOLDERS)

    backup_created = None
    settings = load_settings()
    if settings.get("auto_backup", True):
        backup_created = create_backup_if_exists(target_path)

    # Rebuild Attributes sheet
    group_names = list(attr_groups.keys())
    attr_sheet_headers = ["Attributes", *group_names]
    max_attr_len = max([len(attr_tokens), *[len(attr_groups[g]) for g in group_names], 0])
    attr_sheet_rows = [attr_sheet_headers]
    for i in range(max_attr_len):
        row = [attr_tokens[i] if i < len(attr_tokens) else ""]
        for g in group_names:
            vals = attr_groups[g]
            row.append(vals[i] if i < len(vals) else "")
        attr_sheet_rows.append(row)

    sheets = [
        ("Products", [headers, *products]),
        ("Categories", [["Categories"], *[[c] for c in categories]]),
        ("Tags", [["Tags"], *[[t] for t in tags]]),
        ("Attributes", attr_sheet_rows),
        ("Variations", [var_headers, *variations]),
        ("Lists", lists_rows),
        ("FileBird", [["FileBird"], *[[f] for f in filebird]]),
    ]
    write_xlsx(target_path, sheets)
    save_sidecar(target_path, {
        "paths": payload.get("paths", {}),
        "images_root": payload.get("images_root", detect_default_images_root()),
    })
    return backup_created


def plan_and_execute_fix_and_zip(payload: dict[str, Any]) -> dict[str, Any]:
    headers: list[str] = payload["headers"]
    products: list[list[str]] = [list(r) for r in payload["products"]]
    path_map: dict[str, str] = dict(payload.get("paths", {}))
    images_root: str = payload.get("images_root", detect_default_images_root())
    preview_only: bool = bool(payload.get("preview", True))
    rename_originals: bool = bool(payload.get("rename_originals", False))
    xlsx_file = Path(payload.get("file", str(EXCEL_DIR / "odella-products.xlsx")))

    def col_idx(name: str) -> int:
        low = name.lower()
        for i, h in enumerate(headers):
            if h.strip().lower() == low:
                return i
        return -1

    sku_col = col_idx("SKU")
    name_col = col_idx("Name")
    feat_col = col_idx("Featured image")
    hover_col = col_idx("Hover image")
    gal_col = col_idx("Gallery images")

    plan_items: list[dict[str, Any]] = []
    missing_items: list[dict[str, Any]] = []
    # Map old disk path -> new disk path (for optional rename_originals)
    disk_renames: dict[str, tuple[str, str]] = {}  # old_path_str -> (new_basename, folder)
    used_zip_names: set[str] = set()

    for r_idx, row in enumerate(products):
        sku = (row[sku_col] if sku_col >= 0 and sku_col < len(row) else "").strip() or f"row{r_idx + 2}"
        name = (row[name_col] if name_col >= 0 and name_col < len(row) else "").strip() or "product"
        slug = transliterate_slug(name)

        # Deduplicate local files per product (order: Featured -> Hover -> Gallery)
        product_seen: dict[str, tuple[str, str]] = {}  # resolved_key -> (new_basename, new_token_for_folder)
        next_index = 1

        def process_token(token: str, field_name: str) -> str:
            nonlocal next_index
            kind, folder, fname = parse_token(token)
            if kind != "file":
                return token
            disk_path = find_local_file(token, path_map, images_root)
            if disk_path is None:
                missing_items.append({
                    "row": r_idx + 2,
                    "sku": sku,
                    "field": field_name,
                    "token": token,
                })
                return token
            key = str(disk_path.resolve()).lower()
            if key in product_seen:
                new_basename = product_seen[key][0]
            else:
                ext = disk_path.suffix.lstrip(".").lower() or "jpg"
                while True:
                    candidate = f"{sku}.{slug}_{next_index}.{ext}"
                    next_index += 1
                    if candidate.lower() not in used_zip_names:
                        used_zip_names.add(candidate.lower())
                        new_basename = candidate
                        break
                product_seen[key] = (new_basename, str(disk_path))
                plan_items.append({
                    "row": r_idx + 2,
                    "sku": sku,
                    "name": name,
                    "field": field_name,
                    "old_token": token,
                    "old_path": str(disk_path),
                    "folder": folder,
                    "new_filename": new_basename,
                    "new_token": f"{folder}/{new_basename}" if folder else new_basename,
                })
                disk_renames[str(disk_path)] = (new_basename, folder)

            new_token = f"{folder}/{new_basename}" if folder else new_basename
            return new_token

        for col, label, is_multi in [
            (feat_col, "Featured image", False),
            (hover_col, "Hover image", False),
            (gal_col, "Gallery images", True),
        ]:
            if col < 0 or col >= len(row):
                continue
            cell = row[col].strip()
            if not cell:
                continue
            tokens = split_picture_tokens(cell)
            new_tokens = [process_token(t, label) for t in tokens]
            row[col] = " | ".join(new_tokens) if is_multi else (new_tokens[0] if new_tokens else "")

    if preview_only:
        return {
            "ok": True,
            "preview": True,
            "items": plan_items,
            "missing": missing_items,
            "total": len(plan_items),
        }

    # Execute: create zip + optionally rename originals + update path_map
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
    zip_out = xlsx_file.parent / f"odella-images-{stamp}.zip"
    new_path_map = dict(path_map)
    renamed_on_disk = 0

    with zipfile.ZipFile(zip_out, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        written_zip: set[str] = set()
        for item in plan_items:
            old_p = Path(item["old_path"])
            new_fn = item["new_filename"]
            new_tok = item["new_token"]
            if new_fn.lower() not in written_zip and old_p.is_file():
                zf.write(old_p, arcname=new_fn)
                written_zip.add(new_fn.lower())
            final_disk_path = old_p
            if rename_originals and old_p.is_file():
                target_p = old_p.parent / new_fn
                if target_p != old_p:
                    if not target_p.exists():
                        old_p.rename(target_p)
                        final_disk_path = target_p
                        renamed_on_disk += 1
                    else:
                        final_disk_path = target_p
            new_path_map[new_tok] = str(final_disk_path)
            new_path_map[new_fn] = str(final_disk_path)

    return {
        "ok": True,
        "preview": False,
        "zip_path": str(zip_out),
        "zip_name": zip_out.name,
        "total": len(plan_items),
        "renamed_on_disk": renamed_on_disk,
        "missing": missing_items,
        "products": products,
        "paths": new_path_map,
    }


LAST_PING = 0.0
HAS_CLIENT_CONNECTED = False
CLOSING_AT: float | None = None


class EditorHandler(BaseHTTPRequestHandler):
    def log_message(self, format: str, *args: Any) -> None:
        return

    def _send_json(self, data: Any, status: int = 200) -> None:
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def _read_json(self) -> dict[str, Any]:
        length = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(length) if length > 0 else b"{}"
        return json.loads(raw.decode("utf-8"))

    def do_GET(self) -> None:
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        qs = urllib.parse.parse_qs(parsed.query)

        if path == "/" or path == "/index.html":
            self._serve_static("index.html", "text/html; charset=utf-8")
            return
        if path.startswith("/static/"):
            rel = path[len("/static/"):]
            ctype = mimetypes.guess_type(rel)[0] or "application/octet-stream"
            if ctype.startswith("text/") or ctype == "application/javascript":
                ctype += "; charset=utf-8"
            self._serve_static(rel, ctype)
            return

        try:
            if path == "/api/ping":
                global LAST_PING, HAS_CLIENT_CONNECTED, CLOSING_AT
                LAST_PING = time.monotonic()
                HAS_CLIENT_CONNECTED = True
                CLOSING_AT = None
                self._send_json({"ok": True})
                return

            if path == "/api/settings":
                self._send_json({"ok": True, "settings": load_settings(), "default_prompt": DEFAULT_GEMINI_PROMPT})
                return

            if path == "/api/browse-open":
                chosen = native_open_file_dialog()
                if not chosen:
                    self._send_json({"ok": False, "cancelled": True})
                    return
                fp = Path(chosen)
                if not fp.is_file():
                    self._send_json({"ok": False, "error": f"File not found: {fp}"}, 404)
                    return
                remember_recent_file(str(fp))
                self._send_json({"ok": True, "file": str(fp), "filename": fp.name})
                return

            if path == "/api/files":
                files = []
                seen_paths: set[str] = set()
                # Include recently opened original files first
                for rp in (load_settings().get("recent_files") or []):
                    p = Path(rp)
                    if p.is_file() and p.suffix.lower() == ".xlsx" and not p.name.startswith("~$"):
                        key = str(p.resolve()).lower()
                        if key not in seen_paths:
                            seen_paths.add(key)
                            files.append({"name": p.name, "path": str(p)})
                search_dirs = [EXCEL_DIR]
                if IS_FROZEN:
                    search_dirs.append(EDITOR_DIR)
                for d in search_dirs:
                    if not d.is_dir():
                        continue
                    for p in sorted(d.glob("*.xlsx"), key=lambda x: x.stat().st_mtime, reverse=True):
                        if p.name.startswith("~$"):
                            continue
                        key = str(p.resolve()).lower()
                        if key in seen_paths:
                            continue
                        seen_paths.add(key)
                        files.append({"name": p.name, "path": str(p)})
                self._send_json({
                    "files": files,
                    "excel_dir": str(EXCEL_DIR),
                    "default_images_root": detect_default_images_root(),
                })
                return

            if path == "/api/workbook":
                file_param = (qs.get("file") or [""])[0]
                if not file_param:
                    self._send_json({"error": "Missing file parameter"}, 400)
                    return
                fp = Path(file_param)
                if not fp.is_file():
                    self._send_json({"error": f"File not found: {fp}"}, 404)
                    return
                self._send_json(load_workbook_payload(fp))
                return

            if path == "/api/local-images":
                root_param = (qs.get("root") or [detect_default_images_root()])[0]
                root = Path(root_param)
                groups: dict[str, list[dict[str, Any]]] = {}
                total = 0
                if root.is_dir():
                    for dirpath, _, filenames in os.walk(root):
                        rel_dir = os.path.relpath(dirpath, root)
                        folder_label = "(root)" if rel_dir == "." else rel_dir.replace("\\", "/")
                        for fn in sorted(filenames):
                            if Path(fn).suffix.lower() not in IMAGE_EXTS:
                                continue
                            full = Path(dirpath) / fn
                            groups.setdefault(folder_label, []).append({
                                "name": fn,
                                "folder": folder_label,
                                "path": str(full),
                                "size": full.stat().st_size,
                            })
                            total += 1
                self._send_json({"root": str(root), "exists": root.is_dir(), "total": total, "groups": groups})
                return

            if path == "/api/local-image":
                img_param = (qs.get("path") or [""])[0]
                token_param = (qs.get("token") or [""])[0]
                root_param = (qs.get("root") or [detect_default_images_root()])[0]
                fp: Path | None = None
                if img_param:
                    cand = Path(img_param)
                    if cand.is_file():
                        fp = cand
                if fp is None and token_param:
                    fp = find_local_file(token_param, {}, root_param)
                if fp is None or not fp.is_file() or fp.suffix.lower() not in IMAGE_EXTS:
                    self.send_error(HTTPStatus.NOT_FOUND, "Image not found")
                    return
                ctype = mimetypes.guess_type(fp.name)[0] or "image/jpeg"
                data = fp.read_bytes()
                self.send_response(200)
                self.send_header("Content-Type", ctype)
                self.send_header("Content-Length", str(len(data)))
                self.send_header("Cache-Control", "max-age=60")
                self.end_headers()
                self.wfile.write(data)
                return

            self.send_error(HTTPStatus.NOT_FOUND)
        except Exception as exc:
            self._send_json({"error": str(exc)}, 500)

    def do_POST(self) -> None:
        parsed = urllib.parse.urlparse(self.path)
        path = parsed.path
        if path == "/api/closing":
            global CLOSING_AT
            CLOSING_AT = time.monotonic()
            self._send_json({"ok": True})
            return
        try:
            body = self._read_json()

            if path == "/api/settings":
                saved = save_settings(body)
                self._send_json({"ok": True, "settings": saved})
                return

            if path == "/api/gemini-models":
                api_key = str(body.get("gemini_api_key") or load_settings().get("gemini_api_key") or "").strip()
                if not api_key:
                    self._send_json({"ok": False, "error": "Enter your Gemini API key first."})
                    return
                url = f"https://generativelanguage.googleapis.com/v1beta/models?key={urllib.parse.quote(api_key)}&pageSize=100"
                try:
                    with urllib.request.urlopen(url, timeout=20) as resp:
                        data = json.loads(resp.read().decode("utf-8"))
                    skip_tokens = ("tts", "image", "audio", "robotics", "computer-use", "deep-research", "embedding", "aqa", "learnlm")
                    models = []
                    for m in data.get("models") or []:
                        methods = m.get("supportedGenerationMethods") or []
                        name = str(m.get("name") or "").replace("models/", "")
                        low = name.lower()
                        if "generateContent" not in methods or not low.startswith("gemini"):
                            continue
                        if any(tok in low for tok in skip_tokens):
                            continue
                        is_flash = "flash" in low
                        tag = " ★ Free-tier friendly" if is_flash else ""
                        models.append({
                            "id": name,
                            "label": f"{name} — {m.get('displayName', '')}{tag}".strip(" —"),
                            "_rank": 0 if is_flash else 1,
                        })
                    models.sort(key=lambda x: (x["_rank"], x["id"]))
                    for m in models:
                        m.pop("_rank", None)
                    self._send_json({"ok": True, "models": models})
                except urllib.error.HTTPError as he:
                    self._send_json({"ok": False, "error": f"HTTP {he.code}: {he.read().decode('utf-8', 'replace')[:250]}"})
                except Exception as exc:
                    self._send_json({"ok": False, "error": str(exc)})
                return

            if path == "/api/test-gemini":
                api_key = str(body.get("gemini_api_key") or load_settings().get("gemini_api_key") or "")
                model = str(body.get("gemini_model") or load_settings().get("gemini_model") or "gemini-3.8-flash")
                res = call_gemini_translate(
                    api_key,
                    model,
                    DEFAULT_GEMINI_PROMPT,
                    [{"row_idx": 0, "name": "აბრეშუმის თავსაბურავი Pera", "short_description": "", "description": ""}],
                )
                self._send_json(res)
                return

            if path == "/api/translate":
                settings = load_settings()
                api_key = str(body.get("gemini_api_key") or settings.get("gemini_api_key") or "")
                model = str(body.get("gemini_model") or settings.get("gemini_model") or "gemini-3.8-flash")
                prompt = str(body.get("gemini_prompt") or settings.get("gemini_prompt") or DEFAULT_GEMINI_PROMPT)
                items = body.get("items") or []
                res = call_gemini_translate(api_key, model, prompt, items)
                self._send_json(res)
                return

            if path == "/api/import-file":
                filename = Path(str(body.get("filename") or "imported.xlsx")).name
                raw = base64.b64decode(body.get("data_base64") or "")
                mode = str(body.get("mode") or "open")
                ext = Path(filename).suffix.lower()
                if ext not in (".xlsx", ".csv"):
                    self._send_json({"ok": False, "error": "Only .xlsx and .csv files are supported."}, 400)
                    return

                EXCEL_DIR.mkdir(parents=True, exist_ok=True)
                if mode == "open":
                    dest = EXCEL_DIR / filename
                    if dest.exists():
                        stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
                        dest = EXCEL_DIR / f"{Path(filename).stem}-{stamp}.xlsx"
                    if ext == ".csv":
                        rows = parse_uploaded_csv(raw)
                        dest = dest.with_suffix(".xlsx")
                        write_xlsx(dest, [("Products", rows)])
                    else:
                        dest.write_bytes(raw)
                    wb = load_workbook_payload(dest)
                    self._send_json({"ok": True, "mode": "open", "workbook": wb})
                    return

                # mode == "merge"
                if ext == ".csv":
                    in_rows = parse_uploaded_csv(raw)
                else:
                    tmp = EXCEL_DIR / f"._tmp_import_{int(time.time())}.xlsx"
                    try:
                        tmp.write_bytes(raw)
                        sheets = read_xlsx(tmp)
                        in_rows = sheets.get("Products") or (next(iter(sheets.values())) if sheets else [])
                    finally:
                        if tmp.exists():
                            tmp.unlink()
                if len(in_rows) < 2:
                    self._send_json({"ok": False, "error": "Imported file has no product rows."}, 400)
                    return

                cur_headers: list[str] = list(body.get("headers") or [])
                cur_products: list[list[str]] = [list(r) for r in (body.get("products") or []) if any(str(c or "").strip() for c in r)]
                in_headers = [h.strip() for h in in_rows[0]]

                # Map incoming headers -> current headers (case-insensitive)
                cur_h_map = {h.strip().lower(): i for i, h in enumerate(cur_headers)}
                col_map: dict[int, int] = {}
                for src_i, sh in enumerate(in_headers):
                    if is_ignored_header(sh):
                        continue
                    key = sh.lower()
                    if key in cur_h_map:
                        col_map[src_i] = cur_h_map[key]

                id_c = cur_h_map.get("id", -1)
                sku_c = cur_h_map.get("sku", -1)
                by_id = {r[id_c].strip(): i for i, r in enumerate(cur_products) if id_c >= 0 and r[id_c].strip()}
                by_sku = {r[sku_c].strip().lower(): i for i, r in enumerate(cur_products) if sku_c >= 0 and r[sku_c].strip()}

                updated = 0
                added = 0
                for raw_r in in_rows[1:]:
                    if not any(str(c or "").strip() for c in raw_r):
                        continue
                    mapped = [""] * len(cur_headers)
                    for src_i, dst_i in col_map.items():
                        if src_i < len(raw_r):
                            mapped[dst_i] = str(raw_r[src_i] or "")
                    rid = mapped[id_c].strip() if id_c >= 0 else ""
                    rsku = mapped[sku_c].strip().lower() if sku_c >= 0 else ""
                    target_idx = by_id.get(rid) if rid else None
                    if target_idx is None and rsku:
                        target_idx = by_sku.get(rsku)
                    if target_idx is not None:
                        changed = False
                        for dst_i in col_map.values():
                            if mapped[dst_i] != "" and mapped[dst_i] != cur_products[target_idx][dst_i]:
                                cur_products[target_idx][dst_i] = mapped[dst_i]
                                changed = True
                        if changed:
                            updated += 1
                    else:
                        cur_products.append(mapped)
                        new_idx = len(cur_products) - 1
                        if rid:
                            by_id[rid] = new_idx
                        if rsku:
                            by_sku[rsku] = new_idx
                        added += 1

                self._send_json({"ok": True, "mode": "merge", "products": cur_products, "updated": updated, "added": added})
                return

            if path == "/api/auto-match-images":
                headers_list: list[str] = list(body.get("headers") or [])
                products_list: list[list[str]] = [list(r) for r in (body.get("products") or [])]
                root = Path(str(body.get("images_root") or detect_default_images_root()))
                filebird_folder = str(body.get("filebird_folder") or "").strip("/ ")
                preview_only = bool(body.get("preview", True))

                def find_c(name: str) -> int:
                    low = name.lower()
                    return next((i for i, h in enumerate(headers_list) if h.strip().lower() == low), -1)

                sku_c, name_c = find_c("SKU"), find_c("Name")
                hov_c, feat_c, gal_c = find_c("Hover image"), find_c("Featured image"), find_c("Gallery images")

                by_sku_files: dict[str, list[tuple[str, str]]] = {}
                if root.is_dir():
                    for dp, _, fns in os.walk(root):
                        for fn in sorted(fns):
                            if Path(fn).suffix.lower() not in IMAGE_EXTS:
                                continue
                            m = re.match(r"^(\d{5,8})", fn.strip())
                            if m:
                                by_sku_files.setdefault(m.group(1), []).append((fn, str(Path(dp) / fn)))

                matches = []
                skipped_existing = []
                new_paths = dict(body.get("paths") or {})

                for r_idx, row in enumerate(products_list):
                    if sku_c < 0 or sku_c >= len(row):
                        continue
                    sku = row[sku_c].strip()
                    if not sku or sku not in by_sku_files:
                        continue
                    has_any = any(c >= 0 and c < len(row) and row[c].strip() for c in (feat_c, hov_c, gal_c))
                    files = sorted(by_sku_files[sku], key=lambda x: x[0].lower())
                    if has_any:
                        skipped_existing.append({"row": r_idx + 2, "sku": sku, "name": row[name_c] if name_c >= 0 else ""})
                        continue
                    tokens = [f"{filebird_folder}/{fn}" if filebird_folder else fn for fn, _ in files]
                    matches.append({
                        "row": r_idx + 2,
                        "sku": sku,
                        "name": row[name_c] if name_c >= 0 else "",
                        "files": tokens,
                    })
                    if not preview_only:
                        for (fn, full), tok in zip(files, tokens):
                            new_paths[tok] = full
                            new_paths[fn] = full
                        if feat_c >= 0:
                            row[feat_c] = tokens[0]
                        if len(tokens) >= 2 and hov_c >= 0:
                            row[hov_c] = tokens[1]
                        if gal_c >= 0:
                            row[gal_c] = " | ".join(tokens)

                self._send_json({
                    "ok": True,
                    "preview": preview_only,
                    "matches": matches,
                    "skipped_existing": skipped_existing,
                    "products": products_list if not preview_only else None,
                    "paths": new_paths if not preview_only else None,
                })
                return

            if path == "/api/workbook/save":
                target = Path(body.get("save_as") or body["file"])
                if not target.is_absolute():
                    target = EXCEL_DIR / target
                if target.suffix.lower() != ".xlsx":
                    target = target.with_suffix(".xlsx")
                backup = save_workbook_payload(target, body)
                self._send_json({"ok": True, "file": str(target), "filename": target.name, "backup": backup})
                return

            if path == "/api/upload-drop":
                filename = Path(body.get("filename", "image.jpg")).name
                b64 = body.get("data_base64", "")
                root = Path(body.get("images_root") or detect_default_images_root())
                raw = base64.b64decode(b64) if b64 else b""

                # Try to match the real original file inside the configured root folder
                matched_path: Path | None = None
                if root.is_dir():
                    candidates = []
                    for dirpath, _, fns in os.walk(root):
                        for fn in fns:
                            if fn.lower() == filename.lower():
                                candidates.append(Path(dirpath) / fn)
                    if len(candidates) == 1:
                        matched_path = candidates[0]
                    elif len(candidates) > 1 and raw:
                        for c in candidates:
                            if c.stat().st_size == len(raw):
                                matched_path = c
                                break
                        if matched_path is None:
                            matched_path = candidates[0]

                if matched_path is not None:
                    self._send_json({
                        "ok": True,
                        "filename": matched_path.name,
                        "path": str(matched_path),
                        "matched_original": True,
                    })
                    return

                # Fallback: save to Odella-excel/_dropped_images/
                drop_dir = EXCEL_DIR / "_dropped_images"
                drop_dir.mkdir(parents=True, exist_ok=True)
                dest = drop_dir / filename
                if raw:
                    dest.write_bytes(raw)
                self._send_json({
                    "ok": True,
                    "filename": dest.name,
                    "path": str(dest),
                    "matched_original": False,
                })
                return

            if path == "/api/fix-and-zip":
                res = plan_and_execute_fix_and_zip(body)
                self._send_json(res)
                return

            self.send_error(HTTPStatus.NOT_FOUND)
        except Exception as exc:
            self._send_json({"error": str(exc)}, 500)

    def _serve_static(self, rel_path: str, content_type: str) -> None:
        fp = (STATIC_DIR / rel_path).resolve()
        if not str(fp).startswith(str(STATIC_DIR.resolve())) or not fp.is_file():
            self.send_error(HTTPStatus.NOT_FOUND)
            return
        data = fp.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()
        self.wfile.write(data)


def find_free_port(preferred: int = 8765) -> int:
    for port in range(preferred, preferred + 40):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
            try:
                s.bind(("127.0.0.1", port))
                return port
            except OSError:
                continue
    return 0


def launch_app_window(url: str, force_tab: bool = False) -> bool:
    """Open URL in Edge/Chrome standalone --app window (full browser engine, own window)."""
    if not force_tab and os.name == "nt":
        import subprocess

        candidates = [
            Path(os.environ.get("PROGRAMFILES(X86)", "")) / "Microsoft/Edge/Application/msedge.exe",
            Path(os.environ.get("PROGRAMFILES", "")) / "Microsoft/Edge/Application/msedge.exe",
            Path(os.environ.get("PROGRAMFILES", "")) / "Google/Chrome/Application/chrome.exe",
            Path(os.environ.get("PROGRAMFILES(X86)", "")) / "Google/Chrome/Application/chrome.exe",
            Path(os.environ.get("LOCALAPPDATA", "")) / "Google/Chrome/Application/chrome.exe",
        ]
        for exe in candidates:
            if exe.is_file():
                try:
                    subprocess.Popen(
                        [str(exe), f"--app={url}", "--window-size=1280,760"],
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                    )
                    return True
                except Exception:
                    continue
    webbrowser.open(url)
    return False


def main() -> None:
    parser = argparse.ArgumentParser(description="Odella Product Sheet Editor")
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--no-browser", action="store_true", help="Headless server mode (for testing)")
    parser.add_argument("--browser", action="store_true", help="Open in a normal browser tab instead of --app window")
    args = parser.parse_args()

    port = find_free_port(args.port)
    server = ThreadingHTTPServer(("127.0.0.1", port), EditorHandler)
    actual_port = server.server_address[1]
    url = f"http://127.0.0.1:{actual_port}/"

    if args.no_browser:
        print(f"Odella Product Sheet Editor (headless) at {url}", flush=True)
        try:
            server.serve_forever()
        except KeyboardInterrupt:
            pass
        finally:
            server.server_close()
        return

    srv_thread = threading.Thread(target=server.serve_forever, daemon=True)
    srv_thread.start()

    launch_app_window(url, force_tab=args.browser)

    # Clean auto-shutdown watchdog:
    # - 2.5s after pagehide (/api/closing) if no F5 reload ping cancels it
    # - 15s heartbeat fallback if browser process is killed abruptly
    try:
        started_at = time.monotonic()
        while True:
            time.sleep(0.5)
            now = time.monotonic()
            if CLOSING_AT is not None and (now - CLOSING_AT) > 2.5:
                break
            if HAS_CLIENT_CONNECTED and (now - LAST_PING) > 15.0:
                break
            if not HAS_CLIENT_CONNECTED and (now - started_at) > 120.0:
                break
    except KeyboardInterrupt:
        pass
    finally:
        server.shutdown()
        server.server_close()


if __name__ == "__main__":
    main()
