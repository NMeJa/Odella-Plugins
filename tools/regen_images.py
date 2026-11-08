#!/usr/bin/env python3
"""Regenerate missing WordPress image sizes in small batches.

Local Python 3.12. Each batch is one `wp media regenerate` over SSH.
Stops on the first non-zero exit so a failed batch can be resumed with
--start-after.
"""

from __future__ import annotations

import argparse
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REPORTS = ROOT / "reports"
SSH = ["ssh", "-p", "65002", "-o", "BatchMode=yes", "u981153228@46.17.175.131"]
WP_PREFIX = "cd /home/u981153228/domains/odella.ge/public_html &&"


def wp(command: str, timeout: int, limit: int | None = None) -> subprocess.CompletedProcess[str]:
    # `timeout` kills a hung remote PHP process when the SSH client gives up.
    # Exit 124 is that limit. Other non-zero exits are server errors.
    remote_limit = limit if limit is not None else max(30, timeout - 20)
    remote = f"{WP_PREFIX} timeout {remote_limit} wp {command}"
    return subprocess.run(
        [*SSH, remote],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=timeout,
        check=False,
    )


def attachment_ids(timeout: int) -> list[int]:
    proc = wp(
        "post list --post_type=attachment --post_mime_type=image --posts_per_page=1000 --field=ID --format=csv",
        timeout,
    )
    if proc.returncode != 0:
        sys.stderr.write(proc.stderr)
        sys.stderr.write(proc.stdout)
        raise SystemExit(f"Could not list attachments (exit {proc.returncode}).")
    ids: list[int] = []
    for line in (proc.stdout or "").splitlines():
        line = line.strip()
        if line.isdigit():
            ids.append(int(line))
    if not ids:
        raise SystemExit("No image attachments returned.")
    return ids


def run_regen(chunk: list[int], timeout: int, log, label: str) -> tuple[int, float]:
    id_list = " ".join(str(item) for item in chunk)
    started = time.perf_counter()
    try:
        proc = wp(f"media regenerate {id_list} --only-missing --yes", timeout + 30, limit=timeout)
    except subprocess.TimeoutExpired:
        log.write(f"TIMEOUT {label} ids {id_list}\n")
        log.flush()
        return 124, time.perf_counter() - started
    elapsed = time.perf_counter() - started
    log.write(proc.stdout or "")
    if proc.stderr:
        log.write(proc.stderr)
    log.write(f"# {label} exit {proc.returncode} {elapsed:.1f}s\n")
    log.flush()
    if proc.returncode not in (0, 124):
        tail = (proc.stderr or proc.stdout or "").strip().splitlines()
        print("\n".join(tail[-20:]), flush=True)
    return proc.returncode, elapsed


def regenerate_chunk(chunk: list[int], timeout: int, log) -> tuple[int, list[int]]:
    label = f"ids {chunk[0]}..{chunk[-1]}"
    code, _elapsed = run_regen(chunk, timeout, log, label)
    if code == 0:
        return 0, []
    if code != 124:
        return code, []
    if len(chunk) == 1:
        log.write(f"SKIP {chunk[0]} hung\n")
        print(f"skip {chunk[0]} (hung, killed)", flush=True)
        return 0, [chunk[0]]
    print(f"batch hung; retrying {len(chunk)} images one at a time", flush=True)
    skipped: list[int] = []
    for item in chunk:
        code, _elapsed = run_regen([item], min(timeout, 90), log, f"id {item}")
        if code == 124:
            skipped.append(item)
            log.write(f"SKIP {item} hung\n")
            print(f"skip {item} (hung, killed)", flush=True)
            continue
        if code != 0:
            return code, skipped
    return 0, skipped


def main() -> None:
    parser = argparse.ArgumentParser(description="Regenerate missing image sizes in batches.")
    parser.add_argument("--batch", type=int, default=25, help="Attachments per SSH call.")
    parser.add_argument("--pause", type=float, default=5.0, help="Seconds to wait between batches.")
    parser.add_argument("--start-after", type=int, default=0, help="Skip IDs up to and including this one.")
    parser.add_argument("--timeout", type=int, default=600, help="Seconds allowed for one batch.")
    args = parser.parse_args()
    if args.batch < 1:
        raise SystemExit("--batch must be at least 1.")

    ids = sorted(attachment_ids(args.timeout))
    pending = [item for item in ids if item > args.start_after]
    REPORTS.mkdir(parents=True, exist_ok=True)
    log_path = REPORTS / f"regen-{datetime.now().strftime('%Y%m%d')}.log"
    total = len(pending)
    batches = (total + args.batch - 1) // args.batch
    header = f"attachments={len(ids)} pending={total} batch={args.batch} start_after={args.start_after}"
    print(header, flush=True)
    with log_path.open("a", encoding="utf-8") as log:
        log.write(f"\n# {datetime.now().isoformat(timespec='seconds')} {header}\n")
        done = 0
        skipped: list[int] = []
        for index in range(batches):
            chunk = pending[index * args.batch : (index + 1) * args.batch]
            print(f"batch {index + 1}/{batches} ids {chunk[0]}..{chunk[-1]} ({len(chunk)})", flush=True)
            code, one_skipped = regenerate_chunk(chunk, args.timeout, log)
            skipped.extend(one_skipped)
            if code != 0:
                print(f"Stopped on batch {index + 1}/{batches} (exit {code}). Resume with --start-after {chunk[0] - 1}", flush=True)
                raise SystemExit(code)
            done += len(chunk) - len(one_skipped)
            print(f"ok {done}/{total} skipped {len(skipped)} (last id {chunk[-1]})", flush=True)
            if index + 1 < batches and args.pause > 0:
                time.sleep(args.pause)
        print(f"done {done}/{total}, skipped {len(skipped)}. log {log_path}", flush=True)
        if skipped:
            print("skipped ids: " + " ".join(str(item) for item in skipped), flush=True)


if __name__ == "__main__":
    main()
