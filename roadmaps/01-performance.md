# Roadmap 01 — Performance, Full-Page Scroll, Page Transitions

**For:** the Cursor agent implementing this. **Written:** 2026-11-08 from a grilling session; every decision below is already agreed with the owner, so do not re-open it.
**Site:** https://odella.ge (WordPress 7.1.4, WooCommerce 11.2.2, Elementor 4.3.6 / Pro 4.3.3, LiteSpeed Cache 7.9.3, Hostinger CDN).

Read first: `AGENTS.md`, `PROJECT_BRIEF.md`, `DECISIONS.md`, skill `.claude/skills/odella-plugin-deploy/SKILL.md`.

---

## Ground rules (apply to every phase)

1. **One slice at a time.** Finish a slice, verify it, commit it, then start the next. Never batch two phases into one deploy.
2. **Never edit files on the server.** Code changes go local → `tools/deploy.py`. Database changes (Elementor data, options) go through `tools/snapshot.py save` first, then a `wp eval-file -` script, never hand-typed `wp option update` on JSON.
3. **SSH form** (all WP-CLI runs on the server):
   `ssh -p 65002 u981153228@46.17.175.131 "cd domains/odella.ge/public_html && wp <command>"`
   For PHP longer than one line, pipe it: `Get-Content script.php | ssh -p 65002 u981153228@46.17.175.131 "cd domains/odella.ge/public_html && wp eval-file -"` (PowerShell) — `wp eval-file -` reads PHP from stdin.
4. **Host limits:** `proc_open` is disabled → `wp db query|export|import|cli` fail; use `$wpdb` inside `wp eval`. No Redis/Memcached. Server Python is **3.6** → remote Python must not use `capture_output=`, walrus, or other 3.7+ features (use `stdout=subprocess.PIPE`).
5. **Do not call the PageSpeed API yourself.** The owner runs `tools/psi.py` manually and tells you "read the latest report" (`reports/psi-*.md`, newest file). Same for the full cache warm (`tools/warm_cache.py`).
6. **Do not touch WOOF** (woocommerce-products-filter) or anything in `odella-performance.php` section 10 / `odella_is_catalog_filter_page()`. The filter is being redesigned in roadmap 03.
7. **Speed tests are always done logged out** (private window). Logged-in admins bypass LiteSpeed cache; their timings mean nothing.
8. **Commit after each verified slice:** `git add -A && git commit -m "perf(<phase>): <what>"` (git is set up in Phase 0).

### Standard verification matrix

Unless a slice says otherwise, "verify" means all of these, logged out:

| View | How |
|---|---|
| Mobile | Chrome DevTools device mode, iPhone 12 Pro (390×844) |
| Desktop | 1440×900 window |
| Ultra-wide | 2560×1080 (DevTools responsive) |
| KA | `/ka/` URLs |
| EN | `/en/` equivalents **where they exist**. EN is mostly unpublished today (Home `14592` is draft). If an EN URL returns 404, write "EN: n/a (404)" in the slice notes — do not try to fix it here. |

Key URLs (`tools/urls.txt`, created in 0.4):
```
https://odella.ge/ka/
https://odella.ge/ka/store/
https://odella.ge/ka/product-category/women/coat-women/
https://odella.ge/ka/product/women/kimono-women/cardigan-dream-bordeaux/
https://odella.ge/ka/elementor-15559/
https://odella.ge/ka/my-account-2/
https://odella.ge/ka/our-story/
https://odella.ge/en/store/
```
Every deploy must end with all of them returning HTTP 200 (checkout may 302 to cart when the cart is empty — accept 200/302) and no `There has been a critical error` in the body.

---

## Phase 0 — Safety net and tooling

Goal: every later change can be deployed, verified and rolled back with one command, and nothing needs hand-copying to the server.

### 0.1 Owner backup (USER STEP — stop and ask)
Ask the owner to take a full backup in hPanel → Websites → odella.ge → Backups → "Generate new backup" (files + database), and wait for "done" before continuing. `wp db export` does not work on this host.

### 0.2 Git + local mirror of owned code
In `C:\Users\Nick\Projects\Hostinger-Connect`:

1. Pull the child theme (owned code, 156 KB) — skip `.bak` files:
   ```powershell
   mkdir site\theme-child, site\mu-plugins
   scp -P 65002 "u981153228@46.17.175.131:domains/odella.ge/public_html/wp-content/themes/hello-theme-child-master/{functions.php,style.css,readme.txt,screenshot.png}" site\theme-child\
   ```
2. Move the mu-plugin mirror into place: `git mv`-free first time, just `Move-Item odella-performance.php site\mu-plugins\odella-performance.php`. It is already byte-identical to the server copy (verified 2026-11-08); re-verify with the `mu` target `--check` once 0.3 exists.
3. `.gitignore`:
   ```
   .env
   OdellaPlugins.zip
   reports/raw/
   Odella-Plugins/.build_temp/
   Odella-Plugins/Test Videos/
   node_modules/
   __pycache__/
   ```
4. `git init`, `git add -A`, `git commit -m "chore: baseline of live site code (plugin 1.0.56, mu-plugin 1.1.0, child theme)"`.

### 0.3 Generalise the deploy tool → `tools/deploy.py`
Move the logic of `Odella-Plugins/deploy.py` into `tools/deploy.py` and leave `Odella-Plugins/deploy.py` as a 5-line wrapper that calls `tools/deploy.py --target plugin` with the same flags (so existing docs keep working).

Targets:

| `--target` | Local source | Remote destination | Swap type |
|---|---|---|---|
| `plugin` (default) | `Odella-Plugins/{odella-elementor-widgets.php,includes,assets}` | `wp-content/plugins/odella-elementor-widgets/` | directory swap (current logic) |
| `mu` | `site/mu-plugins/odella-performance.php` | `wp-content/mu-plugins/odella-performance.php` | single file: upload to `odella-performance.php.new` in the same dir, `php -l`, `os.rename` over the old file. Never touch `hostinger-auto-updates.php`. |
| `theme` | `site/theme-child/` | `wp-content/themes/hello-theme-child-master/` | directory swap |

Flags: `--check`, `--no-bump` (plugin only; version bump only applies to `plugin`), `--rollback`, `--force`, `--purge-only`, `--all` (= plugin, mu, theme in that order).

Required behaviour (all new except where noted):

1. **Drift guard.** Keep the hashes of the last successful deploy in `.deploy-state/<target>.json` (committed to git). Before deploying, fetch remote hashes. If remote ≠ last-deployed state, print the differing files and abort with exit code 3 unless `--force`. First run with no state file: if remote == local, write the state file and continue; otherwise abort and tell the owner.
2. **Remote backup.** Before the swap, copy the current remote target to `/home/u981153228/odella-deploy-backups/<target>/<UTC yyyymmdd-HHMMSS>/` (outside `public_html`, so WordPress never sees it as a second plugin). Keep the newest 5, delete older. Replace the current "delete `.old-deploy`" behaviour with this.
3. **`--rollback`.** Restore the newest backup of `--target` with the same atomic swap, then run the purge sequence, then update `.deploy-state/<target>.json` to the restored hashes. Print which backup timestamp was restored.
4. **Purge sequence** (after every deploy/rollback, and alone with `--purge-only`), in this order, each step's exit code and output **printed**, any failure → exit code 2 (do not auto-rollback):
   1. `wp eval "\Elementor\Plugin::\$instance->files_manager->clear_cache(); wp_cache_flush();"`
   2. `wp litespeed-purge all`
   3. Hostinger CDN purge: `DELETE https://developers.hostinger.com/api/hosting/v1/accounts/{HOSTINGER_USERNAME}/websites/odella.ge/cdn/purge` with header `Authorization: Bearer {HOSTINGER_API_TOKEN}` (read both from `.env`; use `urllib.request`, no extra packages). **The token is optional** — the owner connected Hostinger to the agent by OAuth instead, and a script cannot use that login. If the token is missing, print `CDN not purged by script — agent: purge via Hostinger MCP` and continue; the **agent** then calls the Hostinger MCP tool `hosting_cache_clear` (domain `odella.ge`, scope `cdn`) right after the deploy. If that tool is not available in the current agent session, tell the owner and continue (LiteSpeed purge has already run). HTTP 422 means CDN is off → warn, continue.
5. **Smoke test.** After the purge, GET every URL in `tools/urls.txt` once (mobile UA). Fail (exit 4) on any status not in {200, 302} or any body containing `There has been a critical error`. Print status + TTFB per URL. This also warms those 8 pages.
6. **Version check (plugin only).** Confirm the homepage HTML references `odella-elementor-widgets` assets with `ver=<new version>`.
7. **Reminder line** at the end: `Full cache warm: python tools\warm_cache.py (optional, ~25 min)`.

`.env` (gitignored; create `.env.example` with empty values and commit that):
```
HOSTINGER_API_TOKEN=
HOSTINGER_USERNAME=u981153228
PSI_API_KEY=
```
**Status (2026-11-08):** the owner has set `PSI_API_KEY` only. `HOSTINGER_API_TOKEN` is intentionally empty (Hostinger is connected to the agent via OAuth/MCP). Do not ask for a token; use the MCP fallback above. If a token is added later, confirm the username with `GET https://developers.hostinger.com/api/hosting/v1/websites?domain=odella.ge` (expected `u981153228`).

Verify 0.3:
- `python tools\deploy.py --check --all` → all three targets report parity.
- `python tools\deploy.py --target theme` (no local changes) → backup created, smoke test passes, script prints the "agent: purge via Hostinger MCP" line; agent runs the MCP CDN purge and it succeeds.
- `python tools\deploy.py --target theme --rollback` → restores that backup, smoke test passes.
- Test the drift guard without touching the server: temporarily change one hash in `.deploy-state/theme.json`, confirm `deploy.py --target theme` aborts with exit 3, then `git checkout .deploy-state/theme.json`.

### 0.4 Measurement, warming, snapshot and probe tools (all in `tools/`, stdlib-only Python 3.10+ locally)

1. **`tools/urls.txt`** — the key URL list above.
2. **`tools/psi.py`** — owner-run only.
   - `python tools\psi.py` (default = home, store, product URLs from `urls.txt` lines 1, 2, 4) or `python tools\psi.py <url> [<url>…]`.
   - Calls `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=…&strategy=mobile|desktop&category=performance&key=PSI_API_KEY`, sequentially.
   - Writes `reports/psi-YYYYMMDD-HHMM.md`: one table per URL with mobile and desktop columns: score, FCP, LCP, TBT, CLS, Speed Index, server response time, total byte weight, DOM size; then LCP element snippet; then top 5 opportunities/diagnostics by savings (title + savings + top 3 URLs each). Raw JSON → `reports/raw/` (gitignored).
   - Must stay under ~150 lines of output so an agent can read it cheaply.
3. **`tools/warm_cache.py`** — owner-run only.
   - Reads `https://odella.ge/sitemap_index.xml` → child sitemaps (`page-sitemap.xml`, `product-sitemap1.xml`, `product-sitemap2.xml`, …) → all `<loc>`.
   - For each URL: GET with mobile UA `Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)`, then desktop UA `Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)`. One request at a time, `--delay` default 1.0 s between requests. Read the body fully (LiteSpeed only stores completed responses).
   - Flags: `--limit N`, `--only pages|products`, `--delay S`.
   - Progress line every 25 URLs; final summary: count, how many `x-litespeed-cache: hit` vs `miss`, slowest 10 URLs with TTFB.
4. **`tools/snapshot.py`**
   - `save --label <name> [--post ID …] [--option NAME …]` → `snapshots/<yyyymmdd-HHMM>-<label>.json` containing, per post: `post_status`, `_elementor_data`, `_elementor_page_settings`; per option: its value. Fetch via `wp eval-file -` printing JSON between markers.
   - `restore <file>` → writes back with `update_post_meta($id, $key, wp_slash($value))` / `update_option`, then runs the purge sequence (`tools/deploy.py --purge-only`).
   - Snapshots are committed to git (they are the rollback for database edits).
5. **`tools/probe_css.py`** — fetch every URL in `urls.txt` (mobile + desktop UA), collect every `<link rel="stylesheet" href>` and every `<script src>`, HEAD/GET each once, report any non-200. Exit 1 if any 404. Used in Phase 3.
6. **Skill `.claude/skills/odella-pagespeed/SKILL.md`** with frontmatter `disable-model-invocation: true` and a description saying it is owner-invoked only. Body: "Run nothing. Read the newest `reports/psi-*.md`, compare with the previous one, list regressions first, then the top 3 next actions mapped to files in this repo."

**USER STEP (PSI key):** Google Cloud Console → create project "odella-psi" → APIs & Services → Library → enable "PageSpeed Insights API" → Credentials → Create credentials → API key → restrict it to "PageSpeed Insights API" → paste into `.env` as `PSI_API_KEY`. Free; ~25,000 queries/day.

### 0.5 Revisions cleanup
446 revisions exist (300+ on Home `13863`), and `WP_POST_REVISIONS` is unlimited.
1. `wp config set WP_POST_REVISIONS 10 --raw --type=constant`
2. `wp eval-file -` with: for every post that has revisions, `wp_get_post_revisions($id)` (newest first) → `wp_delete_post_revision()` everything after the 10th. Print total deleted.
3. Verify: revision count printed again (expect ≤ 10 × number of posts with revisions); homepage still loads; Elementor editor opens Home and its History panel works.
Rollback: hPanel backup from 0.1 (revisions are not otherwise recoverable — that is why 0.1 is mandatory).

### 0.6 Docs
- Update `.claude/skills/odella-plugin-deploy/SKILL.md`: rename the title to cover all three targets, point to `tools/deploy.py`, remove the hard-coded "20 files", document `--rollback`, exit codes (2 purge failed, 3 drift, 4 smoke test failed).
- Update `AGENTS.md` and `PROJECT_BRIEF.md` section 5 the same way; add `site/` to the local-first rule.
- `DECISIONS.md`: fix the stale line saying Elementor is pinned at 4.2.3 (live: 4.3.6 / Pro 4.3.3). Add entries for: local-first now covers mu-plugin + child theme; remote backups live in `~/odella-deploy-backups`.
- **Commit:** `perf(phase0): deploy targets, rollback, CDN purge, measurement tools`.

**Checkpoint:** ask the owner to run `python tools\psi.py` once to record the **baseline** and to say "baseline done".

---

## Phase 1 — Full-page scroll: footer reachable, snap on mobile and desktop

Agreed behaviour: snapping stays on mobile and desktop for Video → Categories → Products → Instagram. Footer is **not** a snap section: from Instagram a swipe/wheel down glides to the very bottom of the page with the whole footer visible; swipe/wheel up returns to Instagram. Dots go from 5 to 4.

Root cause (verified): widget `c596a33` (`nmj_full_page_scroll`) on Home `13863` lists `footer-id` as its 5th section, so "Allow Scroll Past Last Section" only releases after the footer; and `odella-performance.php` section 5 forces `#footer-id{min-height:100vh;scroll-snap-align:start;scroll-snap-stop:always}`. The footer is 867 px tall on a 812 px phone, so its bottom is unreachable under mandatory snap.

### 1.1 Snapshot
`python tools\snapshot.py save --label phase1-fps --post 13863`

### 1.2 Remove the footer section from the widget (database)
`wp eval-file -` with:
```php
<?php
$id  = 13863;
$d   = json_decode( get_post_meta( $id, '_elementor_data', true ), true );
$removed = 0;
$walk = function ( array &$els ) use ( &$walk, &$removed ) {
    foreach ( $els as &$e ) {
        if ( ( $e['id'] ?? '' ) === 'c596a33' ) {
            $before = count( $e['settings']['sections'] );
            $e['settings']['sections'] = array_values( array_filter(
                $e['settings']['sections'],
                function ( $s ) { return ( $s['section_id'] ?? '' ) !== 'footer-id'; }
            ) );
            $removed += $before - count( $e['settings']['sections'] );
        }
        if ( ! empty( $e['elements'] ) ) { $walk( $e['elements'] ); }
    }
};
$walk( $d );
if ( $removed !== 1 ) { WP_CLI::error( "Expected to remove 1 section, removed $removed. Nothing saved." ); }
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $d ) ) );
\Elementor\Plugin::$instance->files_manager->clear_cache();
WP_CLI::success( 'footer-id removed from c596a33' );
```
Keep `allow_scroll_past = yes` (it now releases after Instagram, which is what we want).

### 1.3 Footer CSS in the mu-plugin
In `site/mu-plugins/odella-performance.php`, section 5 (`odella-fps-critical-css`):
- Change the selector list `#categories,#products,#instagram,#footer-id{…}` → `#categories,#products,#instagram{…}` (rule body unchanged).
- Append a new rule: `#footer-id{scroll-snap-align:end!important;scroll-snap-stop:normal!important}`.
- Bump the header `Version: 1.1.0` → `1.2.0`.

Deploy: `python tools\deploy.py --target mu`.

No change is needed in `full-page-scroll.js` (it only styles the sections listed in the widget). If verification shows the wheel/touch release does not fire at Instagram, fix it in `Odella-Plugins/assets/js/full-page-scroll.js` and deploy with `--target plugin`.

### 1.4 Verify (KA home only — EN home is draft)
- Mobile: swipe from top → each swipe lands exactly on Video, Categories, Products, Instagram. One more swipe down from Instagram → page reaches the very bottom, footer fully visible including its last line. Swipe up from footer → lands on Instagram. Dots: 4.
- Desktop + ultra-wide: same with mouse wheel and with keyboard PageDown/PageUp. Trackpad: no double-jumps.
- No layout shift: DevTools → Performance → reload → CLS < 0.1.
- Open the slide-out cart and filters drawer on the homepage: scrolling inside them still works (wheel handler ignores them).

Rollback: `python tools\snapshot.py restore snapshots\<file>-phase1-fps.json` and `python tools\deploy.py --target mu --rollback`.
**Commit:** `perf(phase1): footer reachable under full-page scroll`.

---

## Phase 2 — Image sizes (sharp product photos, smaller downloads)

WebP is already served automatically by the Hostinger CDN (`Accept: image/webp` → `Content-Type: image/webp`), so **no WebP plugin and no QUIC.cloud**. The problem is that Perfect Images (wp-retina-2x-pro) has disabled generating most WordPress/WooCommerce sizes, so pages fall back to wrong sizes (main product photo renders 272×408 natural pixels in a 315–355 px wide box; elsewhere 800×1200 originals are downloaded).

### 2.1 Snapshot
`python tools\snapshot.py save --label phase2-images --option wr2x_options --post 15154`

### 2.2 Re-enable sizes in Perfect Images (database)
`wp eval-file -`: load `get_option('wr2x_options')`; for each of `thumbnail`, `medium_large`, `large`, `woocommerce_thumbnail`, `woocommerce_single`, `woocommerce_gallery_thumbnail`:
- remove it from `disabled_sizes`;
- in `sizes[]`, set `enabled => true` for the entry with that `name`.
Leave `medium`, `1536x1536`, `2048x2048`, the two variation-swatch sizes disabled. Leave retina/WebP settings untouched. `update_option`, then print `get_intermediate_image_sizes()` to confirm the six sizes are present.

### 2.3 Cap new uploads at 2560 px
In `site/mu-plugins/odella-performance.php` add (new section 12):
```php
add_filter( 'big_image_size_threshold', function () { return 2560; }, 999 );
```
Deploy `--target mu`. Affects new uploads only; existing originals stay (they are only used by the lightbox).

### 2.4 Regenerate missing sizes in batches
1. Check disk headroom first: `du -sh ~/domains/odella.ge/public_html/wp-content/uploads` (2.5 GB on 2026-11-08) and ask the owner to confirm the plan's disk limit in hPanel has ≥ 1 GB free. Expect roughly +300 MB.
2. Test on one image first: get the featured image of product 18781 with `wp post meta get 18781 _thumbnail_id`, run `wp media regenerate <that ID> --only-missing --yes`, then `ls` its files in `wp-content/uploads` and confirm `-900x…`, `-600x…`, `-1024x…`, `-768x…`, `-100x100`, `-150x150` exist.
3. Create `tools/regen_images.py`: gets all image attachment IDs (`wp post list --post_type=attachment --post_mime_type=image --field=ID --format=csv`), runs `wp media regenerate <25 ids> --only-missing --yes` per SSH call, 5 s pause between batches, `--start-after ID` to resume, logs to `reports/regen-<date>.log`. 532 attachments → ~22 batches.
4. Run it.

### 2.5 Point the product gallery at the right sizes
Template `15154`, widget `f48a81a` (`nmj_gallery_grid`) has no saved sizes, so it uses the code default `large`. Set explicitly via `wp eval-file -` (same walk pattern as 1.2, match `id === 'f48a81a'`):
- `image_size` → `woocommerce_single`
- `thumb_image_size` → `woocommerce_gallery_thumbnail`
Also apply to any Polylang translation of 15154 (`pll_get_post_translations(15154)`), if one exists. Then clear Elementor cache + purge (`tools/deploy.py --purge-only`).

### 2.6 Verify
- Product page, mobile: DevTools → main image `naturalWidth` ≥ 600 (should be 900) and visibly sharp; Network tab shows it arriving as `image/webp`.
- Store page and Related Products: card images still `-600x900` (Odella Product Card), no 800×1200 originals downloaded for cards.
- Lightbox still opens the full image.
- Desktop + ultra-wide: gallery not blurry.
Rollback: `snapshot.py restore …phase2-images.json`, `deploy.py --target mu --rollback`. Generated files can stay (harmless).
**Commit:** `perf(phase2): re-enable WP/Woo image sizes, gallery uses woocommerce_single`.

---

## Phase 3 — Move inline CSS to cacheable files

Agreed: as much CSS as possible should be browser-cacheable files instead of being repeated inside every HTML page (today: 270–400 KB of inline CSS per page; Store HTML 797 KB raw / 202 KB compressed). This reverses the "Internal" decision in `DECISIONS.md`; the guard against its old 404 problem is the purge order in Phase 0 (Elementor clear → LiteSpeed → CDN) plus the probe below.

### 3.1 Baseline numbers
Record for the 8 key URLs: raw HTML size and number of `<style>` blocks (`curl -s -A "<mobile UA>" URL | Measure-Object -Character`, or add a `--sizes` flag to `probe_css.py`). Paste into the slice notes.

### 3.2 Lower the mu-plugin inline threshold
`site/mu-plugins/odella-performance.php` section 6c: `$size <= 28672` → `$size <= 2048`. Update the comment above it to "≤ 2 KB". Keep 6a (async Google Fonts) and 6b (async homepage plugin CSS) unchanged. Deploy `--target mu`.

### 3.3 Switch Elementor to external CSS files
1. `python tools\snapshot.py save --label phase3-css --option elementor_css_print_method`
2. `wp option update elementor_css_print_method external`
3. `wp elementor flush-css` (if the command is unavailable, use the `clear_cache()` eval).
4. `python tools\deploy.py --purge-only` (runs LiteSpeed + CDN purge and the smoke test, which regenerates CSS for the key pages).
5. `python tools\probe_css.py` → must report zero 404s.

### 3.4 Verify
- HTML size of each key URL down substantially (target: Store < 350 KB raw, product < 250 KB raw). Report before/after.
- Every page in the matrix renders identically to before (compare screenshots side by side: header, footer, product cards, product page, checkout). Pay attention to fonts, icons and the header menu.
- Navigate Store → product → back → another product with DevTools Network open: second page loads its CSS from disk/memory cache.
- Run `probe_css.py` again after 1 h, and ask the owner to run it after 24 h and 48 h (or run it whenever the owner next opens a session). Any 404 → rollback.
Rollback: `wp option update elementor_css_print_method internal` + `deploy.py --purge-only`; `deploy.py --target mu --rollback` for the threshold.
Update `DECISIONS.md`: new entry superseding "Elementor CSS Print Method set to Internal", with the reason (cacheable CSS across navigations) and the guard (purge order + probe).
**Commit:** `perf(phase3): external CSS files, inline threshold 2 KB`.

---

## Phase 4 — Page transitions and instant product opening

### 4.1 Speculative loading (makes product pages open fast)
WordPress core already outputs speculation rules (`prefetch`, `conservative`). In `site/mu-plugins/odella-performance.php` add section 13:
```php
add_filter( 'wp_speculation_rules_configuration', function ( $config ) {
    if ( is_user_logged_in() ) { return $config; }
    return array( 'mode' => 'prefetch', 'eagerness' => 'moderate' );
} );
add_filter( 'wp_speculation_rules_href_exclude_paths', function ( $paths ) {
    return array_merge( $paths, array( '/ka/elementor-15559/*', '/en/elementor-15559/*', '/ka/my-account-2/*', '/en/my-account/*', '/ka/cart/*', '/en/cart/*' ) );
} );
```
Deploy `--target mu`. Verify: page source contains `<script type="speculationrules">` with `"eagerness":"moderate"` and the excluded paths; core's own exclusion of query-string URLs (`?add-to-cart=` etc.) is still present. In Chrome DevTools → Application → Speculative loads, hovering/touching a product card shows it as prefetched; then opening it is near-instant.

### 4.2 Custom page transition (replaces Elementor's)
Agreed design: tapping a link fades the screen to white with the O'Della logo over **200 ms**; the next page starts covered and fades in over **250 ms** as soon as it paints. **No artificial minimum wait.** Respect `prefers-reduced-motion` (no animation).

Why custom: Elementor Pro's page transitions were removed earlier because they caused 0.47–0.93 CLS and a ~1.5 s LCP delay (`odella-performance.php` dequeues `page-transitions` and hides `<e-page-transition>`). Keep that removal.

Implementation (plugin, local-first):
1. `Odella-Plugins/assets/css/page-transition.css` and `Odella-Plugins/assets/js/page-transition.js`; register + enqueue in `Odella-Plugins/includes/plugin.php` on the frontend only (skip `is_admin()`, Elementor editor/preview `isset($_GET['elementor-preview'])`, and `\Elementor\Plugin::$instance->preview->is_preview_mode()`), script in footer with `defer`.
2. Overlay markup printed on `wp_body_open` (Hello Elementor fires it): `<div id="odella-pt" aria-hidden="true"><img src="<logo URL>" alt="" width="111" height="48"></div>`. Logo URL = the header's `Odella-Full-Logo` image (find it: `curl -s https://odella.ge/ka/ | Select-String -Pattern 'https://odella\.ge/wp-content/uploads/[^"]*Odella-Full-Logo[^"]*' | % { $_.Matches[0].Value }`).
3. **Incoming fade is pure CSS** (works even if JS fails, adds no layout shift because the overlay is `position:fixed`):
   ```css
   #odella-pt{position:fixed;inset:0;z-index:2147483000;background:#fff;display:flex;align-items:center;justify-content:center;pointer-events:none;opacity:0;animation:odellaPtIn .25s ease-out both}
   @keyframes odellaPtIn{from{opacity:1}to{opacity:0}}
   #odella-pt.is-leaving{animation:none;opacity:1;transition:opacity .2s ease-in;pointer-events:auto}
   #odella-pt img{width:111px;height:auto}
   @media (prefers-reduced-motion:reduce){#odella-pt{animation:none;opacity:0}#odella-pt.is-leaving{transition:none;opacity:0}}
   ```
   Inline this CSS in `<head>` (it is tiny) so the overlay is styled before first paint.
4. **Outgoing (JS):** one `click` listener on `document` (bubble phase). Add `is-leaving` to `#odella-pt` and let the navigation proceed normally (never `preventDefault`, never delay `location`). Skip when any is true:
   - `e.defaultPrevented` (AJAX add-to-cart, cart drawer, WOOF etc. already handled it),
   - not a left click, or `ctrl/meta/shift/alt` held,
   - link has `target` other than `_self`, or `download`, or `data-no-transition`,
   - `href` starts with `#`, `mailto:`, `tel:`, `javascript:`, or points to another origin,
   - same URL as current page except for the hash,
   - link is inside `.moderncart-slide-out-modal`, `#moderncart-slide-out`, `.elementor-lightbox`, or has `data-elementor-open-lightbox="yes"`.
   Also: on `pageshow` with `event.persisted` (back/forward cache) remove `is-leaving`; failsafe: remove `is-leaving` after 8 s.
5. Turn off Elementor Pro page transitions in Site Settings (kit `16`): snapshot first (`snapshot.py save --label phase4-kit --post 16`), then in the kit's `_elementor_page_settings` set `settings_page_transitions_entrance_animation` and `settings_page_transitions_exit_animation` to `""` and `settings_page_transitions_preloader_type` to `""`; clear Elementor cache. Verify `<e-page-transition` no longer appears in page HTML. (If it still appears, leave the mu-plugin `display:none` rule in place — it already neutralises it.)
6. Deploy: `python tools\deploy.py --target plugin`.

### 4.3 Verify
- Mobile + desktop, KA (and EN Store): tap a product card → white + logo appears immediately → product page fades in. Back button → previous page is visible, not stuck white.
- Add-to-cart buttons on cards, the cart drawer, filter links, language switcher (switcher should transition — it is a normal navigation), Ctrl+click (opens a new tab, no overlay), product gallery lightbox: all behave as before.
- CLS stays < 0.1 on Home, Store and product (DevTools Performance).
- With "Emulate CSS prefers-reduced-motion: reduce" in DevTools → no fades at all.
Rollback: `deploy.py --target plugin --rollback`; `snapshot.py restore …phase4-kit.json`; `deploy.py --target mu --rollback` for speculation rules.
**Commit:** `perf(phase4): custom page transition, moderate speculative prefetch`.

---

## Phase 5 — Warm, measure, record

1. Ask the owner to run `python tools\warm_cache.py` (≈ 25 min) and then `python tools\psi.py`.
2. Read the newest two reports (baseline vs now) and write `reports/phase-summary-01-performance.md`: table of before/after per URL × mobile/desktop, plus uncached and cached TTFB from `warm_cache.py`'s summary.
3. Targets agreed with the owner:
   - PageSpeed **mobile ≥ 80** on Home, Store, product (KA; EN where published).
   - **Uncached TTFB < 1 s**, cached < 300 ms.
   - Product opened from the Store feels instant when prefetched.
   If a target is missed, list the top 3 remaining causes from the report with the file each lives in — do not start fixing them without the owner.
4. Update `DECISIONS.md` (new entries for Phases 1–4) and `PROJECT_BRIEF.md` (CSS print method is now External; transitions are custom; tools list).
5. **Commit:** `perf(phase5): results and docs`.

---

## Out of scope here (other roadmaps)
- WOOF / filter, Store showing sub-categories, category vs `/store/swoof/` URLs, Load More → roadmap 03.
- Missing prices on product cards → roadmap 02.
- EN pages being draft/404 → roadmap 05.
