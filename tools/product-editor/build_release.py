#!/usr/bin/env python3
"""Build a standalone Windows .exe publish package for Odella Product Sheet Editor."""

from __future__ import annotations

import argparse
import base64
import hashlib
import re
import shutil
import struct
import subprocess
import sys
import zipfile
from pathlib import Path

EDITOR_DIR = Path(__file__).resolve().parent
SERVER_PY = EDITOR_DIR / "server.py"
DIST_DIR = EDITOR_DIR / "dist"
BUILD_DIR = EDITOR_DIR / "build"
ICON_PATH = EDITOR_DIR / "odella.ico"
ZIP_OUT = DIST_DIR / "Odella-Sheet-Editor-Windows.zip"
CHECKSUMS_OUT = DIST_DIR / "SHA256SUMS.txt"
DEFAULT_RELEASE_REPO = "NMeJa/odella-sheet-editor-releases"


def read_app_version() -> str:
    src = SERVER_PY.read_text(encoding="utf-8")
    m = re.search(r'^APP_VERSION\s*=\s*"([^"]+)"', src, re.M)
    if not m:
        raise SystemExit("Could not find APP_VERSION in server.py")
    return m.group(1).strip()


def write_app_version(new_version: str) -> str:
    clean = re.sub(r"^[vV]", "", new_version.strip())
    if not re.match(r"^\d+\.\d+\.\d+$", clean):
        raise SystemExit(f"Invalid version '{new_version}'. Expected semantic version like 1.2.0.")
    src = SERVER_PY.read_text(encoding="utf-8")
    updated, count = re.subn(
        r'^(APP_VERSION\s*=\s*)"[^"]+"',
        rf'\g<1>"{clean}"',
        src,
        count=1,
        flags=re.M,
    )
    if count != 1:
        raise SystemExit("Failed to update APP_VERSION in server.py")
    SERVER_PY.write_text(updated, encoding="utf-8")
    return clean


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        while True:
            chunk = f.read(65536)
            if not chunk:
                break
            h.update(chunk)
    return h.hexdigest()


def ensure_repo_has_initial_commit(repo: str) -> None:
    """GitHub Releases require at least one commit on the default branch."""
    chk_commits = subprocess.run(
        ["gh", "api", f"repos/{repo}/commits?per_page=1"],
        capture_output=True,
        text=True,
    )
    if chk_commits.returncode == 0 and chk_commits.stdout.strip().startswith("["):
        return
    print(f"Repository {repo} is empty; creating initial README.md commit...")
    readme_b64 = base64.b64encode(
        (
            "# Odella Product Sheet Editor — Releases\n\n"
            "Public release binaries for **Odella Product Sheet Editor (Windows)**.\n\n"
            "Download the latest `Odella-Sheet-Editor-Windows.zip` from the **Releases** tab, "
            "or update directly inside the app via the topbar **Update** button.\n"
        ).encode("utf-8")
    ).decode("ascii")
    subprocess.run(
        [
            "gh", "api", f"repos/{repo}/contents/README.md",
            "-X", "PUT",
            "-f", "message=Initialize releases repository",
            "-f", f"content={readme_b64}",
        ],
        check=True,
    )


def publish_github_release(repo: str, version: str, exe_path: Path, zip_path: Path, checksums_path: Path, notes: str) -> None:
    if not shutil.which("gh"):
        raise SystemExit("GitHub CLI ('gh') not found in PATH. Install gh and run 'gh auth login' first.")
    tag = f"v{version}"
    print(f"Checking GitHub releases repository: {repo} ...")
    chk_repo = subprocess.run(["gh", "repo", "view", repo], capture_output=True, text=True)
    if chk_repo.returncode != 0:
        print(f"Repository {repo} does not exist yet. Creating public releases repo...")
        subprocess.run(
            [
                "gh", "repo", "create", repo,
                "--public",
                "--add-readme",
                "--description", "Public compiled Windows releases for Odella Product Sheet Editor",
            ],
            check=True,
        )
    ensure_repo_has_initial_commit(repo)

    release_notes = notes.strip() or (
        f"Odella Product Sheet Editor {tag}\n\n"
        "- Download `Odella-Sheet-Editor-Windows.zip` for fresh installs.\n"
        "- Existing users can update automatically inside the app via the topbar Update button."
    )
    chk_rel = subprocess.run(["gh", "release", "view", tag, "--repo", repo], capture_output=True, text=True)
    if chk_rel.returncode == 0:
        print(f"Release {tag} already exists in {repo}; updating assets with --clobber...")
        subprocess.run(
            [
                "gh", "release", "upload", tag,
                str(exe_path), str(zip_path), str(checksums_path),
                "--clobber",
                "--repo", repo,
            ],
            check=True,
        )
    else:
        print(f"Creating GitHub Release {tag} in {repo}...")
        subprocess.run(
            [
                "gh", "release", "create", tag,
                str(exe_path), str(zip_path), str(checksums_path),
                "--repo", repo,
                "--title", f"Odella Sheet Editor {tag}",
                "--notes", release_notes,
            ],
            check=True,
        )
    print(f"Published {tag} to https://github.com/{repo}/releases/tag/{tag}")


def generate_icon(path: Path) -> None:
    """Generate a crisp 32x32 32-bit BGRA Windows .ico with Odella emerald/navy O emblem."""
    size = 32
    pixels = bytearray()
    cx, cy = (size - 1) / 2.0, (size - 1) / 2.0
    # ICO BMP data is bottom-up
    for y in range(size - 1, -1, -1):
        for x in range(size):
            dx, dy = x - cx, y - cy
            dist = (dx * dx + dy * dy) ** 0.5
            # Rounded square dark navy background (#0f172a) with emerald ring (#10b981)
            in_bg = abs(dx) <= 14.5 and abs(dy) <= 14.5 and (abs(dx) + abs(dy) <= 25.0)
            if not in_bg:
                pixels.extend((0, 0, 0, 0))
            elif 6.2 <= dist <= 10.6:
                pixels.extend((129, 185, 16, 255))  # BGRA for #10b981
            elif 12.6 <= dist <= 13.8:
                pixels.extend((246, 130, 59, 180))  # subtle accent border
            else:
                pixels.extend((42, 23, 15, 255))    # BGRA for #0f172a

    and_mask = bytes(((size + 31) // 32) * 4 * size)
    bmp_header = struct.pack(
        "<IIIHHIIIIII",
        40,              # biSize
        size,            # biWidth
        size * 2,        # biHeight (XOR + AND)
        1,               # biPlanes
        32,              # biBitCount
        0,               # biCompression (BI_RGB)
        len(pixels) + len(and_mask),
        0, 0, 0, 0,
    )
    img_data = bmp_header + bytes(pixels) + and_mask
    ico_header = struct.pack("<HHH", 0, 1, 1)
    ico_entry = struct.pack(
        "<BBBBHHII",
        size, size, 0, 0, 1, 32, len(img_data), 6 + 16,
    )
    path.write_bytes(ico_header + ico_entry + img_data)


README_TEXT = """ODELLA PRODUCT SHEET EDITOR (WINDOWS)
=====================================

HOW TO USE (ENGLISH):
1. Extract this ZIP folder anywhere on your computer (e.g. Desktop or Documents).
2. Double-click "OdellaSheetEditor.exe" — it opens directly as a standalone desktop app.
3. Click "Import" (top-left) to open any .xlsx spreadsheet exported from odella.ge,
   or drop .xlsx files directly into the "Odella-Workbooks" folder next to the app.
4. When finished, click "Save" (and "Fix Naming & Zip" if you added local images),
   then upload the .xlsx + .zip back to odella.ge -> Product spreadsheet.
5. Simply close the app window (X) when done — it shuts down automatically.

გამოყენების ინსტრუქცია (ქართული):
1. ამოაარქივეთ ეს ZIP ფაილი ნებისმიერ ადგილას (მაგ. Desktop-ზე).
2. ორჯერ დააკლიკეთ "OdellaSheetEditor.exe"-ს — აპლიკაცია პირდაპირ გაიხსნება ცალკე ფანჯარაში.
3. დააკლიკეთ "Import"-ს საიტიდან ჩამოტვირთული .xlsx ფაილის გასახსნელად.
   პარამეტრებში (⚙) შეგიძლიათ აირჩიოთ ქართული ინტერფეისი და Light/Dark რეჟიმი.
4. დასრულებისას დააკლიკეთ "Save" (და "Fix Naming & Zip" თუ ახალი ფოტოები დაამატეთ)
   და ატვირთეთ საიტზე (Product spreadsheet).
5. მუშაობის დასრულების შემდეგ უბრალოდ დახურეთ ფანჯარა (X).
"""


def main() -> None:
    parser = argparse.ArgumentParser(description="Build (and optionally publish) Odella Product Sheet Editor for Windows.")
    parser.add_argument("--version", default="", help="Optional new version (e.g. 1.2.0) to write into server.py before building")
    parser.add_argument("--publish", action="store_true", help="Publish built .exe, .zip & SHA256SUMS.txt to GitHub Releases via gh CLI")
    parser.add_argument("--publish-only", action="store_true", help="Skip PyInstaller build and publish existing dist/ artifacts to GitHub Releases")
    parser.add_argument("--repo", default=DEFAULT_RELEASE_REPO, help=f"GitHub releases repo (default: {DEFAULT_RELEASE_REPO})")
    parser.add_argument("--notes", default="", help="Optional release notes for GitHub Release")
    args = parser.parse_args()

    version = write_app_version(args.version) if args.version else read_app_version()
    if args.publish_only:
        exe_path = DIST_DIR / "OdellaSheetEditor.exe"
        if not exe_path.is_file():
            exe_path = BUILD_DIR / "stage" / "OdellaSheetEditor.exe"
        if not exe_path.is_file() or not ZIP_OUT.is_file() or not CHECKSUMS_OUT.is_file():
            raise SystemExit("Existing build artifacts not found in dist/. Run without --publish-only first.")
        publish_github_release(args.repo, version, exe_path, ZIP_OUT, CHECKSUMS_OUT, args.notes)
        return

    print(f"Building Odella Product Sheet Editor v{version}...")

    generate_icon(ICON_PATH)
    stage_dir = BUILD_DIR / "stage"
    stage_dir.mkdir(parents=True, exist_ok=True)
    DIST_DIR.mkdir(parents=True, exist_ok=True)
    cmd = [
        sys.executable, "-m", "PyInstaller",
        "--noconfirm",
        "--clean",
        "--onefile",
        "--windowed",
        "--name", "OdellaSheetEditor",
        "--icon", str(ICON_PATH),
        "--add-data", f"{EDITOR_DIR / 'static'};static",
        "--distpath", str(stage_dir),
        "--workpath", str(BUILD_DIR),
        "--specpath", str(BUILD_DIR),
        str(EDITOR_DIR / "server.py"),
    ]
    print("Running:", " ".join(cmd))
    subprocess.run(cmd, check=True)

    exe_path = stage_dir / "OdellaSheetEditor.exe"
    if not exe_path.is_file():
        raise SystemExit("Build failed: OdellaSheetEditor.exe not found.")

    dist_exe = DIST_DIR / "OdellaSheetEditor.exe"
    try:
        shutil.copy2(exe_path, dist_exe)
    except PermissionError:
        print("Note: dist/OdellaSheetEditor.exe is currently running; updated zip cleanly.")
        dist_exe = exe_path

    readme_path = DIST_DIR / "README.txt"
    readme_path.write_text(README_TEXT, encoding="utf-8")

    with zipfile.ZipFile(ZIP_OUT, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        zf.write(exe_path, arcname="OdellaSheetEditor.exe")
        zf.write(readme_path, arcname="README.txt")

    checksum_lines = [
        f"{sha256_file(exe_path)}  OdellaSheetEditor.exe",
        f"{sha256_file(ZIP_OUT)}  {ZIP_OUT.name}",
    ]
    CHECKSUMS_OUT.write_text("\n".join(checksum_lines) + "\n", encoding="utf-8")

    size_mb = ZIP_OUT.stat().st_size / (1024 * 1024)
    print(f"Publish package ready (v{version}): {ZIP_OUT} ({size_mb:.1f} MB)")
    print(f"Checksums written: {CHECKSUMS_OUT}")

    if args.publish:
        publish_github_release(args.repo, version, exe_path, ZIP_OUT, CHECKSUMS_OUT, args.notes)


if __name__ == "__main__":
    main()
