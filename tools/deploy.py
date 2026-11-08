#!/usr/bin/env python3
"""Deploy Odella-owned code to odella.ge.

Targets: plugin (default), mu, theme. Also --check, --rollback, --purge-only, --all.

Exit codes:
  1  local/remote mismatch on --check, or post-deploy verify/version check failed
  2  purge step failed (files are left as deployed; no automatic rollback)
  3  remote drift from the last recorded deploy
  4  smoke test failed
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import http.client
import io
import json
import os
import re
import subprocess
import sys
import tarfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

SSH_HOST = "u981153228@46.17.175.131"
SSH_PORT = "65002"
REMOTE_WP_ROOT = "/home/u981153228/domains/odella.ge/public_html"
BACKUP_ROOT = "/home/u981153228/odella-deploy-backups"
WP_CD = f"cd {REMOTE_WP_ROOT} && "

REPO_ROOT = Path(__file__).resolve().parent.parent
PLUGIN_DIR = REPO_ROOT / "Odella-Plugins"
STATE_DIR = REPO_ROOT / ".deploy-state"
ENV_PATH = REPO_ROOT / ".env"
URLS_PATH = REPO_ROOT / "tools" / "urls.txt"

TEXT_EXTENSIONS = {".php", ".js", ".css", ".json", ".txt", ".md", ".svg", ".html"}
MOBILE_UA = (
    "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) "
    "AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)"
)
CRITICAL_ERROR = "There has been a critical error"

TARGETS = {
    "plugin": {
        "kind": "dir",
        "root": PLUGIN_DIR,
        "includes": ["odella-elementor-widgets.php", "includes", "assets"],
        "remote": f"{REMOTE_WP_ROOT}/wp-content/plugins/odella-elementor-widgets",
    },
    "mu": {
        "kind": "file",
        "root": REPO_ROOT / "site" / "mu-plugins",
        "file": "odella-performance.php",
        "remote": f"{REMOTE_WP_ROOT}/wp-content/mu-plugins/odella-performance.php",
    },
    "theme": {
        "kind": "dir",
        "root": REPO_ROOT / "site" / "theme-child",
        "includes": None,
        "remote": f"{REMOTE_WP_ROOT}/wp-content/themes/hello-theme-child-master",
    },
}

REMOTE_APPLY = r"""
import base64, datetime, io, json, os, shutil, subprocess, sys, tarfile, tempfile, time

def skipped(name):
    return name.startswith(".") or name.endswith(".bak") or name.endswith(".tmp")

def php_lint(path):
    if not path.endswith(".php"):
        return
    res = subprocess.run(["php", "-l", path], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if res.returncode != 0:
        err = res.stderr.decode("utf-8", "replace")
        out = res.stdout.decode("utf-8", "replace")
        raise RuntimeError("PHP syntax error in %s:\n%s%s" % (path, out, err))

def chmod_tree(root):
    now = int(time.time())
    for dirpath, dirnames, filenames in os.walk(root):
        os.chmod(dirpath, 0o755)
        for name in filenames:
            path = os.path.join(dirpath, name)
            os.chmod(path, 0o644)
            os.utime(path, (now, now))
    return now

def lint_tree(root):
    for dirpath, dirnames, filenames in os.walk(root):
        for name in filenames:
            php_lint(os.path.join(dirpath, name))

def ensure_dir(path):
    if not os.path.isdir(path):
        os.makedirs(path)

def prune(backup_root, keep):
    if not os.path.isdir(backup_root):
        return
    names = sorted([n for n in os.listdir(backup_root) if os.path.isdir(os.path.join(backup_root, n))])
    while len(names) > keep:
        old = names.pop(0)
        shutil.rmtree(os.path.join(backup_root, old))

def copy_live_to_backup(live, dest, kind):
    if kind == "file":
        ensure_dir(dest)
        if os.path.isfile(live):
            shutil.copy2(live, os.path.join(dest, os.path.basename(live)))
    else:
        if os.path.isdir(live):
            shutil.copytree(live, dest)
        else:
            ensure_dir(dest)

def preserve_skipped(live, staging):
    if not os.path.isdir(live):
        return
    for dirpath, dirnames, filenames in os.walk(live):
        dirnames[:] = [d for d in dirnames if not d.startswith(".")]
        for name in filenames:
            if not skipped(name):
                continue
            full = os.path.join(dirpath, name)
            rel = os.path.relpath(full, live)
            dest = os.path.join(staging, rel)
            parent = os.path.dirname(dest)
            if parent and not os.path.isdir(parent):
                os.makedirs(parent)
            if not os.path.exists(dest):
                shutil.copy2(full, dest)

def atomic_dir_swap(live, staging):
    old = live + ".replacing"
    if os.path.exists(old):
        shutil.rmtree(old)
    moved = False
    if os.path.exists(live):
        os.rename(live, old)
        moved = True
    try:
        os.rename(staging, live)
    except Exception:
        if moved and (not os.path.exists(live)) and os.path.exists(old):
            os.rename(old, live)
        raise
    if os.path.exists(old):
        shutil.rmtree(old)

def newest_backup(backup_root):
    if not os.path.isdir(backup_root):
        raise RuntimeError("no backups at %s" % backup_root)
    names = sorted([n for n in os.listdir(backup_root) if os.path.isdir(os.path.join(backup_root, n))])
    if not names:
        raise RuntimeError("no backups at %s" % backup_root)
    return names[-1]

def deploy_dir(cfg):
    live = cfg["remote"]
    parent = os.path.dirname(live)
    staging = tempfile.mkdtemp(prefix=".odella-stage-", dir=parent)
    try:
        raw = base64.b64decode(cfg["payload_b64"])
        with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as tf:
            try:
                tf.extractall(staging, filter="data")
            except TypeError:
                tf.extractall(staging)
        preserve_skipped(live, staging)
        mtime = chmod_tree(staging)
        lint_tree(staging)
        stamp = datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")
        ensure_dir(cfg["backup_root"])
        dest = os.path.join(cfg["backup_root"], stamp)
        copy_live_to_backup(live, dest, "dir")
        prune(cfg["backup_root"], cfg["keep"])
        atomic_dir_swap(live, staging)
        staging = None
        print("MTIME %s" % mtime)
        print("BACKUP %s" % stamp)
        print("__DEPLOY_SUCCESS__")
    finally:
        if staging and os.path.exists(staging):
            shutil.rmtree(staging, ignore_errors=True)

def deploy_file(cfg):
    live = cfg["remote"]
    name = os.path.basename(live)
    if name != "odella-performance.php":
        raise RuntimeError("refusing to replace %s" % name)
    parent = os.path.dirname(live)
    new_path = os.path.join(parent, name + ".new")
    raw = base64.b64decode(cfg["payload_b64"])
    data = None
    with tarfile.open(fileobj=io.BytesIO(raw), mode="r:gz") as tf:
        src = tf.extractfile(tf.getmember(name))
        data = src.read()
    with open(new_path, "wb") as fp:
        fp.write(data)
    os.chmod(new_path, 0o644)
    os.utime(new_path, (time.time(), time.time()))
    try:
        php_lint(new_path)
        stamp = datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")
        ensure_dir(cfg["backup_root"])
        dest = os.path.join(cfg["backup_root"], stamp)
        copy_live_to_backup(live, dest, "file")
        prune(cfg["backup_root"], cfg["keep"])
        os.rename(new_path, live)
        new_path = None
        print("BACKUP %s" % stamp)
        print("__DEPLOY_SUCCESS__")
    finally:
        if new_path and os.path.exists(new_path):
            os.remove(new_path)

def rollback_dir(cfg):
    live = cfg["remote"]
    stamp = newest_backup(cfg["backup_root"])
    src = os.path.join(cfg["backup_root"], stamp)
    parent = os.path.dirname(live)
    staging = tempfile.mkdtemp(prefix=".odella-stage-", dir=parent)
    try:
        for name in os.listdir(src):
            s = os.path.join(src, name)
            d = os.path.join(staging, name)
            if os.path.isdir(s):
                shutil.copytree(s, d)
            else:
                shutil.copy2(s, d)
        chmod_tree(staging)
        lint_tree(staging)
        atomic_dir_swap(live, staging)
        staging = None
        print("RESTORED %s" % stamp)
        print("__DEPLOY_SUCCESS__")
    finally:
        if staging and os.path.exists(staging):
            shutil.rmtree(staging, ignore_errors=True)

def rollback_file(cfg):
    live = cfg["remote"]
    name = os.path.basename(live)
    stamp = newest_backup(cfg["backup_root"])
    src = os.path.join(cfg["backup_root"], stamp, name)
    if not os.path.isfile(src):
        raise RuntimeError("backup file missing: %s" % src)
    new_path = live + ".new"
    shutil.copy2(src, new_path)
    os.chmod(new_path, 0o644)
    os.utime(new_path, (time.time(), time.time()))
    try:
        php_lint(new_path)
        os.rename(new_path, live)
        new_path = None
        print("RESTORED %s" % stamp)
        print("__DEPLOY_SUCCESS__")
    finally:
        if new_path and os.path.exists(new_path):
            os.remove(new_path)

def main(cfg):
    try:
        action = cfg["action"]
        kind = cfg["kind"]
        if action == "deploy" and kind == "dir":
            deploy_dir(cfg)
        elif action == "deploy" and kind == "file":
            deploy_file(cfg)
        elif action == "rollback" and kind == "dir":
            rollback_dir(cfg)
        elif action == "rollback" and kind == "file":
            rollback_file(cfg)
        else:
            raise RuntimeError("unknown action %s/%s" % (action, kind))
    except Exception as exc:
        sys.stderr.write("REMOTE_ERROR: %s\n" % exc)
        sys.exit(1)
"""


def skip_name(name: str) -> bool:
    return name.startswith(".") or name.endswith(".bak") or name.endswith(".tmp")


def normalize_bytes(data: bytes, ext: str) -> bytes:
    if ext.lower() in TEXT_EXTENSIONS:
        return data.replace(b"\r\n", b"\n")
    return data


def collect_local_files(target: str) -> dict[str, bytes]:
    spec = TARGETS[target]
    root: Path = spec["root"]
    files: dict[str, bytes] = {}
    if spec["kind"] == "file":
        path = root / spec["file"]
        if not path.is_file():
            raise FileNotFoundError(f"Required file missing: {path}")
        files[spec["file"]] = normalize_bytes(path.read_bytes(), path.suffix)
        return files

    includes = spec["includes"]
    bases = [root / item for item in includes] if includes else [root]
    for base in bases:
        if not base.exists():
            raise FileNotFoundError(f"Required path missing: {base}")
        if base.is_file():
            if skip_name(base.name):
                continue
            rel = base.relative_to(root).as_posix()
            files[rel] = normalize_bytes(base.read_bytes(), base.suffix)
            continue
        for dirpath, dirnames, filenames in os.walk(base):
            dirnames[:] = [d for d in dirnames if not d.startswith(".")]
            for fname in sorted(filenames):
                if skip_name(fname):
                    continue
                fpath = Path(dirpath) / fname
                rel = fpath.relative_to(root).as_posix()
                files[rel] = normalize_bytes(fpath.read_bytes(), fpath.suffix)
    if not files:
        raise FileNotFoundError(f"No files collected for target {target}")
    return files


def compute_hashes(files: dict[str, bytes]) -> dict[str, str]:
    return {rel: hashlib.sha256(content).hexdigest() for rel, content in sorted(files.items())}


def run_ssh(remote_cmd: str, *, stdin: bytes | None = None) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["ssh", "-p", SSH_PORT, "-o", "BatchMode=yes", SSH_HOST, remote_cmd],
        input=stdin,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def run_remote_python(script: str, echo: bool = False) -> str:
    proc = run_ssh("python3 -", stdin=script.encode("utf-8"))
    stdout = proc.stdout.decode("utf-8", "replace")
    stderr = proc.stderr.decode("utf-8", "replace")
    if echo and stdout.strip():
        print(stdout.rstrip())
    if proc.returncode != 0:
        raise RuntimeError(
            f"Remote SSH command failed (exit {proc.returncode}):\n{stderr}\n{stdout}"
        )
    if echo and stderr.strip():
        print(stderr.rstrip())
    return stdout


def fetch_remote_hashes(target: str) -> dict[str, str]:
    spec = TARGETS[target]
    remote_script = f"""
import os, hashlib, json
root = {json.dumps(spec["remote"])}
kind = {json.dumps(spec["kind"])}
text_ext = set({json.dumps(sorted(TEXT_EXTENSIONS))})

def skipped(name):
    return name.startswith(".") or name.endswith(".bak") or name.endswith(".tmp")

out = {{}}
if kind == "file":
    if os.path.isfile(root):
        name = os.path.basename(root)
        with open(root, "rb") as fp:
            data = fp.read()
        ext = os.path.splitext(name)[1].lower()
        if ext in text_ext:
            data = data.replace(b"\\r\\n", b"\\n")
        out[name] = hashlib.sha256(data).hexdigest()
elif os.path.isdir(root):
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if not d.startswith(".")]
        for fname in sorted(filenames):
            if skipped(fname):
                continue
            full = os.path.join(dirpath, fname)
            rel = os.path.relpath(full, root).replace("\\\\", "/")
            ext = os.path.splitext(fname)[1].lower()
            with open(full, "rb") as fp:
                data = fp.read()
            if ext in text_ext:
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


def print_hash_diff(left: dict[str, str], right: dict[str, str], left_label: str, right_label: str) -> int:
    diffs = 0
    for key in sorted(set(left) | set(right)):
        l_hash = left.get(key)
        r_hash = right.get(key)
        if l_hash == r_hash:
            continue
        diffs += 1
        if l_hash and not r_hash:
            print(f"  only in {left_label}: {key}")
        elif r_hash and not l_hash:
            print(f"  only in {right_label}: {key}")
        else:
            print(f"  hash differs: {key}")
            print(f"    {left_label}: {l_hash}")
            print(f"    {right_label}: {r_hash}")
    return diffs


def compare_local_and_remote(target: str, verbose: bool = True) -> bool:
    local_hashes = compute_hashes(collect_local_files(target))
    remote_hashes = fetch_remote_hashes(target)
    diffs = 0
    for key in sorted(set(local_hashes) | set(remote_hashes)):
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
        print(f"All {len(local_hashes)} {target} files match between local and server.")
        return True
    print(f"Found {diffs} difference(s) for {target}.")
    return False


def state_path(target: str) -> Path:
    return STATE_DIR / f"{target}.json"


def load_state(target: str) -> dict | None:
    path = state_path(target)
    if not path.is_file():
        return None
    return json.loads(path.read_text(encoding="utf-8"))


def write_state(target: str, hashes: dict[str, str]) -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    payload = {
        "deployed_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "hashes": hashes,
        "target": target,
    }
    state_path(target).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def drift_guard(target: str, local_hashes: dict[str, str], remote_hashes: dict[str, str], force: bool) -> None:
    state = load_state(target)
    if state is None:
        if remote_hashes == local_hashes:
            write_state(target, local_hashes)
            print(f"No deploy state for {target}. Remote matches local; wrote .deploy-state/{target}.json.")
            return
        print(f"No deploy state for {target}, and remote does not match local. Aborting.")
        print_hash_diff(local_hashes, remote_hashes, "local", "remote")
        print("Reconcile the files, or re-run with --force.")
        if not force:
            raise SystemExit(3)
        print("--force set; continuing.")
        return

    expected = state.get("hashes") or {}
    if expected == remote_hashes:
        return
    print(f"Drift: remote {target} does not match the last recorded deploy.")
    print_hash_diff(expected, remote_hashes, "state", "remote")
    if not force:
        raise SystemExit(3)
    print("--force set; continuing.")


def pack_files(files: dict[str, bytes]) -> str:
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tf:
        stamped = int(time.time())
        for rel_path, data in sorted(files.items()):
            info = tarfile.TarInfo(name=rel_path)
            info.size = len(data)
            info.mode = 0o644
            info.mtime = stamped
            tf.addfile(info, io.BytesIO(data))
    return base64.b64encode(buf.getvalue()).decode("ascii")


def apply_remote(action: str, target: str, files: dict[str, bytes] | None = None) -> str:
    spec = TARGETS[target]
    cfg = {
        "action": action,
        "backup_root": f"{BACKUP_ROOT}/{target}",
        "keep": 5,
        "kind": spec["kind"],
        "payload_b64": pack_files(files) if files is not None else "",
        "remote": spec["remote"],
    }
    script = REMOTE_APPLY + "\nmain(json.loads(%s))\n" % json.dumps(json.dumps(cfg))
    out = run_remote_python(script, echo=True)
    if "__DEPLOY_SUCCESS__" not in out:
        raise RuntimeError(f"Remote {action} of {target} did not report success:\n{out}")
    return out


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


def print_step(title: str) -> None:
    print(f"\n--- {title} ---")


def purge_cdn(env: dict[str, str]) -> str:
    token = env.get("HOSTINGER_API_TOKEN", "").strip()
    username = env.get("HOSTINGER_USERNAME", "").strip() or "u981153228"
    if not token:
        print("CDN not purged by script — agent: purge via Hostinger MCP")
        return "mcp"
    url = (
        "https://developers.hostinger.com/api/hosting/v1/accounts/"
        f"{urllib.parse.quote(username)}/websites/odella.ge/cdn/purge"
    )
    req = urllib.request.Request(
        url,
        method="DELETE",
        headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
    )
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = resp.read().decode("utf-8", "replace")
            print(f"CDN purge HTTP {resp.status}")
            if body.strip():
                print(body.rstrip())
            return "ok"
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", "replace")
        print(f"CDN purge HTTP {exc.code}")
        if body.strip():
            print(body.rstrip())
        if exc.code == 422:
            print("Warning: CDN purge returned 422 (CDN is off). Continuing.")
            return "warn"
        return "fail"
    except urllib.error.URLError as exc:
        print(f"CDN purge failed: {exc}")
        return "fail"


def purge(env: dict[str, str]) -> bool:
    """Run the purge sequence. Return True when every step succeeded."""
    ok = True
    print_step("Elementor cache + object cache")
    proc = subprocess.run(
        [
            "ssh",
            "-p",
            SSH_PORT,
            "-o",
            "BatchMode=yes",
            SSH_HOST,
            WP_CD + "wp eval '\\Elementor\\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();'",
        ],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    print(
        "$ "
        + WP_CD
        + "wp eval '\\Elementor\\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();'"
    )
    print(f"exit {proc.returncode}")
    out = proc.stdout.decode("utf-8", "replace")
    err = proc.stderr.decode("utf-8", "replace")
    if out.strip():
        print(out.rstrip())
    if err.strip():
        print(err.rstrip())
    if proc.returncode != 0:
        ok = False

    print_step("LiteSpeed purge")
    proc = subprocess.run(
        [
            "ssh",
            "-p",
            SSH_PORT,
            "-o",
            "BatchMode=yes",
            SSH_HOST,
            WP_CD + "wp litespeed-purge all",
        ],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    print("$ " + WP_CD + "wp litespeed-purge all")
    print(f"exit {proc.returncode}")
    out = proc.stdout.decode("utf-8", "replace")
    err = proc.stderr.decode("utf-8", "replace")
    if out.strip():
        print(out.rstrip())
    if err.strip():
        print(err.rstrip())
    if proc.returncode != 0:
        ok = False

    print_step("Hostinger CDN purge")
    if purge_cdn(env) == "fail":
        ok = False
    return ok


def http_get(url: str) -> tuple[int, float, bytes]:
    parsed = urllib.parse.urlparse(url)
    conn = http.client.HTTPSConnection(parsed.hostname, timeout=60)
    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    start = time.perf_counter()
    conn.request(
        "GET",
        path,
        headers={
            "User-Agent": MOBILE_UA,
            "Accept": "text/html,*/*",
            "Accept-Encoding": "identity",
        },
    )
    resp = conn.getresponse()
    ttfb = time.perf_counter() - start
    body = resp.read()
    status = resp.status
    conn.close()
    return status, ttfb, body


def smoke_test() -> bool:
    if not URLS_PATH.is_file():
        print(f"Missing {URLS_PATH}")
        return False
    urls = []
    for line in URLS_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if line and not line.startswith("#"):
            urls.append(line)
    print_step("Smoke test")
    ok = True
    for url in urls:
        try:
            status, ttfb, body = http_get(url)
        except Exception as exc:
            print(f"FAIL  {url}  ({exc})")
            ok = False
            continue
        text = body.decode("utf-8", "replace")
        bad = status not in (200, 302) or CRITICAL_ERROR in text
        mark = "FAIL" if bad else "OK"
        print(f"{mark}  {status}  {ttfb * 1000:.0f} ms  {url}")
        if bad:
            ok = False
    return ok


def get_current_version() -> str:
    main_php = (PLUGIN_DIR / "odella-elementor-widgets.php").read_text(encoding="utf-8")
    import re

    match = re.search(r"define\(\s*['\"]NMJ_VERSION['\"]\s*,\s*['\"]([0-9.]+)['\"]\s*\);", main_php)
    if not match:
        raise RuntimeError("Could not find NMJ_VERSION in odella-elementor-widgets.php")
    return match.group(1)


def set_version(new_version: str) -> None:
    import re

    main_path = PLUGIN_DIR / "odella-elementor-widgets.php"
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


def prepare_plugin_version(bump: bool) -> str:
    initial = get_current_version()
    print(f"Local plugin version: {initial}")
    if not bump:
        parts = initial.split(".")
        if parts and parts[-1].isdigit() and int(parts[-1]) > 0:
            parts[-1] = str(int(parts[-1]) - 1)
            set_version(".".join(parts))
    subprocess.run(["node", str(PLUGIN_DIR / "build.js")], cwd=str(PLUGIN_DIR), check=True)
    if not bump:
        set_version(initial)
        print(f"Deploying version {initial} (--no-bump).")
        return initial
    deployed = get_current_version()
    print(f"Deploying version {deployed}.")
    return deployed


def version_check(version: str, asset_mtime: str | None) -> bool:
    """Homepage must reference this deploy.

    The plugin enqueues assets with filemtime, not NMJ_VERSION. WordPress
    replaces a falsy mtime of 0 with the WordPress version, so the check
    accepts ver=<plugin version> or the mtime this deploy wrote onto the files.
    """
    print_step("Version check")
    status, _ttfb, body = http_get("https://odella.ge/ka/")
    text = body.decode("utf-8", "replace")
    urls = re.findall(
        r"https://odella\.ge/wp-content/plugins/odella-elementor-widgets/[^\"']+",
        text,
    )
    found = []
    for url in urls:
        match = re.search(r"[?&]ver=([^&\"']+)", url)
        found.append(match.group(1) if match else "")
    unique = sorted(set(found))
    ok = status == 200 and bool(urls) and (version in unique or (asset_mtime is not None and asset_mtime in unique))
    shown = ", ".join(unique) if unique else "none"
    expected = version if asset_mtime is None else f"{version} or {asset_mtime}"
    if ok:
        print(f"Homepage references odella-elementor-widgets assets (ver={shown}; deployed {version}).")
    else:
        print(
            f"Version check failed (HTTP {status}). "
            f"Expected odella-elementor-widgets assets with ver={expected}. Found ver={shown}."
        )
    return ok


def reminder() -> None:
    print("\nFull cache warm: python tools\\warm_cache.py (optional, ~25 min)")


def deploy_target(target: str, force: bool) -> str | None:
    local_files = collect_local_files(target)
    local_hashes = compute_hashes(local_files)
    print(f"Fetching remote hashes for {target}...")
    remote_hashes = fetch_remote_hashes(target)
    drift_guard(target, local_hashes, remote_hashes, force)
    print(f"Deploying {target} ({len(local_files)} files)...")
    remote_out = apply_remote("deploy", target, local_files)
    print(f"Verifying {target}...")
    verified = fetch_remote_hashes(target)
    if verified != local_hashes:
        print(f"Post-deploy hash verification failed for {target}.")
        print_hash_diff(local_hashes, verified, "local", "remote")
        raise SystemExit(1)
    write_state(target, local_hashes)
    print(f"{target} deployed and matches local hashes.")
    match = re.search(r"^MTIME (\d+)$", remote_out, re.M)
    return match.group(1) if match else None


def rollback_target(target: str) -> None:
    print(f"Rolling back {target} to the newest remote backup...")
    apply_remote("rollback", target)
    verified = fetch_remote_hashes(target)
    write_state(target, verified)
    print(f"Recorded restored hashes in .deploy-state/{target}.json.")


def selected_targets(args: argparse.Namespace) -> list[str]:
    if args.all:
        return ["plugin", "mu", "theme"]
    return [args.target]


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    if hasattr(sys.stderr, "reconfigure"):
        sys.stderr.reconfigure(encoding="utf-8", errors="replace")

    parser = argparse.ArgumentParser(description="Compare, deploy, or roll back Odella site code.")
    parser.add_argument("--target", choices=["plugin", "mu", "theme"], default="plugin")
    parser.add_argument("--all", action="store_true", help="plugin, then mu, then theme")
    parser.add_argument("--check", action="store_true", help="Compare local files to the server.")
    parser.add_argument("--no-bump", action="store_true", help="Plugin only: deploy without incrementing the version.")
    parser.add_argument("--rollback", action="store_true", help="Restore the newest remote backup of the target.")
    parser.add_argument("--force", action="store_true", help="Deploy even if the server drifted from the last deploy.")
    parser.add_argument("--purge-only", action="store_true", help="Run the purge sequence and smoke test.")
    args = parser.parse_args()

    chosen = sum(bool(flag) for flag in (args.check, args.rollback, args.purge_only))
    if chosen > 1:
        parser.error("use only one of --check, --rollback, --purge-only")

    env = load_env()
    targets = selected_targets(args)

    if args.check:
        ok = True
        for target in targets:
            print(f"\n[{target}]")
            if not compare_local_and_remote(target, verbose=True):
                ok = False
        raise SystemExit(0 if ok else 1)

    if args.purge_only:
        if not purge(env):
            raise SystemExit(2)
        if not smoke_test():
            raise SystemExit(4)
        reminder()
        return

    version = None
    if not args.rollback:
        for target in targets:
            print(f"\n[{target}] drift check")
            local_hashes = compute_hashes(collect_local_files(target))
            remote_hashes = fetch_remote_hashes(target)
            drift_guard(target, local_hashes, remote_hashes, args.force)
    if "plugin" in targets and not args.rollback:
        version = prepare_plugin_version(bump=not args.no_bump)

    for target in targets:
        print(f"\n=== {target} ===")
        asset_mtime = None
        if args.rollback:
            rollback_target(target)
        else:
            asset_mtime = deploy_target(target, force=args.force)
        if not purge(env):
            raise SystemExit(2)
        if not smoke_test():
            raise SystemExit(4)
        if target == "plugin" and not args.rollback and version and not version_check(version, asset_mtime):
            raise SystemExit(1)
    reminder()


if __name__ == "__main__":
    main()
