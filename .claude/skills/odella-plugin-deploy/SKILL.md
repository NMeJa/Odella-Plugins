---
name: odella-plugin-deploy
description: >-
  Local-first edit and atomic deploy for Odella-owned code: the Elementor
  widgets plugin (Odella-Plugins), the mu-plugin (site/mu-plugins), and the
  child theme (site/theme-child). Use whenever inspecting, modifying, fixing,
  building, or deploying any of those trees.
---

# Odella deploy — plugin, mu-plugin, and child theme

Edit owned code only in this repo. Deploy with `tools/deploy.py`. Never patch the live plugin, mu-plugin, or child theme over ad-hoc `ssh` or `scp`.

## Step 1: Check parity

```powershell
python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --check --all
```

`--target plugin`, `--target mu`, or `--target theme` checks one tree. `[OK]` on every file means continue. `[MODIFIED]`, `[LOCAL+]`, or `[REMOTE+]` means reconcile that file locally before editing.

## Step 2: Edit locally

| `--target` | Local tree |
|---|---|
| `plugin` (default) | `Odella-Plugins/` — `odella-elementor-widgets.php`, `includes/`, `assets/` |
| `mu` | `site/mu-plugins/odella-performance.php` |
| `theme` | `site/theme-child/` |

Leave `hostinger-auto-updates.php` alone.

## Step 3: Deploy

```powershell
python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target plugin
python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target mu
python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target theme
```

`--no-bump` deploys the plugin without incrementing `NMJ_VERSION`. `--all` deploys plugin, then mu, then theme. `Odella-Plugins/deploy.py` is a wrapper for `--target plugin`.

Before the swap, the script copies the live target to `~/odella-deploy-backups/<target>/<UTC timestamp>/` (newest 5 kept). If the server does not match `.deploy-state/<target>.json`, it exits **3** unless `--force`.

```powershell
python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target plugin --rollback
```

`--rollback` restores the newest backup of that target, purges caches, and rewrites the state file.

Exit codes: **2** purge failed (the files stay as deployed; nothing rolls back by itself), **3** drift, **4** smoke test failed.

After a deploy whose output says `CDN not purged by script — agent: purge via Hostinger MCP`, purge the CDN with Hostinger `hosting_cache_clear` for `odella.ge`, scope `cdn`.
