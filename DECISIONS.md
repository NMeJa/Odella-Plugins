# Decisions — Odella Website

See [PROJECT_BRIEF.md](file:///c:/Users/Nick/Projects/Hostinger-Connect/PROJECT_BRIEF.md) for project background and template details — this file is for why.

## Decisions

### 2026-11-08 — Elementor CSS is external, small-file inline threshold is 2 KB
**Decision:** `elementor_css_print_method` is `external`. The mu-plugin inlines a local stylesheet only when the file is ≤ 2 KB. This supersedes the 2026-11-07 decision to print Elementor CSS internally.  
**Why:** Internal CSS repeated 270–400 KB on every page and could not be reused on the next navigation. External files are cached by the browser. The old 404 risk is guarded by the purge order (Elementor cache, then LiteSpeed, then the Hostinger CDN) and by `tools/probe_css.py`.  
**Trade-off:** A stylesheet that is missing from the CDN 404s until the purge finishes. Generated files stay on disk after a rollback of the option.

### 2026-11-08 — Live Elementor is 4.3.6 / Pro 4.3.3
**Decision:** The live site runs Elementor 4.3.6 and Elementor Pro 4.3.3. The 2026-11-07 note that Elementor was pinned at 4.2.3 / Pro 4.2.2 is historical.  
**Why:** The versions on the server are the pair that is actually installed.  
**Trade-off:** None beyond recording the live pair so later work does not treat 4.2.3 as current.

### 2026-11-08 — Local-first covers the mu-plugin and child theme
**Decision:** `site/mu-plugins/odella-performance.php` and `site/theme-child/` are owned code, same as `Odella-Plugins`. Deploy them with `tools/deploy.py --target mu` and `--target theme`.  
**Why:** Those files were being copied by hand. One deploy script can check, swap, and roll them back.  
**Trade-off:** A theme or mu-plugin change is a deploy, not an SSH edit.

### 2026-11-08 — Remote deploy backups live outside the web root
**Decision:** Before each swap, `tools/deploy.py` copies the live target to `~/odella-deploy-backups/<target>/<UTC timestamp>/` and keeps the newest 5. `--rollback` restores the newest copy.  
**Why:** The backup must not sit inside `public_html`, or WordPress would see a second plugin or theme.  
**Trade-off:** Rollback restores code only. Database edits roll back from `snapshots/`, and a full-site rollback is still the hPanel backup.

### 2026-11-07 — Elementor CSS Print Method set to Internal
**Decision:** Elementor CSS print method configured to Internal (`elementor_css_print_method: internal`).  
**Why:** Prevents 404 race conditions with Hostinger's Edge CDN cache.  
**Trade-off:** Inlines CSS in the page output instead of serving static CSS files, slightly increasing document HTML size.

### 2026-11-07 — Child theme style.css enqueued with priority 999 and dynamic cache-busting
**Decision:** All custom overrides, responsive layout rules, and SVG constrainers are kept in `hello-theme-child-master/style.css` enqueued at priority 999 with `filemtime` dynamic cache-busting.  
**Why:** Ensures custom stylesheet takes precedence over Elementor and WooCommerce base rules while immediately busting CDN/browser caches on update.  
**Trade-off:** Requires keeping custom CSS organized within the child theme rather than scattering inline Elementor page CSS.

### 2026-11-07 — Frontend Admin-Plugin Bypass & Conditional WOOF Asset Loading via MU-Plugin
**Decision:** Created `wp-content/mu-plugins/odella-performance.php` to (1) skip loading strictly administrative/bulk-editor plugins (`wp-sheet-editor-*`, `woo-products-bulk-editor-premium`, `acf-quickedit-fields`, `enable-media-replace`) on public frontend visitor requests while keeping them 100% active in `/wp-admin/`, and (2) unhook and dequeue `woocommerce-products-filter` (WOOF's 58 CSS/JS files) on non-catalog pages (Home, Single Product, About, Contact, Cart, Checkout).  
**Why:** Eliminates ~600 ms of PHP plugin initialization overhead and removes 59 unnecessary CSS/JS HTTP requests from the homepage and single product pages without affecting `/wp-admin/` workflows or store catalog filtering.  
**Trade-off:** If a WOOF filter widget is ever placed on a new custom page outside the standard WooCommerce shop/archive/store routes, its page ID/slug should be added to `odella_is_catalog_filter_page()` in `odella-performance.php`.

### 2026-11-07 — Plugin Suite Consolidation & Elementor Version Pinning
**Decision:** Deactivated 6 unused active plugins (`bulk-edit-posts-on-frontend-premium`, `bulk-edit-custom-tables-premium`, `wp-sheet-editor-automations-premium`, `woo-orders-spreadsheet-table-premium`, `members`, `hostinger`), deleted 4 unused inactive plugins (`yellow-pencil-visual-theme-customizer`, `user-role-editor`, `hostinger-auto-updates-plugin-HJvuAkspeVr3jRxn`, `wp-sheet-editor-polylang`), and updated `seo-by-rank-math` to `1.0.281`. Elementor was held at 4.2.3 / Pro 4.2.2 on this date. That pin is no longer the live pair — see 2026-11-08.  
**Why:** Updating Elementor Core without a matching Elementor Pro build introduces breaking Atomic Widget changes and risks overwriting the local license initialization fix.  
**Trade-off:** Elementor Core and Pro should be updated together.

### 2026-11-07 — LCP Hero Image Preloading, Inlining Small CSS & Fast Server-Level Root Redirect
**Decision:** (1) In `wp-content/plugins/odella-elementor-widgets/.../responsive-video-widget.php`, added `fetchpriority="high" decoding="async"` to the hero fallback poster `<img>` without forcing `width="1920" height="1080"` (so mobile portrait `Main-Mobile.jpg` `390x850` keeps its natural aspect ratio without layout shift). (2) In `odella-performance.php`, added preloading for `Main-PC.jpg` (desktop) and `Main-Mobile.jpg` (mobile) directly in `<head>`, and added a `style_loader_tag` filter to inline small local stylesheets (`<= 8 KB` with no relative URLs), reducing external `<link rel="stylesheet">` requests from 49 down to 15 (including all 12 of Elementor 4.3's new Atomic CSS files). (3) In `.htaccess`, added a server-level `301` redirect for the root `/` to `/ka/` to bypass Polylang's 326ms uncached PHP boot for root visits. (4) Disabled LiteSpeed `optm-css_min`, `optm-js_min`, `optm-js_defer`, and `optm-ggfonts_async`.  
**Why:** Turning on LiteSpeed's file-rewriting minification/deferral generated 83 new `/wp-content/litespeed/` URLs (causing cold misses on Hostinger Edge CDN), converted inline scripts to base64 `data:` URIs, and injected `webfontloader.min.js` (causing late font swaps). Inlining small CSS files via `style_loader_tag` eliminates 34 render-blocking HTTP requests with zero CDN cache race conditions.  
**Trade-off:** Adds ~28 KB of raw CSS (~5 KB gzipped) inline to the HTML document in exchange for removing 34 blocking network round-trips.

## Open Questions

### Parked Domain (`odellagiftshop.com` / `odellagift.com`) & Polylang English Readiness for GeoIP Routing
**Status:** Open  
**Context:** The goal is for Georgian IPs (`GE`) to use `odella.ge` (starting in Georgian `/ka/`, showing `GEL` prices only) and non-Georgian IPs (`!= GE`) to use the `.com` parked domain (starting in English `/en/`, showing `EUR`/`USD` Global prices while still allowing language switching between `/en/` and `/ka/`).  
**Why it's open:**
1. `odellagiftshop.com` currently returns `NXDOMAIN` in DNS, and `odellagift.com` (previously parked on Hostinger) is currently expired and serves Hostinger's domain expiration page.
2. In Polylang (`language` term `405`), English (`en_US`) is marked `active: false` (`b:0`), and the English front page (`ID 14592`, *Home - English*) plus Cart/Checkout/My Account translations (`14598`, `14600`, `14602`) are currently in `draft` status, causing `/en/` to return `404`.

## Gotchas

### 2026-11-07 — Hostinger Edge CDN cache 404 race condition with external Elementor CSS
**Symptom:** Updated or dynamic pages intermittently throw 404s on external Elementor CSS files when served behind Hostinger Edge CDN.  
**Cause:** Hostinger Edge CDN caches the HTML response referencing newly generated CSS filenames before the filesystem or CDN edge mirrors have the new file.  
**Fix:** Keep `elementor_css_print_method: internal` and flush WP object cache / Elementor file cache (`\Elementor\Plugin::$instance->files_manager->clear_cache()`).

### 2026-11-07 — Infinite Cache-Wipe Loop from `elementor_pro_initialize_local_environment`
**Symptom:** Every uncached page load took 11–13 seconds, executed ~1,500 MySQL queries, and emitted `X-LiteSpeed-Purge: public,e22_`, wiping the entire site's LiteSpeed Cache on every dynamic hit.  
**Cause:** `elementor_pro_initialize_local_environment()` (hooked to `init` in `elementor-pro/elementor-pro.php`) computed `$timeout = strtotime('+12 hours', current_time('timestamp'))` (which changes every second) and unconditionally called `update_option('_elementor_pro_license_v2_data', ...)`. Elementor Core's `Atomic_Widget_Styles` listens to `update_option__elementor_pro_license_v2_data` and calls `Plugin::$instance->files_manager->clear_cache()`, which deleted all Elementor CSS meta on every request and triggered `LiteSpeed\Thirdparty\Elementor::regenerate_litespeed_cache` -> `LiteSpeed\Purge::purge_all()`. Additionally, `Hostinger\Hooks->handle_updated_option` fired 332 `ActionScheduler` DB queries per request in response to the option updates.  
**Fix:** Patched `elementor_pro_initialize_local_environment()` (plus added a safeguard in `wp-content/mu-plugins/odella-performance.php`) to return early when `_elementor_pro_license_v2_data` is already valid with >24h remaining, updating only once every 30 days.

### 2026-11-07 — Hostinger CageFS lacks local Redis/Memcached daemon
**Symptom:** Enabling Object Cache in LiteSpeed Cache shows `Memcached Extension: Enabled`, `Redis Extension: Enabled`, but `Connection Test: Failed`.  
**Cause:** Hostinger's shared/cloud CageFS environment compiles the PHP extensions into the binary but does not run a local `redis-server` (`127.0.0.1:6379`) or `memcached` (`127.0.0.1:11211`) daemon or UNIX socket.  
**Fix:** Keep `litespeed.conf.object` set to `0` (`OFF`) so LiteSpeed does not attempt failing TCP connections on every page request.



---

## [2026-11-07] Mobile Core Web Vitals Optimization (LCP Render Delay, Duplicate Image Downloads & Full-Page-Scroll CLS)

### Context
In PageSpeed Insights (`4k64jopwnu`), Desktop scored **92** (`0.6s` FCP, `1.4s` LCP, `0ms` TBT, `0.019` CLS), while Mobile scored **75** (`2.6s` FCP, `4.4s` LCP, `170ms` TBT, `0.069` CLS). Deep inspection of the Mobile Lighthouse JSON revealed:
1. **LCP Element Render Delay (`1,621 ms`)**: Although `Main-Mobile.jpg` (`70 KB`) finished downloading at `809 ms`, 14 external stylesheets (including `751 ms` for `fonts.googleapis.com/css`) and 3 synchronous `<head>` scripts (`jquery-migrate.min.js`, `jquery.blockUI.min.js`, `page-transitions.min.js`) blocked first paint and LCP rendering on simulated Slow 4G.
2. **Competing High-Priority Images & Double-Downloaded Carousel Images (`1,159 KiB` wasted)**:
   - WordPress's `wp_omit_loading_attr_threshold` (`3`) removed `loading="lazy"` from the first 3 Category carousel images (`Kimono`, `Coat`, `Silk-scarf`) and added `fetchpriority="high"` to `Kimono`, competing for mobile bandwidth with `Main-Mobile.jpg` at `513 ms`.
   - WordPress 6.7+'s `sizes="auto, (max-width: 600px) 100vw, 600px"` caused Mobile Chrome (`412px * 1.75 DPR = 721px > 600w`) to download both the `600x900` (`600w`) and the full-size `800x1200` (`1000w`) original JPEGs for all 25 carousel items (`50` requests).
3. **Mobile Hero Layout Shift (`0.066` of `0.069` total CLS)**: `full-page-scroll.js` executed in the footer on `DOMContentLoaded` and dynamically injected `minHeight = 100vh; paddingTop = 60px` on `#video`, shifting `.nmj-rv-inner` down by `60-122px` after first paint.

### Decision
Updated [`odella-performance.php`](file:///c:/Users/Nick/Projects/Hostinger-Connect/odella-performance.php) (`v1.1.0`) to:
1. Pre-apply the `#video, #categories, #products, #instagram, #footer-id` full-page-scroll section geometry (`min-height: 100vh; padding-top: 60px; box-sizing: border-box; scroll-snap-align: start`) in `<head>` on the homepage so `.nmj-rv-inner` renders at its final coordinates with zero layout shift.
2. Inline local stylesheets `<= 28 KB` (with relative `url()` resolution), load Google Fonts and below-the-fold homepage plugin CSS (`woocommerce-general`, `moderncart-cart-css`, `woo-variation-swatches`, `fluentform-elementor-widget`) asynchronously (`media="print" onload="this.media='all'"`), and add `defer` to `jquery-migrate`, `wc-jquery-blockui`, and `page-transitions`.
3. Set `wp_omit_loading_attr_threshold` to `0` on the homepage, disable `wp_img_tag_add_auto_sizes`, set `sizes="(max-width: 767px) 340px, 500px"` on `600x900` card images (`340 * 1.75 = 595px <= 600w`), and inject explicit `width="1000" height="1000"` on the 18 `/elementor/thumbs/*_insta-*.jpg` images.


---

## [2026-11-07] Full Page Scroll Widget Fix (`odella-elementor-widgets` & `hello-theme-child-master/style.css`)

### Context
The user reported that the Full Page Scroll widget on the homepage (`https://odella.ge/ka/`) still was not snapping or scrolling properly. Deep inspection of the live DOM, CSS cascade, and asset headers uncovered four compounding root causes:
1. **`html, body { overflow-x: hidden !important; }` in `hello-theme-child-master/style.css` Broke Root Scroll-Snap**:
   - Per the W3C CSS Overflow Level 3 and CSS Scroll Snap Level 1 specifications, setting `overflow-x: hidden` on both `html` and `body` prevents `body`'s overflow from propagating to the viewport and forces `body`'s computed `overflow-y` to become `auto`.
   - That turns `<body>` into an intermediate scroll container between `<html>` and the target sections (`#video`, `#categories`, `#products`, `#instagram`, `#footer-id`). Because a scroll container only captures `scroll-snap-align` descendants whose *nearest ancestor scroll container* is itself, `html { scroll-snap-type: y mandatory }` captured **zero** snap targets.
2. **Browser & Edge CDN Caching (`?ver=1.0.55` with `max-age=31557600`)**:
   - `includes/plugin.php` in `odella-elementor-widgets` only used `filemtime($path)` when `WP_DEBUG` was enabled, falling back to the static `NMJ_VERSION` (`1.0.55`). Because Hostinger's CDN (`hcdn`) serves `.js` files with a 1-year `Cache-Control: public, max-age=31557600`, browsers continued serving the stale cached `full-page-scroll.js?ver=1.0.55`.
3. **First Section (`#video`) Sticky Header Offset & Desktop `16:9` Aspect-Ratio Overflow**:
   - Because `<header class="elementor-location-header">` is `position: sticky; top: 0` (`~56px` tall in normal flow), `#video` starts at `offsetTop = 56px`. Applying `scroll-margin-top: 0px` and `padding-top: 60px` to `#video` placed its snap point at `scrollY = 56px` (causing the first downward scroll from `scrollY = 0` to stop at `56px` on `#video` instead of advancing to `#categories`) and added a 60px layout shift on load. Furthermore, `.nmj-rv-inner` (`aspect-ratio: 16/9`) expanded to `1080px` on a `1920px`-wide browser window where `100vh` is `~950px`, making `#video` taller than the viewport.
4. **Elementor 4 Global Color Variable Overwriting `.nmj-fps-dot.active` `transform`**:
   - In `full-page-scroll-widget.php`, `dot_color_active` had `'{{WRAPPER}} .nmj-fps-dot.active' => 'color: {{VALUE}}; fill: {{VALUE}}; transform: scale(1.3);'`, which Elementor 4's global color processor compiled into invalid `transform: var(--e-global-color-v4-odellapurple);`.

### Decision
1. Changed `overflow-x: hidden !important;` to `overflow-x: clip !important;` on `html, body` in `hello-theme-child-master/style.css` so horizontal overflow is clipped at the viewport edge without turning `<body>` into an intermediate scroll container.
2. Updated `includes/plugin.php` in `odella-elementor-widgets` (`v1.0.56`) so `get_asset_version()` always uses `filemtime($path)` for automatic cache-busting on Hostinger Edge CDN and visitor browsers.
3. Updated [`full-page-scroll.js`](file:///home/u981153228/domains/odella.ge/public_html/wp-content/plugins/odella-elementor-widgets/assets/js/full-page-scroll.js) and `<head>` `#odella-fps-critical-css` in [`odella-performance.php`](file:///c:/Users/Nick/Projects/Hostinger-Connect/odella-performance.php) to:
   - Configure Section 0 (`#video`) with `min-height: calc(100vh - 60px)`, `scroll-margin-top: 60px`, `padding-top: 0px`, and `.nmj-rv-inner { max-height: calc(100vh - 60px) }` so its snap point is `scrollY = 0` with zero layout shift.
   - Configure Sections 1–4 (`#categories`, `#products`, `#instagram`, `#footer-id`) with `min-height: 100vh`, `padding-top: 60px`, `scroll-margin-top: 0px`, `box-sizing: border-box`, `scroll-snap-align: start`, and `scroll-snap-stop: always`.
   - Combine native CSS `scroll-snap-type: y mandatory` with deterministic, debounced mouse-wheel, trackpad, keyboard, and dot-click section-to-section smooth scrolling (temporarily suspending CSS scroll-snap during programmatic `scrollTo` animations so `scroll-snap-stop: always` never blocks multi-section dot jumps).
4. Fixed `dot_color_active` selector in `full-page-scroll-widget.php` and enforced `.nmj-fps-dot.active { transform: scale(1.3) !important; }` in `full-page-scroll.css`.
