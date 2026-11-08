# Odella Workspace Rules

## Odella Elementor Widgets (`Odella-Plugins`) — Local-First Policy
1. **Local Source of Truth**: `c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins` is the single source of truth for the `odella-elementor-widgets` WordPress plugin.
2. **No Direct Remote Edits**: Never manually edit, patch, or upload individual files into `/home/u981153228/domains/odella.ge/public_html/wp-content/plugins/odella-elementor-widgets` via ad-hoc `ssh` or `scp` commands.
3. **Mandatory Deployment Script**:
   - First check parity: `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py" --check`
   - Make all code changes locally inside `c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins`.
   - Deploy and replace the remote plugin using:
     `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py"`
     (or `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py" --no-bump` when re-deploying without a version bump).
   - Activate and follow the `odella-plugin-deploy` skill (`.claude/skills/odella-plugin-deploy/SKILL.md`) whenever working on `Odella-Plugins`.

## Skills
All project skills live in `.claude/skills/` (Cursor and Claude Code both load this folder, so keep one copy only).
- `odella-plugin-deploy`: local-first edit and deploy of `Odella-Plugins`.
- `wp-performance`, `wp-wpcli-and-ops`, `wp-plugin-development`, `wp-project-triage`: official WordPress/agent-skills, used for their reference docs.

## This host vs. the WordPress skills
The WordPress skills assume WP-CLI runs on the same machine. Here it does not:
- WP-CLI exists only on the server. Run it over SSH: `ssh -p 65002 u981153228@46.17.175.131 "cd domains/odella.ge/public_html && wp <command>"`. The skills' `node .../scripts/*.mjs` inspectors call a local `wp` and will not work; skip them.
- `proc_open` is disabled on the server, so WP-CLI commands that start a subprocess fail: `wp db query`, `wp db export`, `wp db import`, `wp db cli`. Query with `wp eval` and `$wpdb` instead; take database backups from hPanel.
- Object cache (Redis/Memcached) is not available on this hosting plan; ignore advice that depends on it.
- Server Python is 3.6: remote scripts must not use syntax newer than 3.6.
