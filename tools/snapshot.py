#!/usr/bin/env python3
"""Save and restore Elementor post meta and WordPress options.

save --label <name> [--post ID ...] [--option NAME ...]
restore <file>

Fetch and write go through `wp eval-file -` on the server. Restore then runs
`tools/deploy.py --purge-only`.
"""

from __future__ import annotations

import argparse
import base64
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SNAPSHOTS = ROOT / "snapshots"
SSH = ["ssh", "-p", "65002", "-o", "BatchMode=yes", "u981153228@46.17.175.131"]
REMOTE = "cd /home/u981153228/domains/odella.ge/public_html && wp eval-file -"
START = "__SNAPSHOT_START__"
END = "__SNAPSHOT_END__"


def wp_eval(php: str) -> str:
    proc = subprocess.run(
        [*SSH, REMOTE],
        input=php.encode("utf-8"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    out = proc.stdout.decode("utf-8", "replace")
    err = proc.stderr.decode("utf-8", "replace")
    if proc.returncode != 0:
        raise RuntimeError(f"wp eval-file failed (exit {proc.returncode}):\n{err}\n{out}")
    return out


def extract_json(raw: str) -> dict:
    start = raw.find(START)
    end = raw.find(END)
    if start < 0 or end < 0 or end < start:
        raise RuntimeError(f"Snapshot markers missing:\n{raw[:1000]}")
    return json.loads(raw[start + len(START) : end].strip())


def save(label: str, posts: list[int], options: list[str]) -> Path:
    if not posts and not options:
        raise SystemExit("Pass at least one --post or --option.")
    safe = "".join(ch if ch.isalnum() or ch in "-_" else "-" for ch in label).strip("-")
    if not safe:
        raise SystemExit("Label must contain a letter or number.")
    php = f"""<?php
$posts = {json.dumps(posts)};
$options = {json.dumps(options)};
$out = array('posts' => array(), 'options' => array());
foreach ($posts as $id) {{
    $post = get_post((int) $id);
    if (!$post) {{
        WP_CLI::error("Post $id not found");
    }}
    $out['posts'][(string) $id] = array(
        'post_status' => $post->post_status,
        '_elementor_data' => get_post_meta((int) $id, '_elementor_data', true),
        '_elementor_page_settings' => get_post_meta((int) $id, '_elementor_page_settings', true),
    );
}}
foreach ($options as $name) {{
    $out['options'][$name] = get_option($name);
}}
echo "{START}\\n";
echo wp_json_encode($out);
echo "\\n{END}\\n";
"""
    data = extract_json(wp_eval(php))
    data["label"] = safe
    data["saved_at"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    SNAPSHOTS.mkdir(parents=True, exist_ok=True)
    path = SNAPSHOTS / f"{datetime.now().strftime('%Y%m%d-%H%M')}-{safe}.json"
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Saved {path}")
    print(f"posts={len(data['posts'])} options={len(data['options'])}")
    return path


def restore(path: Path) -> None:
    data = json.loads(path.read_text(encoding="utf-8"))
    payload = base64.b64encode(
        json.dumps({"posts": data.get("posts") or {}, "options": data.get("options") or {}}).encode("utf-8")
    ).decode("ascii")
    php = f"""<?php
$data = json_decode(base64_decode('{payload}'), true);
if (!is_array($data)) {{
    WP_CLI::error('Snapshot JSON could not be decoded');
}}
foreach ($data['posts'] as $id => $post) {{
    $id = (int) $id;
    if (!get_post($id)) {{
        WP_CLI::error("Post $id not found");
    }}
    wp_update_post(array('ID' => $id, 'post_status' => $post['post_status']));
    update_post_meta($id, '_elementor_data', wp_slash($post['_elementor_data']));
    update_post_meta($id, '_elementor_page_settings', wp_slash($post['_elementor_page_settings']));
    WP_CLI::log("restored post $id");
}}
foreach ($data['options'] as $name => $value) {{
    update_option($name, $value);
    WP_CLI::log("restored option $name");
}}
WP_CLI::success('snapshot restored');
"""
    print(wp_eval(php).rstrip())
    purge = subprocess.run(
        [sys.executable, str(ROOT / "tools" / "deploy.py"), "--purge-only"],
        cwd=str(ROOT),
        check=False,
    )
    if purge.returncode != 0:
        raise SystemExit(purge.returncode)


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(description="Snapshot and restore Elementor meta and options.")
    sub = parser.add_subparsers(dest="cmd", required=True)
    save_p = sub.add_parser("save")
    save_p.add_argument("--label", required=True)
    save_p.add_argument("--post", type=int, action="append", default=[])
    save_p.add_argument("--option", action="append", default=[])
    restore_p = sub.add_parser("restore")
    restore_p.add_argument("file")
    args = parser.parse_args()
    if args.cmd == "save":
        save(args.label, args.post, args.option)
    else:
        restore(Path(args.file))


if __name__ == "__main__":
    main()
