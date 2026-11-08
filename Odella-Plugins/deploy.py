#!/usr/bin/env python3
"""
Odella Elementor Widgets — Local-First Build, Sync & Atomic Deployment Tool

Workflow:
  1. Always edit files locally in `c:\\Users\\Nick\\Projects\\Hostinger-Connect\\Odella-Plugins`.
  2. Run `python deploy.py --check` to compare local files against the live server.
  3. Run `python deploy.py` to bump the patch version, update `OdellaPlugins.zip`,
     upload and atomically replace the remote plugin directory, lint PHP files,
     flush Elementor/WP/LiteSpeed caches, and verify 100% SHA-256 parity.
     (Pass `--no-bump` to deploy without incrementing the version number.)
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import tarfile
from pathlib import Path

# ── Configuration ──────────────────────────────────────────────────────────────
SSH_HOST = "u981153228@46.17.175.131"
SSH_PORT = "65002"
REMOTE_WP_ROOT = "/home/u981153228/domains/odella.ge/public_html"
REMOTE_PLUGIN_DIR = f"{REMOTE_WP_ROOT}/wp-content/plugins/odella-elementor-widgets"

PLUGIN_DIR = Path(__file__).resolve().parent
PLUGIN_MAIN_FILE = "odella-elementor-widgets.php"
INCLUDED_PATHS = [PLUGIN_MAIN_FILE, "includes", "assets"]
TEXT_EXTENSIONS = {".php", ".js", ".css", ".json", ".txt", ".md", ".svg", ".html"}


def normalize_bytes(data: bytes, ext: str) -> bytes:
    """Normalize CRLF to LF for text assets so Linux receives clean POSIX files."""
    if ext.lower() in TEXT_EXTENSIONS:
        return data.replace(b"\r\n", b"\n")
    return data


def collect_local_files() -> dict[str, bytes]:
    """Collect all plugin runtime files (normalized to LF) keyed by relative POSIX path."""
    files: dict[str, bytes] = {}
    for item in INCLUDED_PATHS:
        target = PLUGIN_DIR / item
        if not target.exists():
            raise FileNotFoundError(f"Required plugin path missing: {target}")
        if target.is_file():
            rel = target.relative_to(PLUGIN_DIR).as_posix()
            files[rel] = normalize_bytes(target.read_bytes(), target.suffix)
        else:
            for root, _, filenames in os.walk(target):
                for fname in sorted(filenames):
                    if fname.endswith((".bak", ".tmp")) or fname.startswith("."):
                        continue
                    fpath = Path(root) / fname
                    rel = fpath.relative_to(PLUGIN_DIR).as_posix()
                    files[rel] = normalize_bytes(fpath.read_bytes(), fpath.suffix)
    return files


def compute_hashes(files: dict[str, bytes]) -> dict[str, str]:
    return {rel: hashlib.sha256(content).hexdigest() for rel, content in sorted(files.items())}


def run_remote_python(script: str) -> str:
    """Execute a Python script on the remote server via SSH stdin and return stdout."""
    cmd = ["ssh", "-p", SSH_PORT, SSH_HOST, "python3", "-"]
    proc = subprocess.run(
        cmd,
        input=script.encode("utf-8"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if proc.returncode != 0:
        stderr_txt = proc.stderr.decode("utf-8", "replace")
        stdout_txt = proc.stdout.decode("utf-8", "replace")
        raise RuntimeError(
            f"Remote SSH command failed (exit {proc.returncode}):\n{stderr_txt}\n{stdout_txt}"
        )
    return proc.stdout.decode("utf-8", "replace")


def fetch_remote_hashes() -> dict[str, str]:
    remote_script = f"""
import os, hashlib, json
root = {json.dumps(REMOTE_PLUGIN_DIR)}
TEXT_EXT = {json.dumps(sorted(TEXT_EXTENSIONS))}
out = {{}}
if os.path.exists(root):
    for dirpath, _, filenames in os.walk(root):
        for f in sorted(filenames):
            full = os.path.join(dirpath, f)
            rel = os.path.relpath(full, root).replace("\\\\", "/")
            ext = os.path.splitext(f)[1].lower()
            with open(full, "rb") as fp:
                data = fp.read()
            if ext in TEXT_EXT:
                data = data.replace(b"\\r\\n", b"\\n")
            out[rel] = hashlib.sha256(data).hexdigest()
print("__JSON_START__")
print(json.dumps(out))
"""
    raw = run_remote_python(remote_script)
    marker = "__JSON_START__"
    idx = raw.find(marker)
    if idx == -1:
        raise RuntimeError(f"Unexpected output from remote hash check:\n{raw}")
    return json.loads(raw[idx + len(marker) :].strip())


def compare_local_and_remote(verbose: bool = True) -> bool:
    local_files = collect_local_files()
    local_hashes = compute_hashes(local_files)
    remote_hashes = fetch_remote_hashes()

    all_keys = sorted(set(local_hashes.keys()) | set(remote_hashes.keys()))
    diffs = 0
    for key in all_keys:
        l_hash = local_hashes.get(key)
        r_hash = remote_hashes.get(key)
        if l_hash == r_hash:
            if verbose:
                print(f"  [OK]       {key}")
        elif l_hash and not r_hash:
            diffs += 1
            print(f"  [LOCAL+]   {key} (missing on server)")
        elif r_hash and not l_hash:
            diffs += 1
            print(f"  [REMOTE+]  {key} (extra file on server)")
        else:
            diffs += 1
            print(f"  [MODIFIED] {key}")

    if diffs == 0:
        print(f"\n✅ All {len(all_keys)} plugin files match 100% between local and server.")
        return True
    else:
        print(f"\n⚠️ Found {diffs} difference(s) between local Odella-Plugins and server.")
        return False


def get_current_version() -> str:
    main_php = (PLUGIN_DIR / PLUGIN_MAIN_FILE).read_text(encoding="utf-8")
    m = re.search(r"define\(\s*['\"]NMJ_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]\s*\);", main_php)
    if not m:
        raise RuntimeError(f"Could not find NMJ_VERSION in {PLUGIN_MAIN_FILE}")
    return m.group(1)


def set_version(new_version: str) -> None:
    main_path = PLUGIN_DIR / PLUGIN_MAIN_FILE
    content = main_path.read_text(encoding="utf-8")
    content = re.sub(r"(\*\s*Version:\s*)([0-9.]+)", rf"\g<1>{new_version}", content, count=1)
    content = re.sub(
        r"(define\(\s*['\"]NMJ_VERSION['\"]\s*,\s*['\"])([0-9.]+)(['\"]\s*\);)",
        rf"\g<1>{new_version}\g<3>",
        content,
        count=1,
    )
    main_path.write_text(content, encoding="utf-8")

    pkg_path = PLUGIN_DIR / "package.json"
    if pkg_path.exists():
        pkg = json.loads(pkg_path.read_text(encoding="utf-8"))
        pkg["version"] = new_version
        pkg_path.write_text(json.dumps(pkg, indent=2) + "\n", encoding="utf-8")


def build_zip_without_bump() -> None:
    """Run build.js while keeping the chosen version intact."""
    ver = get_current_version()
    parts = ver.split(".")
    # build.js increments patch by 1, so set to (ver - 1) before calling build.js if needed,
    # or run build.js and restore version if --no-bump was requested.
    subprocess.run(["node", str(PLUGIN_DIR / "build.js")], cwd=str(PLUGIN_DIR), check=True)


def deploy(bump: bool = True) -> None:
    initial_version = get_current_version()
    print(f"📦 Local plugin version: {initial_version}")

    if not bump:
        parts = initial_version.split(".")
        if parts and parts[-1].isdigit() and int(parts[-1]) > 0:
            parts[-1] = str(int(parts[-1]) - 1)
            set_version(".".join(parts))

    # Run build.js to package OdellaPlugins.zip (which increments patch version by 1)
    subprocess.run(["node", str(PLUGIN_DIR / "build.js")], cwd=str(PLUGIN_DIR), check=True)

    if not bump:
        set_version(initial_version)
        print(f"📌 Deploying version {initial_version} (--no-bump) to {SSH_HOST}...")
    else:
        print(f"🚀 Deploying version {get_current_version()} to {SSH_HOST}...")

    local_files = collect_local_files()
    expected_hashes = compute_hashes(local_files)

    # Create in-memory tar.gz archive with POSIX paths and LF line endings
    tar_buf = io.BytesIO()
    with tarfile.open(fileobj=tar_buf, mode="w:gz") as tf:
        for rel_path, data in sorted(local_files.items()):
            info = tarfile.TarInfo(name=rel_path)
            info.size = len(data)
            info.mode = 0o644
            tf.addfile(info, io.BytesIO(data))

    b64_payload = base64.b64encode(tar_buf.getvalue()).decode("ascii")

    remote_deploy_script = f"""
import base64, io, os, shutil, subprocess, tarfile, tempfile

wp_root = {json.dumps(REMOTE_WP_ROOT)}
plugin_dir = {json.dumps(REMOTE_PLUGIN_DIR)}
payload = base64.b64decode({json.dumps(b64_payload)})

staging_dir = tempfile.mkdtemp(prefix=".odella-widgets-stage-", dir=os.path.dirname(plugin_dir))
backup_dir = plugin_dir + ".old-deploy"
try:
    with tarfile.open(fileobj=io.BytesIO(payload), mode="r:gz") as tf:
        tf.extractall(staging_dir)

    # Ensure directory permissions are 0755 and files are 0644
    for root, dirs, files in os.walk(staging_dir):
        os.chmod(root, 0o755)
        for d in dirs:
            os.chmod(os.path.join(root, d), 0o755)
        for f in files:
            fpath = os.path.join(root, f)
            os.chmod(fpath, 0o644)
            if f.endswith(".php"):
                res = subprocess.run(["php", "-l", fpath], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
                if res.returncode != 0:
                    raise RuntimeError(f"PHP syntax error in {{f}}:\\n" + res.stderr.decode("utf-8", "replace"))

    # Atomic swap into live plugin directory (same filesystem)
    if os.path.exists(backup_dir):
        shutil.rmtree(backup_dir)
    if os.path.exists(plugin_dir):
        os.rename(plugin_dir, backup_dir)
    os.rename(staging_dir, plugin_dir)
    if os.path.exists(backup_dir):
        shutil.rmtree(backup_dir)

    # Flush Elementor CSS cache, WP Object Cache, and LiteSpeed Cache
    subprocess.run(
        ["wp", "eval", r"if (class_exists('\\Elementor\\Plugin')) {{ \\Elementor\\Plugin::$instance->files_manager->clear_cache(); }} wp_cache_flush();", f"--path={{wp_root}}"],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    subprocess.run(
        ["wp", "litespeed-purge", "all", f"--path={{wp_root}}"],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    print("__DEPLOY_SUCCESS__")
except Exception as e:
    if not os.path.exists(plugin_dir) and os.path.exists(backup_dir):
        os.rename(backup_dir, plugin_dir)
    if os.path.exists(staging_dir):
        shutil.rmtree(staging_dir, ignore_errors=True)
    raise
"""
    out = run_remote_python(remote_deploy_script)
    if "__DEPLOY_SUCCESS__" not in out:
        raise RuntimeError(f"Remote deployment did not report success:\n{out}")

    print("🔍 Verifying deployed files via SHA-256 checksums...")
    if not compare_local_and_remote(verbose=False):
        raise RuntimeError("Post-deploy SHA-256 verification failed!")

    print(f"🎉 Successfully deployed odella-elementor-widgets v{get_current_version()} and flushed caches.")


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    if hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(
        description="Compare or deploy local Odella-Plugins to the production Hostinger server."
    )
    parser.add_argument(
        "--check",
        action="store_true",
        help="Only compare local files against the remote server (SHA-256) without deploying.",
    )
    parser.add_argument(
        "--no-bump",
        action="store_true",
        help="Deploy current local files without incrementing NMJ_VERSION.",
    )
    args = parser.parse_args()

    if args.check:
        ok = compare_local_and_remote(verbose=True)
        sys.exit(0 if ok else 1)

    deploy(bump=not args.no_bump)


if __name__ == "__main__":
    main()
