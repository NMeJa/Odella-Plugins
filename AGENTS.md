# Odella Workspace Rules

## Owned code — local-first policy
1. **Local source of truth**: `Odella-Plugins` (plugin), `site/mu-plugins` (mu-plugin), and `site/theme-child` (child theme).
2. **No direct remote edits**: Never patch those trees on the server with ad-hoc `ssh` or `scp`.
3. **Deploy**:
   - Check: `python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --check --all`
   - Deploy one target: `python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target plugin|mu|theme` (`--no-bump` skips the plugin version bump).
   - Roll back the newest remote backup: `python "c:\Users\Nick\Projects\Hostinger-Connect\tools\deploy.py" --target plugin|mu|theme --rollback`
   - Exit **2** purge failed, **3** drift, **4** smoke test failed.
   - Follow `.claude/skills/odella-plugin-deploy/SKILL.md` whenever working on these trees.

## Skills
All project skills live in `.claude/skills/` (Cursor and Claude Code both load this folder, so keep one copy only).
- `odella-plugin-deploy`: local-first edit and deploy of the plugin, mu-plugin, and child theme.
- `odella-pagespeed`: owner-invoked only (`disable-model-invocation`). Do not run it unless the owner asks.
- `wp-performance`, `wp-wpcli-and-ops`, `wp-plugin-development`, `wp-project-triage`: official WordPress/agent-skills, used for their reference docs.

## This host vs. the WordPress skills
The WordPress skills assume WP-CLI runs on the same machine. Here it does not:
- WP-CLI exists only on the server. Run it over SSH: `ssh -p 65002 u981153228@46.17.175.131 "cd domains/odella.ge/public_html && wp <command>"`. The skills' `node .../scripts/*.mjs` inspectors call a local `wp` and will not work; skip them.
- `proc_open` is disabled on the server, so WP-CLI commands that start a subprocess fail: `wp db query`, `wp db export`, `wp db import`, `wp db cli`. Query with `wp eval` and `$wpdb` instead; take database backups from hPanel.
- Object cache (Redis/Memcached) is not available on this hosting plan; ignore advice that depends on it.
- Server Python is 3.6: remote scripts must not use syntax newer than 3.6.
