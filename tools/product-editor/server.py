#!/usr/bin/env python3
"""Odella Local Product Sheet Editor — zero-dependency Python 3.12 server."""

from __future__ import annotations

import argparse
import base64
import csv
import datetime
import hashlib
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

APP_VERSION = "1.2.1"
DEFAULT_UPDATE_REPO = "NMeJa/odella-sheet-editor-releases"

IS_FROZEN = bool(getattr(sys, "frozen", False))
if IS_FROZEN:
    BUNDLE_DIR = Path(getattr(sys, "_MEIPASS", Path(sys.executable).resolve().parent))
    EXE_DIR = Path(sys.executable).resolve().parent
    EDITOR_DIR = EXE_DIR
    STATIC_DIR = BUNDLE_DIR / "static"
    LEGACY_SETTINGS_PATH = EXE_DIR / "odella-editor-settings.json"
    EXCEL_DIR = EXE_DIR / "Odella-Workbooks"
    EXCEL_DIR.mkdir(parents=True, exist_ok=True)
else:
    EDITOR_DIR = Path(__file__).resolve().parent
    STATIC_DIR = EDITOR_DIR / "static"
    LEGACY_SETTINGS_PATH = EDITOR_DIR / "settings.json"
    REPO_ROOT = EDITOR_DIR.parent.parent
    EXCEL_DIR = REPO_ROOT / "Odella-excel"


def get_app_data_dir() -> Path:
    if os.name == "nt":
        base = os.environ.get("LOCALAPPDATA") or str(Path.home() / "AppData" / "Local")
        d = Path(base) / "OdellaSheetEditor"
    else:
        d = Path.home() / ".config" / "odella-sheet-editor"
    try:
        d.mkdir(parents=True, exist_ok=True)
        return d
    except OSError:
        return EDITOR_DIR


APP_DATA_DIR = get_app_data_dir()
SETTINGS_PATH = APP_DATA_DIR / "settings.json"

# One-time migration from legacy folder-local settings file into AppData
if SETTINGS_PATH != LEGACY_SETTINGS_PATH and not SETTINGS_PATH.is_file() and LEGACY_SETTINGS_PATH.is_file():
    try:
        shutil.copy2(LEGACY_SETTINGS_PATH, SETTINGS_PATH)
    except Exception:
        pass

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
    "last_opened_file": "",
    "last_images_root": "",
    "frozen_cols": ["id", "sku", "name"],
    "drawer_width": 340,
    "drawer_open": False,
    "lang_view": "all",
    "wp_site_url": "https://odella.ge",
    "wp_username": "",
    "wp_app_password": "",
    "push_batch_size": 10,
    "hostinger_api_token": "",
    "hostinger_username": "u981153228",
    "update_repo": DEFAULT_UPDATE_REPO,
}


def wp_rest_request(
    method: str,
    endpoint: str,
    payload: dict[str, Any] | None = None,
    site_url: str | None = None,
    username: str | None = None,
    app_password: str | None = None,
    timeout: int = 120,
) -> dict[str, Any]:
    s = load_settings()
    base = (site_url if site_url is not None else str(s.get("wp_site_url") or "https://odella.ge")).strip().rstrip("/")
    user = (username if username is not None else str(s.get("wp_username") or "")).strip()
    pw = (app_password if app_password is not None else str(s.get("wp_app_password") or "")).strip()
    if not base or not user or not pw:
        return {"ok": False, "error": "WordPress connection not configured. Enter your Site URL, Username, and Application Password in Settings."}

    url = f"{base}/wp-json/odella-sheet/v1/{endpoint.lstrip('/')}"
    auth_b64 = base64.b64encode(f"{user}:{pw}".encode("utf-8")).decode("ascii")
    headers = {
        "Authorization": f"Basic {auth_b64}",
        "Accept": "application/json",
        "User-Agent": "OdellaSheetEditor/1.0",
    }
    data = None
    if payload is not None:
        headers["Content-Type"] = "application/json"
        data = json.dumps(payload, ensure_ascii=False).encode("utf-8")

    req = urllib.request.Request(url, data=data, headers=headers, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8", "replace")
            return json.loads(raw) if raw.strip() else {"ok": True}
    except urllib.error.HTTPError as he:
        err_raw = he.read().decode("utf-8", "replace")
        try:
            parsed = json.loads(err_raw)
            msg = parsed.get("error") or parsed.get("message") or err_raw[:280]
        except Exception:
            msg = err_raw[:280]
        if he.code in (401, 403):
            return {"ok": False, "error": f"Authentication failed (HTTP {he.code}): check Username & Application Password. ({msg})"}
        return {"ok": False, "error": f"Server HTTP {he.code}: {msg}"}
    except Exception as exc:
        return {"ok": False, "error": f"Connection failed: {exc}"}


def remember_recent_file(path_str: str) -> None:
    s = load_settings()
    recents = [p for p in (s.get("recent_files") or []) if isinstance(p, str) and p.lower() != path_str.lower() and Path(p).is_file()]
    recents.insert(0, path_str)
    save_settings({"recent_files": recents[:15]})


_BROWSE_LOCK = threading.Lock()


def native_browse_folder_dialog(initial_dir: str = "") -> str:
    """Open modern Windows 11 File Explorer folder picker (IFileOpenDialog + FOS_PICKFOLDERS, UTF-8 safe)."""
    if os.name != "nt":
        return ""
    import subprocess

    init_safe = initial_dir.replace("'", "''") if initial_dir and Path(initial_dir).is_dir() else ""
    ps_script = f"""
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class OdellaModernFolderPicker {{
    [ComImport, Guid("DC1C5A9C-E88A-4dde-A5A1-60F82A20AEF7")]
    private class FileOpenDialog {{}}

    [ComImport, Guid("42f85136-db7e-439c-85f1-e4075d135fc8"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IFileDialog {{
        [PreserveSig] int Show(IntPtr parent);
        void SetFileTypes(uint cFileTypes, IntPtr rgFilterSpec);
        void SetFileTypeIndex(uint iFileType);
        void GetFileTypeIndex(out uint piFileType);
        void Advise(IntPtr pfde, out uint pdwCookie);
        void Unadvise(uint dwCookie);
        void SetOptions(uint fos);
        void GetOptions(out uint pfos);
        void SetDefaultFolder(IShellItem psi);
        void SetFolder(IShellItem psi);
        void GetFolder(out IShellItem ppsi);
        void GetCurrentSelection(out IShellItem ppsi);
        void SetFileName([MarshalAs(UnmanagedType.LPWStr)] string pszName);
        void GetFileName([MarshalAs(UnmanagedType.LPWStr)] out string pszName);
        void SetTitle([MarshalAs(UnmanagedType.LPWStr)] string pszTitle);
        void SetOkButtonLabel([MarshalAs(UnmanagedType.LPWStr)] string pszText);
        void SetFileNameLabel([MarshalAs(UnmanagedType.LPWStr)] string pszLabel);
        void GetResult(out IShellItem ppsi);
        void AddPlace(IShellItem psi, int alignment);
        void SetDefaultExtension([MarshalAs(UnmanagedType.LPWStr)] string pszDefaultExtension);
        void Close(int hr);
        void SetClientGuid(ref Guid guid);
        void ClearClientData();
        void SetFilter(IntPtr pFilter);
    }}

    [ComImport, Guid("43826D1E-E718-42EE-BC55-A1E261C37BFE"), InterfaceType(ComInterfaceType.InterfaceIsIUnknown)]
    private interface IShellItem {{
        void BindToHandler(IntPtr pbc, ref Guid bhid, ref Guid riid, out IntPtr ppv);
        void GetParent(out IShellItem ppsi);
        void GetDisplayName(uint sigdnName, [MarshalAs(UnmanagedType.LPWStr)] out string ppszName);
        void GetAttributes(uint sfgaoMask, out uint psfgaoAttribs);
        void Compare(IShellItem psi, uint hint, out int piOrder);
    }}

    [DllImport("shell32.dll", CharSet = CharSet.Unicode, PreserveSig = true)]
    private static extern int SHCreateItemFromParsingName(string pszPath, IntPtr pbc, ref Guid riid, out IShellItem ppv);

    [DllImport("user32.dll")]
    private static extern IntPtr GetForegroundWindow();

    public static string Pick(string initialDir, string title) {{
        var dlg = (IFileDialog)new FileOpenDialog();
        uint opts;
        dlg.GetOptions(out opts);
        dlg.SetOptions(opts | 0x20u | 0x40u); // FOS_PICKFOLDERS | FOS_FORCEFILESYSTEM
        if (!string.IsNullOrEmpty(title)) dlg.SetTitle(title);
        if (!string.IsNullOrEmpty(initialDir)) {{
            Guid iid = new Guid("43826D1E-E718-42EE-BC55-A1E261C37BFE");
            IShellItem item;
            if (SHCreateItemFromParsingName(initialDir, IntPtr.Zero, ref iid, out item) == 0 && item != null) {{
                dlg.SetFolder(item);
            }}
        }}
        int hr = dlg.Show(GetForegroundWindow());
        if (hr != 0) return "";
        IShellItem res;
        dlg.GetResult(out res);
        string path;
        res.GetDisplayName(0x80058000u, out path); // SIGDN_FILESYSPATH
        return path ?? "";
    }}
}}
'@
[OdellaModernFolderPicker]::Pick('{init_safe}', 'Select Local Images Root Folder')
"""
    creationflags = getattr(subprocess, "CREATE_NO_WINDOW", 0)
    try:
        proc = subprocess.run(
            ["powershell.exe", "-NoProfile", "-STA", "-Command", ps_script],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            creationflags=creationflags,
            timeout=300,
        )
        return (proc.stdout or "").strip()
    except Exception:
        return ""


def resolve_dropped_folder(folder_name: str, sample_files: list[str], hints: list[str]) -> Path | None:
    """Resolve a folder dragged from Windows Explorer by name + optional sample child names."""
    target_name = Path(folder_name.strip().rstrip("/\\")).name
    if not target_name:
        return None
    # If full path was provided directly
    direct = Path(folder_name.strip())
    if direct.is_dir():
        return direct

    target_low = target_name.lower()
    samples_low = [s.strip().lower() for s in sample_files if s and s.strip()][:8]

    home = Path.home()
    search_roots: list[Path] = []
    for h in hints:
        if h:
            p = Path(h)
            if p.is_file():
                p = p.parent
            if p.is_dir():
                search_roots.append(p)
                if p.parent.is_dir():
                    search_roots.append(p.parent)
    search_roots.extend([
        EXCEL_DIR,
        EDITOR_DIR,
        home / "Desktop",
        home / "Documents",
        home / "Downloads",
        home / "Pictures",
        home / "OneDrive",
        home,
    ])

    seen_roots: set[str] = set()
    candidates: list[Path] = []
    max_depth = 3

    for root in search_roots:
        if not root.is_dir():
            continue
        rkey = str(root.resolve()).lower()
        if rkey in seen_roots:
            continue
        seen_roots.add(rkey)
        if root.name.lower() == target_low:
            candidates.append(root)
        root_depth = len(root.parts)
        try:
            for dirpath, dirnames, _ in os.walk(root):
                cur = Path(dirpath)
                depth = len(cur.parts) - root_depth
                # Prune hidden/system/heavy dirs
                dirnames[:] = [
                    d for d in dirnames
                    if not d.startswith(".") and d.lower() not in ("node_modules", "__pycache__", "appdata", "windows", "program files", "program files (x86)")
                ]
                for d in dirnames:
                    if d.lower() == target_low:
                        candidates.append(cur / d)
                if depth >= max_depth:
                    dirnames[:] = []
        except Exception:
            continue

    if not candidates:
        return None

    # Deduplicate candidates
    uniq: list[Path] = []
    seen_c: set[str] = set()
    for c in candidates:
        ck = str(c.resolve()).lower()
        if ck not in seen_c:
            seen_c.add(ck)
            uniq.append(c)

    if len(uniq) == 1 or not samples_low:
        return uniq[0]

    # Score candidates by how many sample child filenames exist inside them
    best = uniq[0]
    best_score = -1
    for c in uniq:
        try:
            children = {p.name.lower() for p in c.iterdir()}
        except Exception:
            continue
        score = sum(1 for s in samples_low if s in children)
        if score > best_score:
            best_score = score
            best = c
    return best


_WEB_IMAGE_HASH_CACHE: dict[str, tuple[int, str]] = {}


def _stem_variants(name: str) -> set[str]:
    low = Path(name).stem.lower()
    variants = {low}
    stripped = re.sub(r"(?:-scaled|-\d+)+$", "", low)
    if stripped:
        variants.add(stripped)
    return variants


def match_local_to_web_image(local_path: Path, web_urls: list[str]) -> str:
    """Return matching web URL if local_path is byte-identical (sha256) to an existing web image."""
    if not local_path.is_file() or not web_urls:
        return ""
    local_bytes = local_path.read_bytes()
    local_size = len(local_bytes)
    local_hash = hashlib.sha256(local_bytes).hexdigest()
    local_name_low = local_path.name.lower()
    local_stems = _stem_variants(local_path.name)

    # Pre-filter candidate URLs by filename or stem match (fast, avoids downloading unrelated images)
    candidates: list[str] = []
    seen: set[str] = set()
    for u in web_urls:
        if not isinstance(u, str) or not u.lower().startswith(("http://", "https://")):
            continue
        if u in seen:
            continue
        seen.add(u)
        try:
            u_name = urllib.parse.unquote(Path(urllib.parse.urlparse(u).path).name)
        except Exception:
            continue
        if not u_name:
            continue
        if u_name.lower() == local_name_low or bool(local_stems & _stem_variants(u_name)):
            candidates.append(u)

    # Also check any already-cached web hashes even if filename differs
    for u, (sz, h) in list(_WEB_IMAGE_HASH_CACHE.items()):
        if sz == local_size and h == local_hash and u in seen:
            return u

    for u in candidates[:12]:
        cached = _WEB_IMAGE_HASH_CACHE.get(u)
        if cached is not None:
            if cached[0] == local_size and cached[1] == local_hash:
                return u
            continue
        try:
            req = urllib.request.Request(u, headers={"User-Agent": "OdellaSheetEditor/1.0"})
            with urllib.request.urlopen(req, timeout=10) as resp:
                data = resp.read()
            h = hashlib.sha256(data).hexdigest()
            _WEB_IMAGE_HASH_CACHE[u] = (len(data), h)
            if len(data) == local_size and h == local_hash:
                return u
        except Exception:
            continue
    return ""


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
    SETTINGS_PATH.parent.mkdir(parents=True, exist_ok=True)
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
    parts = [p.strip() for p in re.split(r"\s*;\s*", ka_cell) if p.strip()]
    out: list[str] = []
    for p in parts:
        en = KA_TO_EN_LOWER.get(p.lower(), "")
        if en and en not in out:
            out.append(en)
    return "; ".join(out)

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
    parts = re.split(r"\s*(?:;|\||\n)\s*|,\s*(?=https?://)", cell, flags=re.I)
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
    saved_root = str(load_settings().get("last_images_root") or "").strip()
    if saved_root and Path(saved_root).is_dir():
        return saved_root
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
                data.setdefault("paths", {})
                data.setdefault("images_root", detect_default_images_root())
                data.setdefault("sale_discounts", {})
                data.setdefault("var_sale_discounts", {})
                return data
        except Exception:
            pass
    return {
        "paths": {},
        "images_root": detect_default_images_root(),
        "sale_discounts": {},
        "var_sale_discounts": {},
    }


def save_sidecar(xlsx_path: Path, data: dict[str, Any]) -> None:
    sp = sidecar_path(xlsx_path)
    sp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


VIRTUAL_COL_NAMES = {"sale discount"}


def _clean_num_str(val: float) -> str:
    if abs(val - round(val)) < 1e-6:
        return str(int(round(val)))
    return f"{val:.2f}".rstrip("0").rstrip(".")


def _parse_sale_discount(raw: str) -> tuple[str, float, str] | None:
    s = str(raw or "").strip()
    if not s or s.lower() == "(clear)":
        return None
    m = re.match(r"^\s*(\d+(?:[.,]\d+)?)\s*(%?)\s*$", s)
    if not m:
        return None
    num = float(m.group(1).replace(",", "."))
    if num <= 0:
        return None
    is_pct = bool(m.group(2))
    clean = _clean_num_str(num)
    if is_pct:
        if num >= 100:
            return None
        return ("pct", num, f"{clean}%")
    return ("unit", num, clean)


def _compute_sale_from_discount(reg_raw: str, disc_raw: str) -> str:
    try:
        reg = float(str(reg_raw or "").strip().replace(",", "."))
    except ValueError:
        return ""
    if reg <= 0:
        return ""
    parsed = _parse_sale_discount(disc_raw)
    if not parsed:
        return ""
    kind, val, _ = parsed
    if kind == "pct":
        sale = float(round(reg * (1.0 - val / 100.0)))
    else:
        sale = reg - val
    if sale <= 0 or sale >= reg:
        return ""
    return _clean_num_str(sale)


def _derive_unit_discount(reg_raw: str, sale_raw: str) -> str:
    try:
        reg = float(str(reg_raw or "").strip().replace(",", "."))
        sale = float(str(sale_raw or "").strip().replace(",", "."))
    except ValueError:
        return ""
    if reg <= 0 or sale <= 0 or sale >= reg:
        return ""
    return _clean_num_str(reg - sale)


def _strip_virtual_columns(headers: list[str], products: list[list[str]]) -> tuple[list[str], list[list[str]]]:
    keep = [i for i, h in enumerate(headers) if str(h or "").strip().lower() not in VIRTUAL_COL_NAMES]
    if len(keep) == len(headers):
        return headers, products
    clean_headers = [headers[i] for i in keep]
    clean_products = [[row[i] if i < len(row) else "" for i in keep] for row in products]
    return clean_headers, clean_products


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

    # Inject virtual "Sale Discount" column right after "Sale price (GEL)" (stripped on .xlsx save / WP push)
    sale_idx = next((i for i, h in enumerate(headers) if h.strip().lower() == "sale price (gel)"), -1)
    disc_idx = next((i for i, h in enumerate(headers) if h.strip().lower() == "sale discount"), -1)
    if sale_idx >= 0 and disc_idx < 0:
        disc_idx = sale_idx + 1
        headers.insert(disc_idx, "Sale Discount")
        id_col_d = next((i for i, h in enumerate(headers) if h.strip().lower() == "id"), -1)
        sku_col_d = next((i for i, h in enumerate(headers) if h.strip().lower() == "sku"), -1)
        reg_col_d = next((i for i, h in enumerate(headers) if h.strip().lower() == "regular price (gel)"), -1)
        saved_discs = sidecar.get("sale_discounts") if isinstance(sidecar.get("sale_discounts"), dict) else {}
        for row in products:
            row.insert(disc_idx, "")
            reg_val = row[reg_col_d].strip() if 0 <= reg_col_d < len(row) else ""
            sale_val = row[sale_idx].strip() if 0 <= sale_idx < len(row) else ""
            if not sale_val or sale_val.lower() == "(clear)":
                continue
            pid_v = row[id_col_d].strip() if 0 <= id_col_d < len(row) else ""
            if pid_v.endswith(".0"):
                pid_v = pid_v[:-2]
            sku_v = row[sku_col_d].strip().lower() if 0 <= sku_col_d < len(row) else ""
            stored = ""
            if pid_v and f"id:{pid_v}" in saved_discs:
                stored = str(saved_discs[f"id:{pid_v}"] or "").strip()
            elif sku_v and f"sku:{sku_v}" in saved_discs:
                stored = str(saved_discs[f"sku:{sku_v}"] or "").strip()
            if stored:
                expected_sale = _compute_sale_from_discount(reg_val, stored)
                try:
                    cur_sale_f = float(sale_val.replace(",", "."))
                    exp_sale_f = float(expected_sale) if expected_sale else -1.0
                except ValueError:
                    cur_sale_f, exp_sale_f = -1.0, -2.0
                if expected_sale and abs(cur_sale_f - exp_sale_f) < 0.01:
                    parsed = _parse_sale_discount(stored)
                    row[disc_idx] = parsed[2] if parsed else stored
                    continue
            row[disc_idx] = _derive_unit_discount(reg_val, sale_val)

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
        "sale_discounts": sidecar.get("sale_discounts", {}),
        "var_sale_discounts": sidecar.get("var_sale_discounts", {}),
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
        existing_tokens = [t.strip() for t in re.split(r"\s*;\s*", str(row[attr_col] or "")) if t.strip()]
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
        row[attr_col] = "; ".join(merged)


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

    # Strip virtual UI-only columns (e.g. Sale Discount) before writing .xlsx so WordPress schema stays clean
    xlsx_headers, xlsx_products = _strip_virtual_columns(headers, products)

    sheets = [
        ("Products", [xlsx_headers, *xlsx_products]),
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
        "sale_discounts": payload.get("sale_discounts", {}),
        "var_sale_discounts": payload.get("var_sale_discounts", {}),
    })
    return backup_created


def plan_web_image_renames(payload: dict[str, Any]) -> dict[str, Any]:
    headers: list[str] = payload.get("headers") or []
    products: list[list[str]] = [list(r) for r in (payload.get("products") or [])]
    row_filter_raw = payload.get("row_indices")
    row_filter: set[int] | None = set(int(x) for x in row_filter_raw) if isinstance(row_filter_raw, list) and len(row_filter_raw) > 0 else None

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

    # Pass 1: Scan ALL rows so shared web images across products get combined dot-joined names
    url_owners: dict[str, list[tuple[str, str]]] = {}
    url_first_meta: dict[str, dict[str, Any]] = {}
    url_in_scope: set[str] = set()

    for r_idx, row in enumerate(products):
        sku = (row[sku_col] if sku_col >= 0 and sku_col < len(row) else "").strip() or f"row{r_idx + 2}"
        name = (row[name_col] if name_col >= 0 and name_col < len(row) else "").strip() or "product"
        slug = transliterate_slug(name)
        in_scope = (row_filter is None) or (r_idx in row_filter)
        for col, label in [
            (feat_col, "Featured image"),
            (hover_col, "Hover image"),
            (gal_col, "Gallery images"),
        ]:
            if col < 0 or col >= len(row):
                continue
            cell = row[col].strip()
            if not cell:
                continue
            for tok in split_picture_tokens(cell):
                kind, _, _ = parse_token(tok)
                if kind != "url":
                    continue
                key = tok.strip()
                owners = url_owners.setdefault(key, [])
                if (sku, slug) not in owners:
                    owners.append((sku, slug))
                if in_scope:
                    url_in_scope.add(key)
                    if key not in url_first_meta:
                        url_first_meta[key] = {
                            "row": r_idx + 2,
                            "sku": sku,
                            "name": name,
                            "field": label,
                        }

    # Pass 2: Compute expected filename per unique in-scope web image; skip already-matching ones
    owner_next_idx: dict[tuple[tuple[str, str], ...], int] = {}
    used_names: set[str] = set()
    items: list[dict[str, Any]] = []
    skipped_matching = 0

    for url in list(url_owners.keys()):
        if url not in url_in_scope:
            continue
        parsed_path = urllib.parse.unquote(urllib.parse.urlparse(url).path or "")
        cur_name = Path(parsed_path).name
        if not cur_name:
            continue
        ext = Path(cur_name).suffix.lstrip(".").lower() or "jpg"
        owners = url_owners.get(url) or []
        owners_key = tuple(owners)
        base_stem = ".".join(f"{s}.{sl}" for s, sl in owners) if owners else "product"
        if len(base_stem) > 180:
            base_stem = base_stem[:180].rstrip("._-")

        # Check if current filename already matches this product/owners naming pattern
        pattern = re.compile(rf"^{re.escape(base_stem)}_\d+(?:-scaled)?\.{re.escape(ext)}$", re.IGNORECASE)
        if pattern.match(cur_name):
            used_names.add(cur_name.lower())
            skipped_matching += 1
            continue

        idx = owner_next_idx.get(owners_key, 1)
        while True:
            candidate = f"{base_stem}_{idx}.{ext}"
            idx += 1
            if candidate.lower() not in used_names:
                used_names.add(candidate.lower())
                owner_next_idx[owners_key] = idx
                break

        meta = url_first_meta.get(url, {})
        items.append({
            "url": url,
            "current_filename": cur_name,
            "new_filename": candidate,
            "row": meta.get("row", 0),
            "sku": meta.get("sku", ""),
            "name": meta.get("name", ""),
            "field": meta.get("field", ""),
            "shared_count": len(owners),
            "shared_skus": [s for s, _ in owners],
        })

    return {
        "ok": True,
        "items": items,
        "total_web_images": len(url_in_scope),
        "skipped_matching": skipped_matching,
    }


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
    used_zip_names: set[str] = set()

    # Pass 1: Collect every product that uses each physical disk image
    # so shared images across 2+ products get combined "SKU1.Name1.SKU2.Name2_idx.ext"
    file_owners: dict[str, list[tuple[str, str]]] = {}  # resolved_key -> [(sku, slug), ...]
    file_disk_paths: dict[str, Path] = {}

    for r_idx, row in enumerate(products):
        sku = (row[sku_col] if sku_col >= 0 and sku_col < len(row) else "").strip() or f"row{r_idx + 2}"
        name = (row[name_col] if name_col >= 0 and name_col < len(row) else "").strip() or "product"
        slug = transliterate_slug(name)
        for col in (feat_col, hover_col, gal_col):
            if col < 0 or col >= len(row):
                continue
            cell = row[col].strip()
            if not cell:
                continue
            for tok in split_picture_tokens(cell):
                kind, _, _ = parse_token(tok)
                if kind != "file":
                    continue
                disk_path = find_local_file(tok, path_map, images_root)
                if disk_path is None:
                    continue
                key = str(disk_path.resolve()).lower()
                file_disk_paths[key] = disk_path
                owners = file_owners.setdefault(key, [])
                if (sku, slug) not in owners:
                    owners.append((sku, slug))

    # Pass 2: Assign one canonical filename per physical disk image (shared across all owning products)
    assigned_basename: dict[str, str] = {}
    owner_next_idx: dict[tuple[tuple[str, str], ...], int] = {}

    def get_or_assign_basename(key: str, disk_path: Path) -> str:
        if key in assigned_basename:
            return assigned_basename[key]
        owners = file_owners.get(key) or []
        owners_key = tuple(owners)
        idx = owner_next_idx.get(owners_key, 1)
        ext = disk_path.suffix.lstrip(".").lower() or "jpg"
        # Single product: SKU.Slug_1.ext
        # Multiple products sharing same image: SKU1.Slug1.SKU2.Slug2_1.ext
        base_stem = ".".join(f"{s}.{sl}" for s, sl in owners) if owners else "product"
        if len(base_stem) > 180:
            base_stem = base_stem[:180].rstrip("._-")
        while True:
            candidate = f"{base_stem}_{idx}.{ext}"
            idx += 1
            if candidate.lower() not in used_zip_names:
                used_zip_names.add(candidate.lower())
                owner_next_idx[owners_key] = idx
                assigned_basename[key] = candidate
                return candidate

    planned_keys_by_row: set[tuple[int, str]] = set()

    for r_idx, row in enumerate(products):
        sku = (row[sku_col] if sku_col >= 0 and sku_col < len(row) else "").strip() or f"row{r_idx + 2}"
        name = (row[name_col] if name_col >= 0 and name_col < len(row) else "").strip() or "product"

        def process_token(token: str, field_name: str) -> str:
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
            new_basename = get_or_assign_basename(key, disk_path)
            new_token = f"{folder}/{new_basename}" if folder else new_basename
            if (r_idx, key) not in planned_keys_by_row:
                planned_keys_by_row.add((r_idx, key))
                plan_items.append({
                    "row": r_idx + 2,
                    "sku": sku,
                    "name": name,
                    "field": field_name,
                    "old_token": token,
                    "old_path": str(disk_path),
                    "folder": folder,
                    "new_filename": new_basename,
                    "new_token": new_token,
                })
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
            row[col] = "; ".join(new_tokens) if is_multi else (new_tokens[0] if new_tokens else "")

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
FORCE_EXIT_AT: float | None = None

_UPDATE_LOCK = threading.Lock()
_UPDATE_STATE: dict[str, Any] = {
    "state": "idle",  # idle | downloading | verifying | restarting | error
    "pct": 0,
    "message": "",
    "error": "",
}


def parse_semver(tag: str) -> tuple[int, ...]:
    cleaned = re.sub(r"^[vV]", "", str(tag or "").strip())
    cleaned = cleaned.split("-", 1)[0].split("+", 1)[0]
    parts: list[int] = []
    for p in cleaned.split("."):
        m = re.match(r"^(\d+)", p.strip())
        if m:
            parts.append(int(m.group(1)))
        else:
            parts.append(0)
    while len(parts) < 3:
        parts.append(0)
    return tuple(parts)


def cleanup_stale_update_files() -> None:
    if not IS_FROZEN:
        return
    exe_p = Path(sys.executable).resolve()
    for suffix in (".bak", ".new", ".zip.new"):
        cand = exe_p.with_name(exe_p.name + suffix)
        if cand.is_file():
            try:
                cand.unlink()
            except OSError:
                pass


def check_github_update(repo_override: str | None = None) -> dict[str, Any]:
    s = load_settings()
    repo = str(repo_override or s.get("update_repo") or DEFAULT_UPDATE_REPO).strip().strip("/")
    if not repo or "/" not in repo:
        return {
            "ok": False,
            "error": "Invalid GitHub releases repository format. Use owner/repo (e.g. NMeJa/odella-sheet-editor-releases).",
            "current_version": APP_VERSION,
            "is_frozen": IS_FROZEN,
            "repo": repo,
        }
    url = f"https://api.github.com/repos/{repo}/releases/latest"
    req = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": f"OdellaSheetEditor/{APP_VERSION}",
        },
        method="GET",
    )
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            data = json.loads(resp.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as he:
        if he.code == 404:
            return {
                "ok": False,
                "error": f"No published GitHub releases found yet in {repo} (HTTP 404).",
                "current_version": APP_VERSION,
                "is_frozen": IS_FROZEN,
                "repo": repo,
            }
        return {
            "ok": False,
            "error": f"GitHub API HTTP {he.code}: {he.read().decode('utf-8', 'replace')[:220]}",
            "current_version": APP_VERSION,
            "is_frozen": IS_FROZEN,
            "repo": repo,
        }
    except Exception as exc:
        return {
            "ok": False,
            "error": f"Could not reach GitHub: {exc}",
            "current_version": APP_VERSION,
            "is_frozen": IS_FROZEN,
            "repo": repo,
        }

    tag = str(data.get("tag_name") or "").strip()
    latest_ver = re.sub(r"^[vV]", "", tag) or tag
    assets = data.get("assets") or []
    exe_asset = None
    zip_asset = None
    checksum_asset = None
    for a in assets:
        name = str(a.get("name") or "")
        low = name.lower()
        if low == "odellasheeteditor.exe":
            exe_asset = a
        elif low.endswith(".zip") and "odella" in low:
            zip_asset = a
        elif low == "sha256sums.txt":
            checksum_asset = a
    chosen = exe_asset or zip_asset
    update_available = bool(tag) and (parse_semver(latest_ver) > parse_semver(APP_VERSION))
    return {
        "ok": True,
        "current_version": APP_VERSION,
        "latest_version": latest_ver,
        "tag": tag,
        "update_available": update_available,
        "release_name": str(data.get("name") or tag),
        "notes": str(data.get("body") or ""),
        "published_at": str(data.get("published_at") or ""),
        "html_url": str(data.get("html_url") or f"https://github.com/{repo}/releases/latest"),
        "asset_name": str(chosen.get("name") or "") if chosen else "",
        "asset_url": str(chosen.get("browser_download_url") or "") if chosen else "",
        "asset_size": int(chosen.get("size") or 0) if chosen else 0,
        "checksum_url": str(checksum_asset.get("browser_download_url") or "") if checksum_asset else "",
        "is_frozen": IS_FROZEN,
        "repo": repo,
    }


def _set_update_state(state_name: str, pct: int = 0, message: str = "", error: str = "") -> None:
    with _UPDATE_LOCK:
        _UPDATE_STATE["state"] = state_name
        _UPDATE_STATE["pct"] = pct
        _UPDATE_STATE["message"] = message
        _UPDATE_STATE["error"] = error


def _run_update_install(asset_url: str, asset_name: str, checksum_url: str) -> None:
    global FORCE_EXIT_AT
    import subprocess
    import tempfile

    exe_path = Path(sys.executable).resolve()
    is_zip = asset_name.lower().endswith(".zip")
    dl_path = exe_path.with_name(exe_path.name + (".zip.new" if is_zip else ".new"))
    new_exe_path = exe_path.with_name(exe_path.name + ".new")

    try:
        _set_update_state("downloading", 4, f"Downloading {asset_name}...")
        req = urllib.request.Request(
            asset_url,
            headers={"User-Agent": f"OdellaSheetEditor/{APP_VERSION}"},
            method="GET",
        )
        h = hashlib.sha256()
        downloaded = 0
        with urllib.request.urlopen(req, timeout=180) as resp:
            total = int(resp.headers.get("Content-Length") or 0)
            with open(dl_path, "wb") as out_f:
                while True:
                    chunk = resp.read(65536)
                    if not chunk:
                        break
                    out_f.write(chunk)
                    h.update(chunk)
                    downloaded += len(chunk)
                    if total > 0:
                        pct = 5 + int((downloaded / total) * 70)
                        mb_done = downloaded / (1024 * 1024)
                        mb_tot = total / (1024 * 1024)
                        _set_update_state("downloading", min(75, pct), f"Downloading {asset_name} ({mb_done:.1f} / {mb_tot:.1f} MB)...")
                    else:
                        mb_done = downloaded / (1024 * 1024)
                        _set_update_state("downloading", 45, f"Downloading {asset_name} ({mb_done:.1f} MB)...")

        dl_sha = h.hexdigest().lower()

        if checksum_url:
            _set_update_state("verifying", 80, "Verifying SHA-256 checksum...")
            cs_req = urllib.request.Request(
                checksum_url,
                headers={"User-Agent": f"OdellaSheetEditor/{APP_VERSION}"},
                method="GET",
            )
            with urllib.request.urlopen(cs_req, timeout=30) as cs_resp:
                cs_text = cs_resp.read().decode("utf-8", "replace")
            expected_sha = ""
            for line in cs_text.splitlines():
                parts = line.strip().split()
                if len(parts) >= 2 and parts[-1].lstrip("*").lower() == asset_name.lower():
                    expected_sha = parts[0].lower()
                    break
            if expected_sha and expected_sha != dl_sha:
                raise RuntimeError("SHA-256 checksum mismatch! Download may be corrupted — update aborted.")

        if is_zip:
            _set_update_state("verifying", 86, "Extracting OdellaSheetEditor.exe...")
            with zipfile.ZipFile(dl_path, "r") as zf:
                member = next((m for m in zf.namelist() if Path(m).name.lower() == "odellasheeteditor.exe"), None)
                if not member:
                    raise RuntimeError("OdellaSheetEditor.exe not found inside downloaded zip.")
                with zf.open(member) as src_f, open(new_exe_path, "wb") as dst_f:
                    shutil.copyfileobj(src_f, dst_f)
            try:
                dl_path.unlink()
            except OSError:
                pass

        _set_update_state("verifying", 92, "Validating executable integrity...")
        if not new_exe_path.is_file() or new_exe_path.stat().st_size < 1_000_000:
            raise RuntimeError("Downloaded executable is unexpectedly small — update aborted.")
        with open(new_exe_path, "rb") as vf:
            magic = vf.read(2)
        if magic != b"MZ":
            raise RuntimeError("Downloaded file is not a valid Windows executable — update aborted.")

        _set_update_state("restarting", 98, "Installing update and restarting app...")
        bak_path = exe_path.with_name(exe_path.name + ".bak")
        script_path = Path(tempfile.gettempdir()) / f"odella_update_{os.getpid()}.cmd"
        cmd_body = (
            "@echo off\r\n"
            "setlocal\r\n"
            f'set "TARGET={exe_path}"\r\n'
            f'set "NEWEXE={new_exe_path}"\r\n'
            f'set "BAKEXE={bak_path}"\r\n'
            "set /a TRIES=0\r\n"
            ":WAITLOOP\r\n"
            "ping -n 1 -w 300 127.0.0.1 >nul\r\n"
            'if exist "%BAKEXE%" del /f /q "%BAKEXE%" >nul 2>&1\r\n'
            'move /y "%TARGET%" "%BAKEXE%" >nul 2>&1\r\n'
            "if errorlevel 1 (\r\n"
            "  set /a TRIES+=1\r\n"
            "  if %TRIES% LSS 60 goto WAITLOOP\r\n"
            "  exit /b 1\r\n"
            ")\r\n"
            'move /y "%NEWEXE%" "%TARGET%" >nul 2>&1\r\n'
            "if errorlevel 1 (\r\n"
            '  move /y "%BAKEXE%" "%TARGET%" >nul 2>&1\r\n'
            "  exit /b 1\r\n"
            ")\r\n"
            'start "" "%TARGET%"\r\n'
            "ping -n 2 127.0.0.1 >nul\r\n"
            'if exist "%BAKEXE%" del /f /q "%BAKEXE%" >nul 2>&1\r\n'
            'del /f /q "%~f0" >nul 2>&1\r\n'
        )
        script_path.write_text(cmd_body, encoding="utf-8")

        DETACHED_PROCESS = 0x00000008
        CREATE_NEW_PROCESS_GROUP = 0x00000200
        CREATE_NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0x08000000)
        subprocess.Popen(
            ["cmd.exe", "/c", str(script_path)],
            creationflags=DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP | CREATE_NO_WINDOW,
            close_fds=True,
        )
        FORCE_EXIT_AT = time.monotonic()
    except Exception as exc:
        for p in (dl_path, new_exe_path):
            if p.is_file():
                try:
                    p.unlink()
                except OSError:
                    pass
        _set_update_state("error", 0, "", str(exc))


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

    def _touch_activity(self) -> None:
        global LAST_PING, HAS_CLIENT_CONNECTED, CLOSING_AT
        LAST_PING = time.monotonic()
        HAS_CLIENT_CONNECTED = True
        CLOSING_AT = None

    def do_GET(self) -> None:
        self._touch_activity()
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
                self._send_json({"ok": True})
                return

            if path == "/api/settings":
                self._send_json({"ok": True, "settings": load_settings(), "default_prompt": DEFAULT_GEMINI_PROMPT})
                return

            if path == "/api/browse-folder":
                if not _BROWSE_LOCK.acquire(blocking=False):
                    self._send_json({"ok": False, "cancelled": True, "busy": True})
                    return
                try:
                    initial = (qs.get("initial") or [""])[0]
                    chosen = native_browse_folder_dialog(initial)
                    if not chosen:
                        self._send_json({"ok": False, "cancelled": True})
                        return
                    self._send_json({"ok": True, "path": chosen})
                finally:
                    _BROWSE_LOCK.release()
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
                remember_recent_file(str(fp))
                save_settings({"last_opened_file": str(fp)})
                self._send_json(load_workbook_payload(fp))
                return

            if path == "/api/local-images":
                root_param = (qs.get("root") or [detect_default_images_root()])[0]
                root = Path(root_param)
                if root.is_dir():
                    save_settings({"last_images_root": str(root)})
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
                self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
                self.end_headers()
                self.wfile.write(data)
                return

            if path == "/api/proxy-image":
                img_url = (qs.get("url") or [""])[0].strip()
                if not re.match(r"^https?://", img_url, re.I):
                    self.send_error(HTTPStatus.BAD_REQUEST, "Invalid URL")
                    return
                req = urllib.request.Request(img_url, headers={"User-Agent": "OdellaSheetEditor/1.0"})
                try:
                    with urllib.request.urlopen(req, timeout=25) as resp:
                        data = resp.read()
                        ctype = resp.headers.get("Content-Type") or "image/jpeg"
                    self.send_response(200)
                    self.send_header("Content-Type", ctype)
                    self.send_header("Content-Length", str(len(data)))
                    self.send_header("Cache-Control", "max-age=300")
                    self.end_headers()
                    self.wfile.write(data)
                except Exception as exc:
                    self.send_error(HTTPStatus.BAD_GATEWAY, str(exc))
                return

            if path == "/api/wp/speed-check":
                res = wp_rest_request("GET", "speed-check", timeout=60)
                self._send_json(res)
                return

            if path == "/api/app-info":
                s = load_settings()
                self._send_json({
                    "ok": True,
                    "version": APP_VERSION,
                    "is_frozen": IS_FROZEN,
                    "update_repo": str(s.get("update_repo") or DEFAULT_UPDATE_REPO),
                })
                return

            if path == "/api/update/check":
                repo_q = (qs.get("repo") or [""])[0].strip() or None
                self._send_json(check_github_update(repo_q))
                return

            if path == "/api/update/status":
                with _UPDATE_LOCK:
                    st = dict(_UPDATE_STATE)
                self._send_json({"ok": True, **st})
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
        self._touch_activity()
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
                ext = Path(filename).suffix.lower()
                if ext not in (".xlsx", ".csv"):
                    self._send_json({"ok": False, "error": "Only .xlsx and .csv files are supported."}, 400)
                    return

                EXCEL_DIR.mkdir(parents=True, exist_ok=True)
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
                remember_recent_file(str(dest))
                save_settings({"last_opened_file": str(dest)})
                wb = load_workbook_payload(dest)
                self._send_json({"ok": True, "mode": "open", "workbook": wb})
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
                            row[gal_c] = "; ".join(tokens)

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
                remember_recent_file(str(target))
                save_settings({"last_opened_file": str(target)})
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

            if path == "/api/match-web-image":
                lp = str(body.get("local_path") or "").strip()
                web_urls = body.get("web_urls") or []
                matched_url = match_local_to_web_image(Path(lp), web_urls) if lp else ""
                self._send_json({"ok": True, "matched": bool(matched_url), "url": matched_url})
                return

            if path == "/api/save-edited-image":
                b64 = str(body.get("data_base64") or "")
                if not b64:
                    self._send_json({"ok": False, "error": "Empty image data."}, 400)
                    return
                raw = base64.b64decode(b64)
                orig_path_str = str(body.get("original_path") or "").strip()
                orig_p = Path(orig_path_str) if orig_path_str else None
                ext = str(body.get("ext") or "jpg").lstrip(".").lower()
                if ext not in ("jpg", "jpeg", "png", "webp"):
                    ext = "jpg"
                overwrite_orig = bool(body.get("overwrite_original", False))
                images_root = str(body.get("images_root") or detect_default_images_root())

                if orig_p is not None and orig_p.is_file():
                    target_dir = orig_p.parent
                    stem = orig_p.stem
                else:
                    raw_name = Path(str(body.get("filename") or "image.jpg")).name
                    stem = Path(raw_name).stem or "image"
                    # Strip any existing _2x3 suffix to avoid stacking
                    stem = re.sub(r"_2x3(?:_\d+)?$", "", stem)
                    try:
                        target_dir = Path(images_root) / "_fixed_images"
                        target_dir.mkdir(parents=True, exist_ok=True)
                    except OSError:
                        target_dir = EXCEL_DIR / "_fixed_images"
                        target_dir.mkdir(parents=True, exist_ok=True)

                clean_stem = re.sub(r"_2x3(?:_\d+)?$", "", stem)
                conflict_mode = str(body.get("conflict_mode") or "ask").strip().lower()
                base_p = target_dir / f"{clean_stem}_2x3.{ext}"

                # Always compute the first available incremented path (_2x3_2, _2x3_3, ...)
                inc_p = base_p
                if inc_p.exists():
                    n = 2
                    while True:
                        cand = target_dir / f"{clean_stem}_2x3_{n}.{ext}"
                        if not cand.exists():
                            inc_p = cand
                            break
                        n += 1

                if base_p.exists() and conflict_mode == "ask":
                    self._send_json({
                        "ok": False,
                        "conflict": True,
                        "existing_path": str(base_p),
                        "existing_name": base_p.name,
                        "incremented_path": str(inc_p),
                        "incremented_name": inc_p.name,
                        "folder": str(target_dir),
                    })
                    return

                copy_p = inc_p if (base_p.exists() and conflict_mode == "increment") else base_p
                copy_p.write_bytes(raw)

                overwritten = False
                if overwrite_orig and orig_p is not None and orig_p.is_file():
                    if orig_p.resolve() != copy_p.resolve():
                        orig_p.write_bytes(raw)
                    overwritten = True

                self._send_json({
                    "ok": True,
                    "copy_path": str(copy_p),
                    "copy_name": copy_p.name,
                    "original_path": str(orig_p) if orig_p else "",
                    "overwritten_original": overwritten,
                })
                return

            if path == "/api/resolve-folder":
                folder_name = str(body.get("folder_name") or "").strip()
                sample_files = [str(x) for x in (body.get("sample_files") or []) if x]
                hints = [str(x) for x in (body.get("hints") or []) if x]
                found = resolve_dropped_folder(folder_name, sample_files, hints)
                if found is None or not found.is_dir():
                    self._send_json({"ok": False, "error": f"Could not locate folder '{folder_name}' on disk. Click Browse to select it directly."}, 404)
                    return
                self._send_json({"ok": True, "path": str(found)})
                return

            if path == "/api/rename-local-image":
                old_path_str = str(body.get("old_path") or "").strip()
                new_name_raw = str(body.get("new_name") or "").strip()
                if not old_path_str or not new_name_raw:
                    self._send_json({"ok": False, "error": "Missing file path or new filename."}, 400)
                    return
                old_p = Path(old_path_str)
                if not old_p.is_file() or old_p.suffix.lower() not in IMAGE_EXTS:
                    self._send_json({"ok": False, "error": f"Original image not found: {old_p}"}, 404)
                    return
                if any(ch in new_name_raw for ch in '<>:"/\\|?*'):
                    self._send_json({"ok": False, "error": "Filename contains invalid characters."}, 400)
                    return
                new_name = Path(new_name_raw).name
                if not Path(new_name).suffix:
                    new_name = f"{new_name}{old_p.suffix}"
                elif Path(new_name).suffix.lower() not in IMAGE_EXTS:
                    self._send_json({"ok": False, "error": f"Unsupported image extension: {Path(new_name).suffix}"}, 400)
                    return
                new_p = old_p.parent / new_name
                if new_p == old_p:
                    self._send_json({"ok": True, "old_path": str(old_p), "new_path": str(new_p), "old_name": old_p.name, "new_name": new_p.name})
                    return
                if new_p.exists() and str(new_p).lower() != str(old_p).lower():
                    self._send_json({"ok": False, "error": f"A file named '{new_name}' already exists in this folder."}, 409)
                    return
                old_p.rename(new_p)
                self._send_json({
                    "ok": True,
                    "old_path": str(old_p),
                    "new_path": str(new_p),
                    "old_name": old_p.name,
                    "new_name": new_p.name,
                })
                return

            if path == "/api/wp/test":
                res = wp_rest_request(
                    "GET",
                    "ping",
                    site_url=body.get("wp_site_url"),
                    username=body.get("wp_username"),
                    app_password=body.get("wp_app_password"),
                    timeout=30,
                )
                self._send_json(res)
                return

            if path == "/api/wp/pull":
                cols = body.get("columns") or []
                res = wp_rest_request("POST", "pull", {"columns": cols}, timeout=150)
                if not res.get("ok") or not res.get("data_base64"):
                    self._send_json(res)
                    return
                raw = base64.b64decode(res["data_base64"])
                fname = Path(str(res.get("filename") or f"odella-products-{datetime.datetime.now().strftime('%Y%m%d-%H%M%S')}.xlsx")).name
                EXCEL_DIR.mkdir(parents=True, exist_ok=True)
                dest = EXCEL_DIR / fname
                if dest.exists():
                    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
                    dest = EXCEL_DIR / f"{dest.stem}-{stamp}.xlsx"
                dest.write_bytes(raw)
                remember_recent_file(str(dest))
                save_settings({"last_opened_file": str(dest)})
                self._send_json({"ok": True, "file": str(dest), "filename": dest.name})
                return

            if path == "/api/wp/push-start":
                upload_images = bool(body.get("upload_images", False))
                trash_missing = bool(body.get("trash_missing", False))
                settings = load_settings()
                batch_size = int(body.get("batch_size") or settings.get("push_batch_size") or 10)
                batch_size = max(5, min(25, batch_size))
                changed_only = body.get("changed_only_indices")

                products_all = [list(r) for r in (body.get("products") or [])]
                paths_map = dict(body.get("paths") or {})
                zip_b64 = ""
                zip_name = ""
                updated_products = None
                updated_paths = None

                if upload_images:
                    zip_res = plan_and_execute_fix_and_zip({
                        "headers": body.get("headers") or [],
                        "products": products_all,
                        "paths": paths_map,
                        "images_root": body.get("images_root") or detect_default_images_root(),
                        "preview": False,
                        "rename_originals": False,
                        "file": body.get("file") or str(EXCEL_DIR / "odella-products.xlsx"),
                    })
                    if zip_res.get("ok"):
                        products_all = zip_res.get("products") or products_all
                        paths_map = zip_res.get("paths") or paths_map
                        updated_products = products_all
                        updated_paths = paths_map
                        zp = Path(str(zip_res.get("zip_path") or ""))
                        if zp.is_file() and int(zip_res.get("total") or 0) > 0:
                            zip_b64 = base64.b64encode(zp.read_bytes()).decode("ascii")
                            zip_name = zp.name

                headers = list(body.get("headers") or [])
                var_headers = list(body.get("variation_headers") or [])
                variations_all = [list(r) for r in (body.get("variations") or [])]

                if isinstance(changed_only, list) and len(changed_only) > 0:
                    idx_set = {int(i) for i in changed_only if 0 <= int(i) < len(products_all)}
                    push_products = [products_all[i] for i in sorted(idx_set) if any(str(c or "").strip() for c in products_all[i])]
                    # Filter variations to only those belonging to pushed products
                    id_c = next((i for i, h in enumerate(headers) if h.strip().lower() == "id"), -1)
                    sku_c = next((i for i, h in enumerate(headers) if h.strip().lower() == "sku"), -1)
                    v_pid_c = next((i for i, h in enumerate(var_headers) if h.strip().lower() == "parent id"), -1)
                    v_psku_c = next((i for i, h in enumerate(var_headers) if h.strip().lower() == "parent sku"), -1)
                    kept_ids = {str(r[id_c] or "").strip() for r in push_products if id_c >= 0 and str(r[id_c] or "").strip()}
                    kept_skus = {str(r[sku_c] or "").strip().lower() for r in push_products if sku_c >= 0 and str(r[sku_c] or "").strip()}
                    push_variations = []
                    for vr in variations_all:
                        vpid = str(vr[v_pid_c] or "").strip() if 0 <= v_pid_c < len(vr) else ""
                        vpsku = str(vr[v_psku_c] or "").strip().lower() if 0 <= v_psku_c < len(vr) else ""
                        if (vpid and vpid in kept_ids) or (vpsku and vpsku in kept_skus):
                            push_variations.append(vr)
                    trash_missing = False
                else:
                    push_products = [r for r in products_all if any(str(c or "").strip() for c in r)]
                    push_variations = variations_all

                tmp_xlsx = EXCEL_DIR / f"._tmp_push_{int(time.time())}.xlsx"
                push_headers, clean_push_products = _strip_virtual_columns(headers, push_products)
                try:
                    write_xlsx(tmp_xlsx, [
                        ("Products", [push_headers, *clean_push_products]),
                        ("Variations", [var_headers, *push_variations]),
                    ])
                    xlsx_b64 = base64.b64encode(tmp_xlsx.read_bytes()).decode("ascii")
                finally:
                    if tmp_xlsx.exists():
                        tmp_xlsx.unlink()

                res = wp_rest_request("POST", "push-start", {
                    "xlsx_base64": xlsx_b64,
                    "zip_base64": zip_b64,
                    "batch_size": batch_size,
                    "trash_missing": trash_missing,
                }, timeout=180)
                if updated_products is not None:
                    res["updated_products"] = updated_products
                    res["updated_paths"] = updated_paths
                    res["zip_name"] = zip_name
                self._send_json(res)
                return

            if path == "/api/wp/push-step":
                res = wp_rest_request("POST", "push-step", {"job_id": body.get("job_id")}, timeout=120)
                self._send_json(res)
                return

            if path == "/api/wp/purge-reindex":
                s = load_settings()
                res = wp_rest_request("POST", "purge-reindex", {
                    "hostinger_api_token": s.get("hostinger_api_token") or "",
                    "hostinger_username": s.get("hostinger_username") or "u981153228",
                }, timeout=120)
                self._send_json(res)
                return

            if path == "/api/wp/warm-step":
                res = wp_rest_request("POST", "warm-step", {
                    "warm_token": body.get("warm_token"),
                    "offset": body.get("offset", 0),
                    "limit": body.get("limit", 6),
                }, timeout=90)
                self._send_json(res)
                return

            if path == "/api/wp/create-category":
                res = wp_rest_request("POST", "create-category", {
                    "name_ka": body.get("name_ka"),
                    "name_en": body.get("name_en"),
                    "parent_ka_id": body.get("parent_ka_id", 0),
                }, timeout=60)
                self._send_json(res)
                return

            if path == "/api/plan-web-rename":
                res = plan_web_image_renames(body)
                self._send_json(res)
                return

            if path == "/api/wp/rename-web-images":
                items = body.get("items") or []
                res = wp_rest_request("POST", "rename-web-images", {"items": items}, timeout=180)
                self._send_json(res)
                return

            if path == "/api/update/install":
                if not IS_FROZEN:
                    self._send_json({
                        "ok": False,
                        "error": "You are running from source (python server.py). Update with 'git pull' instead.",
                    })
                    return
                if os.name != "nt":
                    self._send_json({"ok": False, "error": "Automatic self-update is only supported on Windows."})
                    return
                with _UPDATE_LOCK:
                    if _UPDATE_STATE.get("state") in ("downloading", "verifying", "restarting"):
                        self._send_json({"ok": True, "started": True, "already_running": True})
                        return
                asset_url = str(body.get("asset_url") or "").strip()
                asset_name = str(body.get("asset_name") or "").strip()
                checksum_url = str(body.get("checksum_url") or "").strip()
                if not asset_url or not asset_name:
                    chk = check_github_update()
                    if not chk.get("ok"):
                        self._send_json(chk)
                        return
                    asset_url = str(chk.get("asset_url") or "")
                    asset_name = str(chk.get("asset_name") or "")
                    checksum_url = str(chk.get("checksum_url") or "")
                if not asset_url:
                    self._send_json({"ok": False, "error": "No downloadable Windows release asset found on GitHub."})
                    return
                _set_update_state("downloading", 1, f"Starting download of {asset_name}...")
                t = threading.Thread(
                    target=_run_update_install,
                    args=(asset_url, asset_name, checksum_url),
                    daemon=True,
                )
                t.start()
                self._send_json({"ok": True, "started": True})
                return

            self.send_error(HTTPStatus.NOT_FOUND)
        except Exception as exc:
            self._send_json({"error": str(exc)}, 500)

    def _serve_static(self, rel_path: str, content_type: str) -> None:
        fp = (STATIC_DIR / rel_path).resolve()
        if not str(fp).startswith(str(STATIC_DIR.resolve())) or not fp.is_file():
            self.send_error(HTTPStatus.NOT_FOUND)
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

    cleanup_stale_update_files()

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
    # - 15m heartbeat fallback if browser process is killed abruptly (safe against Chromium background timer throttling)
    try:
        started_at = time.monotonic()
        while True:
            time.sleep(0.5)
            now = time.monotonic()
            if FORCE_EXIT_AT is not None and (now - FORCE_EXIT_AT) > 0.7:
                break
            if CLOSING_AT is not None and (now - CLOSING_AT) > 2.5:
                break
            if HAS_CLIENT_CONNECTED and (now - LAST_PING) > 900.0:
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
