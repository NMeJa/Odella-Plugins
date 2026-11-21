#!/usr/bin/env python3
"""Build a standalone Windows .exe publish package for Odella Product Sheet Editor."""

from __future__ import annotations

import shutil
import struct
import subprocess
import sys
import zipfile
from pathlib import Path

EDITOR_DIR = Path(__file__).resolve().parent
DIST_DIR = EDITOR_DIR / "dist"
BUILD_DIR = EDITOR_DIR / "build"
ICON_PATH = EDITOR_DIR / "odella.ico"
ZIP_OUT = DIST_DIR / "Odella-Sheet-Editor-Windows.zip"


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

    try:
        shutil.copy2(exe_path, DIST_DIR / "OdellaSheetEditor.exe")
    except PermissionError:
        print("Note: dist/OdellaSheetEditor.exe is currently running; updated zip cleanly.")

    readme_path = DIST_DIR / "README.txt"
    readme_path.write_text(README_TEXT, encoding="utf-8")

    with zipfile.ZipFile(ZIP_OUT, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        zf.write(exe_path, arcname="OdellaSheetEditor.exe")
        zf.write(readme_path, arcname="README.txt")

    size_mb = ZIP_OUT.stat().st_size / (1024 * 1024)
    print(f"Publish package ready: {ZIP_OUT} ({size_mb:.1f} MB)")


if __name__ == "__main__":
    main()
