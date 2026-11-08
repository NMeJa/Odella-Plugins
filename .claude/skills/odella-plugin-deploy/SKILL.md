---
name: odella-plugin-deploy
description: >-
  Local-first development, comparison, packaging, and atomic deployment workflow
  for the Odella Elementor Widgets plugin (`Odella-Plugins` / `odella-elementor-widgets`).
  Use whenever inspecting, modifying, fixing, building, or deploying any widget,
  PHP class, JS script, or CSS file in `Odella-Plugins` (`odella-elementor-widgets`).
---

# Odella Elementor Widgets — Local-First Development & Atomic Deployment Skill

Whenever a task touches `odella-elementor-widgets` (`c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins`), you **MUST** follow this local-first workflow. **Never** edit plugin files directly on the remote Hostinger server via ad-hoc SSH/SCP commands.

## Mandatory Workflow

### Step 1: Verify Local vs. Remote Parity Before Editing
Before making changes, check that local [`Odella-Plugins`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins) matches the live server:

```powershell
python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py" --check
```

- If all 20 files report `[OK]`, proceed to Step 2.
- If any file reports `[MODIFIED]`, `[LOCAL+]`, or `[REMOTE+]`, inspect and reconcile the difference locally before making edits.

### Step 2: Implement and Fix Changes Locally
Apply all PHP, JS, and CSS edits strictly inside the local repository:
- **Main plugin bootstrap**: [`c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\odella-elementor-widgets.php`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins/odella-elementor-widgets.php)
- **Core loader & AJAX handlers**: [`c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\includes\plugin.php`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins/includes/plugin.php)
- **Elementor widgets**: `c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\includes\widgets\*.php`
- **Frontend JS & CSS**: `c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\assets\js\*.js` and `assets\css\*.css`

### Step 3: Deploy Atomically Using `deploy.py`
Once local changes are ready, deploy them using [`deploy.py`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins/deploy.py) instead of manual `scp`/`ssh` commands:

```powershell
python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py"
```

*(Or pass `--no-bump` if you want to deploy without incrementing the patch version in `odella-elementor-widgets.php` and `package.json`.)*

### What `deploy.py` Does Automatically
1. **Bumps Version & Builds Archive**: Runs [`build.js`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins/build.js) to increment `NMJ_VERSION` in `odella-elementor-widgets.php` and `package.json`, and builds `c:\Users\Nick\Projects\Hostinger-Connect\OdellaPlugins.zip` with verified POSIX path separators.
2. **Normalizes Line Endings**: Converts `CRLF` to `LF` in memory for all text assets (`.php`, `.js`, `.css`, `.json`, `.svg`).
3. **Stages & Lints on Remote Server**: Extracts the payload into a staging directory on the same filesystem (`/wp-content/plugins/.odella-widgets-stage-*`) and runs `php -l` syntax checks on every `.php` file before touching the live plugin directory.
4. **Atomic Directory Replacement**: Swaps the validated staging directory into `/home/u981153228/domains/odella.ge/public_html/wp-content/plugins/odella-elementor-widgets` (with automatic rollback if any error occurs), ensuring zero stale or `.bak` files remain.
5. **Flushes All Caches**: Clears the Elementor CSS cache, WordPress Object Cache, and LiteSpeed Cache (`wp litespeed-purge all`).
6. **Post-Deploy SHA-256 Verification**: Re-computes SHA-256 checksums across all remote files and verifies 100% parity with local `Odella-Plugins`.
