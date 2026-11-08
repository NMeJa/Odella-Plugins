#!/usr/bin/env python3
import subprocess
import sys
from pathlib import Path

raise SystemExit(subprocess.call([sys.executable, str(Path(__file__).resolve().parent.parent / "tools" / "deploy.py"), "--target", "plugin", *sys.argv[1:]]))
