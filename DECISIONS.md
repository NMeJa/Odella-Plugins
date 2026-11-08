# Decisions — Odella Website

See [PROJECT_BRIEF.md](file:///c:/Users/Nick/Projects/Hostinger-Connect/PROJECT_BRIEF.md) for project background and template details — this file is for why.

## Decisions

### 2026-11-08 — Page transitions are custom, prefetch is moderate
**Decision:** Logged-out pages prefetch with WordPress speculation rules at eagerness `moderate`. Cart, checkout, and account URLs stay excluded. A click fades the screen to white with the O'Della logo over 200 ms, and the next page fades in over 250 ms, with no minimum wait. Elementor Pro page transitions in kit 16 are off.  
**Why:** Elementor's own transition added layout shift and delayed LCP. A short overlay does not wait for the next page, and a prefetched product can open from cache.  
**Trade-off:** `prefers-reduced-motion` disables the fade. Logged-in visitors keep WordPress's own speculation setting.

### 2026-11-08 — Elementor CSS is external, small-file inline threshold is 2 KB
**Decision:** `elementor_css_print_method` is `external`. The mu-plugin inlines a local stylesheet only when the file is ≤ 2 KB. This supersedes the 2026-11-07 decision to print Elementor CSS internally.  
**Why:** Internal CSS repeated 270–400 KB on every page and could not be reused on the next navigation. External files are cached by the browser. The old 404 risk is guarded by the purge order (Elementor cache, then LiteSpeed, then the Hostinger CDN) and by `tools/probe_css.py`.  
**Trade-off:** A stylesheet that is missing from the CDN 404s until the purge finishes. Generated files stay on disk after a rollback of the option.

### 2026-11-08 — Product images use the six catalog sizes, capped at 2560 px
**Decision:** Perfect Images generates thumbnail, medium_large, large, woocommerce_thumbnail, woocommerce_single, and woocommerce_gallery_thumbnail. New uploads are capped at 2560 px. The product gallery uses `woocommerce_single` and `woocommerce_gallery_thumbnail`. Store cards use the Odella Product Card size (600×900).  
**Why:** Those sizes were disabled, so cards and the gallery fell back to originals.  
**Trade-off:** Regenerating the library adds files on disk. Medium, 1536, 2048, and the variation-swatch sizes stay disabled.

### 2026-11-08 — The homepage footer is reachable, and tall 2K screens do not snap back
**Decision:** Full-page scroll keeps Video, Categories, Products, and Instagram as snap sections. One more scroll from Instagram reaches the footer. On a viewport at least 2000 px wide and taller than 1080 px, snap is turned off once the visitor is past Instagram.  
**Why:** Proximity snap treated the short footer as near Instagram and pulled a tall 2K screen back up. Shorter viewports already stayed put.  
**Trade-off:** That tall 2K case has no snap on the footer. The cart drawer and the filter drawer still scroll on their own.

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
**Trade-off:** None remaining. WOOF itself was removed from the mu-plugin on 2026-11-08.

### 2026-11-09 — The shop filter uses category URLs and a local product index
**Decision:** Category archives are the filter URLs. Search is `?q=` and sort is `?orderby=date|price|price-desc`, with newest as the default. Clicks filter a cached catalog file, `wp-content/uploads/odella-catalog/catalog-<stamp>.json`, instead of rebuilding the Elementor archive. Rebuild it from the admin-bar Reindex button, from Products → Catalog index, or on a timer (24h 0m by default). 0h 0m leaves it manual.  
**Why:** Fetching a full archive page for a few hundred products was the slow part. The index is built once and then filtered in the browser.  
**Trade-off:** The file stays as it was until the next rebuild. Opening a category URL directly still renders that archive on the server.

### 2026-11-09 — Related products stay in the same category
**Decision:** Related products come from the product’s deepest category, then from that category’s parent only. Tags are not used. The list is published, in stock, catalog-visible, and priced. The widget shows 4 on desktop and 2 on mobile. The code is `Odella-Plugins/includes/related-products.php`.  
**Why:** WooCommerce’s default related list mixed categories and tags.  
**Trade-off:** A category with only one or two siblings is topped up from its parent and can still be short. It never pulls from other branches.

### 2026-11-09 — Georgian shop strings live in one map
**Decision:** `nmj_t()` in `Odella-Plugins/includes/i18n.php` is the Georgian and English map for shop UI strings. Widgets use it when a text setting is empty or still the English default. The Load More button (`c490a4e`) and the Related heading (`7bc6c2db`) are swapped by element ID on Georgian pages.  
**Why:** Those strings were hardcoded in English inside Elementor.  
**Trade-off:** A new shop string has to be added to that map.

### 2026-11-08 — WOOF is deactivated; delete it on 2026-11-22
**Decision:** The store archive uses the Odella Product Filter widget. WOOF (`woocommerce-products-filter`) is deactivated and left installed. Delete it on 2026-11-22 if no rollback was needed (`wp plugin delete woocommerce-products-filter`), and delete popup `16255` then.  
**Why:** The new filter uses real category URLs. WOOF stays installed for two weeks so it can be switched back on.  
**Trade-off:** The plugin files remain on disk until 2026-11-22.

### 2026-11-07 — Plugin Suite Consolidation & Elementor Version Pinning
**Decision:** Deactivated 6 unused active plugins (`bulk-edit-posts-on-frontend-premium`, `bulk-edit-custom-tables-premium`, `wp-sheet-editor-automations-premium`, `woo-orders-spreadsheet-table-premium`, `members`, `hostinger`), deleted 4 unused inactive plugins (`yellow-pencil-visual-theme-customizer`, `user-role-editor`, `hostinger-auto-updates-plugin-HJvuAkspeVr3jRxn`, `wp-sheet-editor-polylang`), and updated `seo-by-rank-math` to `1.0.281`. Elementor was held at 4.2.3 / Pro 4.2.2 on this date. That pin is no longer the live pair — see 2026-11-08.  
**Why:** Updating Elementor Core without a matching Elementor Pro build introduces breaking Atomic Widget changes and risks overwriting the local license initialization fix.  
**Trade-off:** Elementor Core and Pro should be updated together.

### 2026-11-07 — LCP Hero Image Preloading, Inlining Small CSS & Fast Server-Level Root Redirect
**Decision:** (1) In `wp-content/plugins/odella-elementor-widgets/.../responsive-video-widget.php`, added `fetchpriority="high" decoding="async"` to the hero fallback poster `<img>` without forcing `width="1920" height="1080"` (so mobile portrait `Main-Mobile.jpg` `390x850` keeps its natural aspect ratio without layout shift). (2) In `odella-performance.php`, added preloading for `Main-PC.jpg` (desktop) and `Main-Mobile.jpg` (mobile) directly in `<head>`, and added a `style_loader_tag` filter to inline small local stylesheets (`<= 8 KB` with no relative URLs), reducing external `<link rel="stylesheet">` requests from 49 down to 15 (including all 12 of Elementor 4.3's new Atomic CSS files). (3) In `.htaccess`, added a server-level `301` redirect for the root `/` to `/ka/` to bypass Polylang's 326ms uncached PHP boot for root visits. (4) Disabled LiteSpeed `optm-css_min`, `optm-js_min`, `optm-js_defer`, and `optm-ggfonts_async`.  
**Why:** Turning on LiteSpeed's file-rewriting minification/deferral generated 83 new `/wp-content/litespeed/` URLs (causing cold misses on Hostinger Edge CDN), converted inline scripts to base64 `data:` URIs, and injected `webfontloader.min.js` (causing late font swaps). Inlining small CSS files via `style_loader_tag` eliminates 34 render-blocking HTTP requests with zero CDN cache race conditions.  
**Trade-off:** Adds ~28 KB of raw CSS (~5 KB gzipped) inline to the HTML document in exchange for removing 34 blocking network round-trips.

### 2026-11-08 — Unpriced products are hidden, and only new ones are drafted
**Decision:** A published product with no GEL price is hidden with WooCommerce catalog visibility (`exclude-from-catalog`, `exclude-from-search`) and `_odella_auto_hidden`. It is shown again when a price is saved, including from WP Sheet Editor. A product published without a price, or one that loses its price, is set back to Draft. A product that already has `_odella_auto_hidden` stays published and hidden.  
**Why:** Fifty published products had no price and could not be bought. Hiding them keeps the store and search clear. Drafting only new ones means filling those fifty in Sheet Editor does not move the rows to Draft.  
**Trade-off:** The product’s own URL still opens, with Rank Math `noindex`. Visibility terms the owner set are left in place.

### 2026-11-08 — EUR and USD follow the daily National Bank of Georgia rate
**Decision:** `odella-pricing.php` writes the daily National Bank of Georgia EUR and USD rates into Price Based on Country (free 4.3.6). The zone `exchange_rate` is foreign currency per 1 GEL. A rate outside 0.15–0.80, or more than 10% off the stored rate, is rejected and the old rate stays. The first run skips the 10% check because the stored rate was exactly 1.  
**Why:** The free plugin has no automatic rates, so a 430 ₾ coat was showing as €430 / $430. Buying the Pro plugin was the other way to get rates.  
**Trade-off:** Rates update once a day on WP-Cron (03:30 Asia/Tbilisi), not live.

### 2026-11-08 — Foreign prices round up to 9 at the WooCommerce getter
**Decision:** When the visitor’s zone is not GEL and the product is priced by the exchange rate, `odella_round_up_9` runs at `PHP_INT_MAX` on the WooCommerce price getters. A manual zone price is left as entered. GEL is left as entered.  
**Why:** The free plugin’s rounding is a protected method with no filter, and it only rounds decimals. €148.30 should display as €149, and an entered €200 should stay €200.  
**Trade-off:** The stored zone meta can stay the unrounded conversion. The price ending in 9 is what the storefront, cart, and checkout show.

## Open Questions

### Parked Domain (`odellagiftshop.com` / `odellagift.com`) & Polylang English Readiness for GeoIP Routing
**Status:** Open  
**Context:** The goal is for Georgian IPs (`GE`) to use `odella.ge` (starting in Georgian `/ka/`, showing `GEL` prices only) and non-Georgian IPs (`!= GE`) to use the `.com` parked domain (starting in English `/en/`, showing `EUR`/`USD` Global prices while still allowing language switching between `/en/` and `/ka/`).  
**Why it's open:**
1. `odellagiftshop.com` currently returns `NXDOMAIN` in DNS, and `odellagift.com` (previously parked on Hostinger) is currently expired and serves Hostinger's domain expiration page.
2. In Polylang (`language` term `405`), English (`en_US`) is marked `active: false` (`b:0`), and the English front page (`ID 14592`, *Home - English*) plus Cart/Checkout/My Account translations (`14598`, `14600`, `14602`) are currently in `draft` status, causing `/en/` to return `404`.

**Update 2026-11-08:** The eurozone and the rest-of-world zone now convert from the daily National Bank of Georgia rate, so a non-Georgian visitor already sees EUR or USD instead of a 1:1 copy of the GEL price. Georgian visitors still see GEL. The domain and English-front questions above are still open.

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

### 2026-11-08 — The NBG currency filter returns an empty list
**Symptom:** `https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/en/json/?currencies=EUR,USD` returns `[]` with HTTP 200.  
**Cause:** That filter does not return the two codes. The same URL with no `currencies` parameter returns the full list, including EUR and USD.  
**Fix:** `odella_fetch_nbg_rates()` tries the filtered URL first. If EUR and USD are missing, it uses the unfiltered response and keeps only those two codes.



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
