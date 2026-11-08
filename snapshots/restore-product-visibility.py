#!/usr/bin/env python3
"""Save and restore WooCommerce product_visibility terms.

snapshot.py does not store taxonomy terms. Take the snapshot before the
pricing backfill. Restore puts each product's terms back, and deletes the
_odella_auto_hidden marker metas that the backfill added.

  python snapshots/restore-product-visibility.py save
  python snapshots/restore-product-visibility.py restore snapshots/<file>.json --id 123
  python snapshots/restore-product-visibility.py restore snapshots/<file>.json
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
START = "__VISIBILITY_START__"
END = "__VISIBILITY_END__"


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
    if err.strip():
        print(err.rstrip(), file=sys.stderr)
    return out


def extract_json(raw: str):
    start = raw.find(START)
    end = raw.find(END)
    if start < 0 or end < 0 or end < start:
        raise RuntimeError(f"Visibility markers missing:\n{raw[:1500]}")
    return json.loads(raw[start + len(START) : end].strip())


def save() -> Path:
    php = f"""<?php
$ids = get_posts(array(
    'post_type' => 'product',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'orderby' => 'ID',
    'order' => 'ASC',
    'suppress_filters' => true,
    'no_found_rows' => true,
));
$out = array();
foreach ($ids as $id) {{
    $terms = wp_get_object_terms((int) $id, 'product_visibility', array('fields' => 'slugs'));
    if (is_wp_error($terms) || !is_array($terms)) {{
        $terms = array();
    }}
    $terms = array_map('strval', $terms);
    sort($terms);
    $out[(string) (int) $id] = array_values($terms);
}}
echo "{START}\\n";
echo wp_json_encode($out);
echo "\\n{END}\\n";
"""
    data = extract_json(wp_eval(php))
    if not isinstance(data, dict) or not data:
        raise RuntimeError("Snapshot was empty.")
    SNAPSHOTS.mkdir(parents=True, exist_ok=True)
    path = SNAPSHOTS / f"{datetime.now().strftime('%Y%m%d-%H%M')}-product-visibility.json"
    ordered = {key: data[key] for key in sorted(data, key=lambda item: int(item))}
    path.write_text(json.dumps(ordered, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    with_terms = sum(1 for slugs in ordered.values() if slugs)
    print(f"Saved {path}")
    print(f"products={len(ordered)} with_terms={with_terms}")
    return path


def restore(path: Path, only_id: int | None) -> None:
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise SystemExit("Snapshot must be an object of product_id -> term slugs.")
    if only_id is not None:
        key = str(only_id)
        if key not in data:
            raise SystemExit(f"Product {only_id} is not in {path}.")
        data = {key: data[key]}
    payload = base64.b64encode(json.dumps(data).encode("utf-8")).decode("ascii")
    php = f"""<?php
$data = json_decode(base64_decode('{payload}'), true);
if (!is_array($data)) {{
    WP_CLI::error('Visibility JSON could not be decoded');
}}
$report = array();
foreach ($data as $id => $slugs) {{
    $id = (int) $id;
    if (!get_post($id)) {{
        WP_CLI::error("Post $id not found");
    }}
    if (!is_array($slugs)) {{
        $slugs = array();
    }}
    $slugs = array_values(array_map('strval', $slugs));
    wp_set_object_terms($id, $slugs, 'product_visibility', false);
    delete_post_meta($id, '_odella_auto_hidden');
    delete_post_meta($id, '_odella_auto_hidden_terms');
    if (function_exists('wc_delete_product_transients')) {{
        wc_delete_product_transients($id);
    }}
    $now = wp_get_object_terms($id, 'product_visibility', array('fields' => 'slugs'));
    if (is_wp_error($now) || !is_array($now)) {{
        $now = array();
    }}
    $now = array_map('strval', $now);
    sort($now);
    $want = $slugs;
    sort($want);
    $report[(string) $id] = array('ok' => $now === $want, 'terms' => $now);
}}
echo "{START}\\n";
echo wp_json_encode($report);
echo "\\n{END}\\n";
"""
    report = extract_json(wp_eval(php))
    failed = [pid for pid, row in report.items() if not row.get("ok")]
    print(f"restored={len(report)} failed={len(failed)}")
    if failed:
        print("mismatch: " + ", ".join(failed[:20]))
        raise SystemExit(1)
    if only_id is not None:
        row = report[str(only_id)]
        print(f"product {only_id} terms={json.dumps(row['terms'], ensure_ascii=False)}")


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(description="Save or restore product_visibility terms.")
    sub = parser.add_subparsers(dest="cmd", required=True)
    sub.add_parser("save")
    restore_parser = sub.add_parser("restore")
    restore_parser.add_argument("file", type=Path)
    restore_parser.add_argument("--id", type=int, default=0, help="Restore one product and check it.")
    args = parser.parse_args()
    if args.cmd == "save":
        save()
        return
    only = args.id if args.id else None
    restore(args.file, only)


if __name__ == "__main__":
    main()
