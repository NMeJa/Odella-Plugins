# Roadmap 06 — Unified `Odella` Admin Menu, Encrypted Settings, Reindex + Cache Warmer, Modern Cart Quantity Speed, "Is Slow?" Diagnostic & Registration Speed

**For:** Cursor running **Grok 4.7** in Lead-Reviewer + Subagent mode.  
**Written:** 2026-11-15 from a grilling session with the owner. Every architectural and UX decision below is agreed — implement it directly, do not redesign.  
**Prerequisites:** Roadmaps 01–05 are live (`Elementor 4.4.0`, `Elementor Pro 4.4.0`, `odella-elementor-widgets 1.0.83`). Same local-first rules: edit only in `Odella-Plugins/`, `site/mu-plugins/`, and `site/theme-child/`, and deploy exclusively via `python tools\deploy.py --target plugin|mu|theme`.

---

## Execution Model: High-Effort Reviewer + Medium/Low-Effort Worker Subagents

You (the main chat session running **Grok High-Effort**) are the **Lead Architect & Code Reviewer**. Execute all six phases (`6A` → `6B` → `6C` → `6D` → `6E` → `6F`) sequentially in a single continuous run using subagents:

| Phase | Slice | Subagent Effort | Why |
|---|---|---|---|
| **6A** | Unified `Odella` menu, `Pricing & Rates` page, encrypted `Settings` page + key test AJAX + Custom prompt toggle | **Medium** | Multi-file admin UI, `libsodium` encryption, AJAX key testers |
| **6B** | Upgrade `Reindex` to also warm cold LiteSpeed cache in batches + 24h cron warm budget | **Medium** | Sitemap parser, batch state machine, admin-bar live progress |
| **6C** | Modern Cart quantity speed: baseline benchmark, MU-plugin slim-down for cart AJAX, optimistic UI + 300 ms debounce | **Medium** | Performance profiling, MU-plugin early hook filtering, JS capture-phase debounce |
| **6D** | "Is Slow?" button & `Odella → Speed Check`: 9 deterministic checks, 2-wave parallel PageSpeed, auto-warmer, guarded purge, multi-language Gemini tabs | **Medium** | `curl_multi` PSI runner, deterministic probes, Gemini structured JSON tabs |
| **6E** | Registration speed profiling, early HTTP redirect flush (`litespeed_finish_request`), deferred transactional emails | **Medium** | Hook timing profiler, response flushing before `wp_mail()` |
| **6F** | Update `DECISIONS.md`, `PROJECT_BRIEF.md`, and generate `roadmaps/manual-test-04-speed-and-cart.html` | **Low** | Documentation, version sync, and owner manual test guide |

### Reviewer Loop for Every Phase
1. **Dispatch:** Spawn a worker subagent with the recommended effort level (`medium` or `low`), giving it the exact phase specification below and the repo rules (`AGENTS.md`, `PROJECT_BRIEF.md`, `DECISIONS.md`).
2. **Audit:** When the subagent reports back, do **not** blindly trust its summary:
   - Inspect `git diff` locally.
   - Verify `python tools\deploy.py --check --all` exits `0` (zero drift between local and remote).
   - Run or verify the phase's automated verification commands (`wp eval`, `curl` timings, `tools/probe_css.py`).
   - Check PHP 7.4+/8.x compatibility, nonce/capability guards (`manage_woocommerce` vs `manage_options`), and confirm no API keys or secrets appear in code or logs.
3. **Gate:** If anything is broken, incomplete, or fails verification, send targeted fix instructions to a subagent and re-audit. Only when the phase passes 100% of its Verify checklist, commit that phase with its exact commit message and proceed to the next phase.
4. **Final Report:** After Phase `6F` is committed, present a unified executive report to the owner covering:
   - What was built and committed in each phase (`6A`–`6F`).
   - Before/after benchmarks for Cart Quantity AJAX (`6C`) and Registration POST (`6E`).
   - Step-by-step instructions on **what the owner needs to configure and test** (pasting the client's PageSpeed & Gemini keys in `Odella → Settings`, testing each flow, and opening `roadmaps/manual-test-04-speed-and-cart.html`).

---

## Non-Negotiable Rules & Security Guardrails
1. **Local-first only:** Never edit plugin, mu-plugin, or theme files directly on the server over SSH. Edit locally and run `python tools\deploy.py --target plugin|mu|theme`.
2. **Never hardcode API keys:** API keys live **only** in the WordPress database option `odella_speed_settings` (`autoload = false`), encrypted with `libsodium` (`sodium_crypto_secretbox`) using a key derived from `wp_salt('auth')`. Never log, echo, localize to JS, or commit any API key.
3. **Capabilities:**
   - Viewing/running `Speed Check`, `Catalog & Cache` (Reindex + Warm), and `Pricing & Rates`: `manage_woocommerce`.
   - Viewing/saving `Odella → Settings` (API keys, Gemini model, languages, custom prompt): `manage_options` (administrators only).
4. **Server constraints:**
   - `proc_open` is disabled on Hostinger — never use `wp db query/cli/export/import`; use `wp eval` or `wp eval-file -` with `$wpdb`.
   - Object cache (`litespeed.conf.object`) must remain `0` (disabled).
   - Payments and shipping remain disabled on purpose.

---

## Phase 6A — Unified `Odella` Admin Menu, `Pricing & Rates` Submenu & Encrypted `Settings`

### Goal
Consolidate every Odella admin tool under a single top-level **Odella** menu in `wp-admin` and build the modular encrypted **Settings** screen and **Pricing & Rates** screen.

### Files
- **[NEW]** `Odella-Plugins/includes/settings.php`
- **[NEW]** `Odella-Plugins/includes/admin-menu.php`
- **[MODIFY]** `Odella-Plugins/includes/product-index.php`
- **[MODIFY]** `Odella-Plugins/includes/plugin.php`

### Implementation Steps

#### 6A.1 Encrypted Settings (`Odella-Plugins/includes/settings.php`)
1. Option name: `odella_speed_settings` (always saved with `update_option('odella_speed_settings', $value, false)` so `autoload` is `no`/`off`).
2. Encryption helpers using `libsodium` (built into PHP on Hostinger; add an OpenSSL `aes-256-gcm` fallback if `function_exists('sodium_crypto_secretbox')` is ever false):
   ```php
   function odella_secret_key(): string {
       return hash('sha256', wp_salt('auth') . '|odella-speed-v1', true);
   }

   function odella_encrypt_secret(string $plain): string {
       $plain = trim($plain);
       if ($plain === '') {
           return '';
       }
       $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
       $cipher = sodium_crypto_secretbox($plain, $nonce, odella_secret_key());
       return base64_encode($nonce . $cipher);
   }

   function odella_decrypt_secret(string $stored): string {
       if ($stored === '') {
           return '';
       }
       $raw = base64_decode($stored, true);
       if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
           return '';
       }
       $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
       $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
       $plain  = sodium_crypto_secretbox_open($cipher, $nonce, odella_secret_key());
       return is_string($plain) ? $plain : '';
   }

   function odella_mask_secret(string $plain): string {
       if ($plain === '') {
           return '';
       }
       $tail = strlen($plain) > 4 ? substr($plain, -4) : $plain;
       return '••••••••' . $tail;
   }
   ```
3. Supported report languages (max **3** selected simultaneously, default `['en']`):
   ```php
   function odella_supported_report_languages(): array {
       return [
           'en' => 'English',
           'ka' => 'Georgian (ქართული)',
           'ru' => 'Russian (Русский)',
           'uk' => 'Ukrainian (Українська)',
           'tr' => 'Turkish (Türkçe)',
           'de' => 'German (Deutsch)',
           'fr' => 'French (Français)',
           'es' => 'Spanish (Español)',
       ];
   }
   ```
4. Built-in Read-Only Default Prompt & **"Custom"** Toggle:
   - `odella_default_ai_prompt()` returns:
     ```
     You are the performance diagnostics assistant for Odella (https://odella.ge), a WooCommerce + Elementor 4.4 e-commerce store running on Hostinger LiteSpeed Web Server with LiteSpeed Cache, Hostinger Edge CDN, and a custom static JSON product catalog index.

     Analyze the deterministic server checks and Google PageSpeed Insights metrics provided below.
     1. Identify the primary root cause(s) of any slowness in plain, non-technical language that a store owner can immediately understand, followed by concise technical specifics.
     2. Do not blame cold cache if the deterministic check already warmed the cache.
     3. Never suggest installing Redis/Memcached (unavailable on this CageFS plan) or enabling LiteSpeed CSS/JS minification (intentionally disabled to prevent CDN 404 races).
     4. Give 2 to 4 concrete, prioritized next actions.
     ```
   - Settings schema stored in `odella_speed_settings`:
     - `psi_key_enc` (`string`, default `''`)
     - `gemini_key_enc` (`string`, default `''`)
     - `gemini_model` (`string`, default `'gemini-flash-latest'`)
     - `languages` (`array<string>`, 1 to 3 valid keys from `odella_supported_report_languages()`, default `['en']`)
     - `use_custom_prompt` (`bool`, default `false`)
     - `custom_prompt` (`string`, default `''`)
   - `odella_effective_ai_prompt()` returns `$settings['use_custom_prompt'] && trim($settings['custom_prompt']) !== '' ? $settings['custom_prompt'] : odella_default_ai_prompt()`.
   - When calling Gemini (in Phase 6D), the backend **always** appends the structural JSON + language contract underneath `odella_effective_ai_prompt()` so a custom prompt can never break multi-language tab parsing.

5. **`Odella → Settings` UI (`odella_settings_page()`)**:
   - Guarded by `current_user_can('manage_options')`.
   - **PageSpeed Insights API Key**:
     - Shows badge `Configured (••••••••XXXX)` or `Not set`.
     - Password/text input with placeholder `Leave empty to keep current key`.
     - Checkbox/button `Remove stored PageSpeed key`.
     - Button `Test PageSpeed Key` (`id="odella-test-psi-key"`) + inline status span.
   - **Google Gemini API Key**:
     - Shows badge `Configured (••••••••XXXX)` or `Not set`.
     - Password/text input with placeholder `Leave empty to keep current key`.
     - Checkbox/button `Remove stored Gemini key`.
     - Button `Test Gemini Key` (`id="odella-test-gemini-key"`) + inline status span.
   - **Gemini Model**:
     - Text input `gemini_model`, default `gemini-flash-latest`.
   - **AI Report Languages (choose 1 to 3)**:
     - Checkboxes for the 8 languages in `odella_supported_report_languages()`.
     - Client-side JS disables unchecked boxes when 3 are already checked, and prevents unchecking the last remaining box. Server-side save validates `1 <= count <= 3` (defaults to `['en']` if empty, slices to 3 if more).
   - **AI System Prompt (Read-Only Default + "Custom" Toggle)**:
     - Toggle switch / checkbox labeled **`Custom`** (`name="use_custom_prompt"`).
     - When **`Custom` is OFF**: `<textarea rows="9" class="large-text code" readonly>` displays `odella_default_ai_prompt()`.
     - When **`Custom` is ON**: JS removes `readonly`, switches the textarea value to the saved `custom_prompt` (pre-filling with `odella_default_ai_prompt()` if `custom_prompt` was empty), and reveals a **"Reset to default prompt"** button that copies `odella_default_ai_prompt()` into the textarea.
     - Note below textarea: *"The multi-language JSON output format is appended automatically so language tabs always work."*

6. **Key Tester AJAX (`wp_ajax_odella_test_api_key`)**:
   - Checks `check_ajax_referer('odella_settings_ajax', 'nonce')` and `current_user_can('manage_options')`.
   - Accepts optional unsaved `candidate_key` from the input field (so the admin can test before or after saving) or uses the stored decrypted key if the input is blank.
   - For `which=psi`: calls `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=https%3A%2F%2Fodella.ge%2Fka%2F&strategy=mobile&category=performance&key=` + rawurlencode($key) with `timeout => 60`. Returns `wp_send_json_success(['message' => 'PageSpeed key valid (HTTP 200, score ' . $score . ')'])` or `wp_send_json_error(['message' => $api_error_message])`.
   - For `which=gemini`: POSTs to `https://generativelanguage.googleapis.com/v1beta/models/` . rawurlencode($model) . `:generateContent` with header `x-goog-api-key: $key`, `Content-Type: application/json`, body `{"contents":[{"parts":[{"text":"Reply with the single word OK."}]}]}`, `timeout => 20`. Returns `wp_send_json_success(['message' => 'Gemini key valid (' . $model . ' replied: ' . $reply . ')'])` or the exact error message from Google.

#### 6A.2 Unified `Odella` Menu & `Pricing & Rates` Page (`Odella-Plugins/includes/admin-menu.php`)
1. Register top-level menu at priority `9` on `admin_menu`:
   - `add_menu_page('Odella', 'Odella', 'manage_woocommerce', 'odella-speed-check', 'odella_speed_check_page_dispatch', 'dashicons-performance', 58);`
   - Submenu 1: `Speed Check` (`odella-speed-check`, `manage_woocommerce`, `odella_speed_check_page_dispatch`). (In Phase 6A, `odella_speed_check_page_dispatch` calls `odella_speed_check_page()` if defined, or renders a clean placeholder card until Phase 6D lands.)
   - Submenu 2: `Catalog & Cache` (`odella-catalog-index`, `manage_woocommerce`, `nmj_product_index_page`).
   - Submenu 3: `Pricing & Rates` (`odella-pricing`, `manage_woocommerce`, `odella_pricing_admin_page`).
   - Submenu 4: `Settings` (`odella-settings`, `manage_options`, `odella_settings_page`).
2. Remove the old `add_submenu_page('edit.php?post_type=product', ...)` from `Odella-Plugins/includes/product-index.php`. Add an `admin_init` redirect so any request to `/wp-admin/edit.php?post_type=product&page=nmj-catalog-index` redirects (`302`) to `admin_url('admin.php?page=odella-catalog-index')`. Update the `Reindex` admin-bar node `href` to `admin_url('admin.php?page=odella-catalog-index')`.
3. Add the **`Is Slow?`** admin-bar button in `admin_bar_menu` (priority `81`, right next to `Reindex` in `top-secondary`):
   - `id => 'odella-is-slow'`, `parent => 'top-secondary'`, `title => 'Is Slow?'`, `href => admin_url('admin.php?page=odella-speed-check&autorun=1')`.
4. **`Odella → Pricing & Rates` Page (`odella_pricing_admin_page()`)**:
   - Displays a summary card of the NBG exchange rate sync from `site/mu-plugins/odella-pricing.php`:
     - Last sync date & stored rates from `get_option('odella_rates_last')` (`date`, `EUR`, `USD`).
     - Live zone rates currently active in `get_option('wc_price_based_country_regions')` (`eurozone`, `global`).
     - Next scheduled `odella_daily_rates` run (`wp_next_scheduled('odella_daily_rates')`).
     - Form button **"Sync NBG Rates Now"** (nonce-verified POST; calls `odella_update_zone_rates()` if `function_exists('odella_update_zone_rates')` and shows updated rates).
   - Displays **Auto-Hidden Unpriced Products**:
     - Queries `post_type=product`, `post_status=any`, `meta_key=_odella_auto_hidden`, `meta_value=1`, `posts_per_page=200`, `lang=''`.
     - Shows total count and a table (`ID`, `Title` linked to `get_edit_post_link()`, `SKU`, `Language`, `Status`) so store managers can see which products are currently hidden for missing a GEL price.

### Verify (Phase 6A)
1. Deploy `--target plugin`.
2. Run `wp eval` over SSH to test encryption round-trip:
   - Encrypt a dummy string, verify stored ciphertext does not contain the plaintext, decrypt and assert equality.
   - Save a test setting into `odella_speed_settings`, verify `autoload` column in `wp_options` is `no`/`off`, then restore clean state.
3. Verify menu registration via `wp eval`: confirm `odella-speed-check`, `odella-catalog-index`, `odella-pricing`, and `odella-settings` are registered under top-level `odella-speed-check`.
4. Verify `odella_pricing_admin_page` reads `odella_rates_last` and `_odella_auto_hidden` count without PHP warnings.
- **Rollback:** `python tools\deploy.py --target plugin --rollback`
- **Commit:** `admin(6A): unified Odella menu, encrypted settings, and pricing status page`

---

## Phase 6B — Reindex Calls Recaching (Batched Sitemap Cache Warmer)

### Goal
When **Reindex** is clicked (in the top admin bar or on `Odella → Catalog & Cache`), automatically warm cold pages and products in LiteSpeed Cache right after the catalog index JSON finishes building. Also warm within a 45-second time budget during the automatic cron rebuild.

### Files
- **[NEW]** `Odella-Plugins/includes/cache-warmer.php`
- **[MODIFY]** `Odella-Plugins/includes/product-index.php`
- **[MODIFY]** `Odella-Plugins/includes/plugin.php`

### Implementation Steps
1. **Sitemap URL Collector (`Odella-Plugins/includes/cache-warmer.php`)**:
   - `nmj_cache_warmer_collect_urls(): array`:
     - Always starts with priority storefront URLs: `home_url('/ka/')` and `home_url('/ka/shop/')`, plus the category archive URLs from `odella-catalog/current.json` (so navigation and filter landings warm first).
     - Fetches `home_url('/sitemap_index.xml')` (`timeout => 15`), extracts `<loc>` tags matching `page-sitemap` and `product-sitemap`, fetches each child sitemap, and appends all `<loc>` URLs (deduplicated with `array_values(array_unique($urls))`).
     - Excludes `/cart`, `/checkout`, `/my-account`, and `/en/` draft URLs if any appear.
2. **Single-URL Warmer (`nmj_cache_warmer_warm_url(string $url): array`)**:
   - Sends two logged-out `wp_remote_get` requests (`cookies => []`, `timeout => 15`, `redirection => 3`):
     - Mobile UA: `Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)`
     - Desktop UA: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)`
   - Inspects response header `x-litespeed-cache` (`hit` vs `miss`). If a request was a `miss`, pauses `100ms` (`usleep(100000)`) so LiteSpeed finishes writing the cache entry without spiking CPU. If already `hit`, moves on immediately.
3. **AJAX Batch Steps (`nmj_product_index_ajax()` in `Odella-Plugins/includes/product-index.php`)**:
   - Keep existing `start`, `batch`, `finish` steps for catalog indexing.
   - Add three warming steps under the same action (`nmj_product_index`) so both `Reindex` and `Speed Check` can drive it:
     - `step=warm_start`: calls `nmj_cache_warmer_collect_urls()`, writes state file `odella-catalog/warm-<token>.json` with `['urls' => $urls, 'offset' => 0, 'hits' => 0, 'misses' => 0, 'started' => time()]`, returns `{ token, total }`.
     - `step=warm_batch`: reads `warm-<token>.json`, slices **4 URLs** (`8` requests: 4 mobile + 4 desktop), warms them via `nmj_cache_warmer_warm_url()` (or `Requests::request_multiple` / `curl_multi` in pairs of 2 URLs for speed while staying polite to shared hosting), updates `offset`, `hits`, `misses`, and returns `{ offset, total, hits, misses }`.
     - `step=warm_finish`: removes `warm-<token>.json`, writes `update_option('odella_last_warm', ['time' => time(), 'urls' => $total, 'hits' => $hits, 'misses' => $misses], false)`, and returns the summary.
4. **Update Frontend/Admin-Bar JS (`nmj_product_index_footer_script()`)**:
   - After `step=finish` (`Indexed N`), `rebuild()` automatically continues to `warm_start` → loop `warm_batch` (`Warming X / Y`) → `warm_finish` (`Indexed N · Warmed Y`).
   - On `Odella → Catalog & Cache`:
     - Show **Last index build** (`timestamp`, `products`) AND **Last cache warm** (`timestamp`, `URLs`, `hits`, `misses`).
     - Provide two buttons: **Rebuild index & warm cache** (primary) and **Warm cache only** (secondary, runs `warm_start` → `warm_batch` → `warm_finish` directly).
5. **Cron Time-Budgeted Warm (`nmj_product_index_cron_run()`)**:
   - After `nmj_product_index_build_all()`, call `nmj_cache_warmer_run_budget(45)` which iterates through `nmj_cache_warmer_collect_urls()` until 45 seconds have elapsed, updating `odella_last_warm`.

### Verify (Phase 6B)
1. Deploy `--target plugin`.
2. Test `nmj_cache_warmer_collect_urls()` via `wp eval`: confirm it returns `> 150` valid `https://odella.ge/...` URLs with `/ka/` and `/ka/shop/` first.
3. Run a 5-URL warm test via `wp eval` (`nmj_cache_warmer_warm_url`) and verify the second pass returns `hit` for both mobile and desktop UAs.
- **Rollback:** `python tools\deploy.py --target plugin --rollback`
- **Commit:** `cache(6B): chain sitemap cache warming into Reindex and catalog cron`

---

## Phase 6C — Modern Cart Quantity Update Speed

### Goal
Make clicking `+` / `-` in the Modern Cart slide-out drawer feel instantaneous in the browser and cut the server `moderncart_update_cart` execution time below `300 ms` for both guests and logged-in admins.

### Files
- **[MODIFY]** `site/mu-plugins/odella-performance.php`
- **[MODIFY]** `Odella-Plugins/odella-elementor-widgets.php`
- **[MODIFY]** `Odella-Plugins/includes/cart-refresh.php`
- **[MODIFY]** `Odella-Plugins/assets/js/cart-refresh.js`
- **[MODIFY]** `site/theme-child/style.css`

### Implementation Steps

#### 6C.1 Measure Baseline First (Mandatory Before Code Edits)
Run a timing benchmark over SSH (via `wp eval` + `curl` against `https://odella.ge/wp-admin/admin-ajax.php`) and record the exact baseline numbers:
1. Measure `action=moderncart_refresh_slide_out_cart` and `action=moderncart_update_cart` with a real WooCommerce session containing **1 item** (e.g. product `18781`) and **5 items**, both as a **guest** and with **logged-in admin cookies**.
2. Record how many active plugins load during `admin-ajax.php` (`count(wp_get_active_and_valid_plugins())`) and the response time in milliseconds.

#### 6C.2 Slim Server Plugin Load on Modern Cart AJAX (`site/mu-plugins/odella-performance.php`)
1. Today, `odella_is_public_frontend_request()` returns `false` on **all** `DOING_AJAX` requests, so every Modern Cart click loads all 31 active plugins (including Elementor, Elementor Pro, Rank Math Pro, FluentForm Pro, ACF Pro, FileBird Pro, WP Retina 2x Pro, PayPal Payments, etc.).
2. Add `odella_is_moderncart_ajax_request()` in `site/mu-plugins/odella-performance.php`:
   ```php
   function odella_is_moderncart_ajax_request(): bool {
       if ( PHP_SAPI === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
           return false;
       }
       $uri = $_SERVER['REQUEST_URI'] ?? '';
       if ( strpos( $uri, 'admin-ajax.php' ) === false && ! isset( $_GET['wc-ajax'] ) ) {
           return false;
       }
       $action = $_REQUEST['action'] ?? ( $_GET['wc-ajax'] ?? '' );
       return is_string( $action ) && in_array( $action, [
           'moderncart_update_cart',
           'moderncart_refresh_slide_out_cart',
           'moderncart_refresh_floating_cart',
           'moderncart_add_to_cart',
           'moderncart_remove_product',
           'moderncart_apply_coupon',
           'moderncart_remove_coupon',
       ], true );
   }
   ```
3. When `odella_is_moderncart_ajax_request()` is true, filter `option_active_plugins` at priority `1` to remove plugins that do not participate in cart drawer calculation or rendering:
   - Strip: `bulk-edit-*`, `woo-orders-spreadsheet-table-premium/*`, `woo-products-bulk-editor-premium/*`, `wp-sheet-editor-*`, `bulky-woocommerce-bulk-edit-products/*`, `acf-quickedit-fields/*`, `acf-options-for-polylang/*`, `advanced-custom-fields-pro/*`, `enable-media-replace/*`, `filebird-pro/*`, `fluentform/*`, `fluentformpro/*`, `instagram-sync-settings/*`, `seo-by-rank-math/*`, `seo-by-rank-math-pro/*`, `simple-custom-post-order/*`, `taxonomy-terms-order/*`, `wp-retina-2x-pro/*`, `hostinger/*`, `adv-geoip-redirect/*`, `checkout-country-flags-for-woocommerce/*`, `phone-validator-with-flags-for-woocommerce/*`, `woocommerce-checkout-manager/*`, `woocommerce-paypal-payments/*`, `elementor/*`, `elementor-pro/*`, `connect-polylang-elementor/*`.
   - Keep active: `woocommerce`, `modern-cart`, `polylang-pro`, `polylang-wc`, `woocommerce-product-price-based-on-countries`, `woo-variation-swatches`, `woo-variation-swatches-pro`, `odella-elementor-widgets`, `litespeed-cache`.
4. **Ensure Georgian cart strings still load when Elementor is skipped on cart AJAX**:
   - In `Odella-Plugins/odella-elementor-widgets.php`, check how `includes/plugin.php` is booted. Currently it boots on `plugins_loaded` only if `did_action('elementor/loaded')` is true!
   - Update `Odella-Plugins/odella-elementor-widgets.php` so that when `!did_action('elementor/loaded')` during `odella_is_moderncart_ajax_request()`, it still requires `includes/i18n.php` and `includes/cart-refresh.php` (instead of printing an admin notice or returning early). That way Georgian Modern Cart translations (`nmj_translate_modern_cart`) work 100% without loading Elementor Core & Pro on cart AJAX!

#### 6C.3 Optimistic Instant UI, 300 ms Debounce & Single-Request Badge Update (`Odella-Plugins/assets/js/cart-refresh.js` & `site/theme-child/style.css`)
1. **Why Modern Cart's JS felt sluggish**:
   - On clicking `.moderncart-cart-item-plus` or `.moderncart-cart-item-minus`, Modern Cart's `cart.js` immediately calls `beforeAjaxAction()` (which adds `.moderncart-With-loading` / disables buttons) and sends `moderncart_update_cart`.
   - When `moderncart_update_cart` returns, `cart.js` calls `ModernCartFrontendScripts.refreshFloating(e)`, firing a **second** serial POST (`moderncart_refresh_floating_cart`).
   - Clicking `+` 3 times required waiting for 6 serial HTTP requests while the drawer locked after every single click.
2. **Fix in `Odella-Plugins/assets/js/cart-refresh.js`**:
   - Attach a **capture-phase** `click` listener (`document.addEventListener('click', ..., true)`) matching `#moderncart-slide-out-modal button[data-action="up"], #moderncart-slide-out-modal button[data-action="down"]` (verify the exact selector in `wp-content/plugins/modern-cart/assets/js/cart.js` on the server before writing — look around line 1040 of `cart.js` where `moderncart_update_cart` is bound!).
   - In the capture listener:
     - Call `event.stopImmediatePropagation()` and `event.preventDefault()` so Modern Cart's un-debounced handler does not fire.
     - Read `cart_key` (`data-key`) and current quantity from the row's `.moderncart-cart-item-quantity__input` (or equivalent input selector from `cart.js`).
     - Compute the new quantity (`+1` for `up`, `-1` for `down`, min `0`).
     - **Optimistic DOM update (0 ms)**:
       - Immediately set the quantity input's value to the new quantity.
       - Immediately scale the row's displayed line price if a single unit price can be inferred, and add a subtle non-blocking `.odella-qty-syncing` class to the price/subtotal text only (buttons remain 100% clickable!).
       - Immediately update `#moderncart-floating-cart .moderncart-floating-cart-count` by `+1` / `-1`.
     - **Debounced Server Sync (280 ms)**:
       - Clear any pending timer for `cart_key` and abort any in-flight `AbortController` for `moderncart_update_cart`.
       - After `280 ms` of no further clicks, send **one** `fetch()` POST to `moderncart_ajax_object.ajax_url` with `action=moderncart_update_cart`, `quantity`, `cart_key`, `notice_action=true`, `moderncart_nonce=moderncart_ajax_object.ajax_nonce`.
       - If the user clicked again while a request was in flight, ignore the stale response and let the latest debounced request win.
       - When the final response arrives (`response.content`):
         - Update `#moderncart-slide-out-modal` innerHTML with `response.content`.
         - Sum the quantities from the freshly rendered `#moderncart-slide-out-modal` inputs (or read `response.cart_count` if present) and update `#moderncart-floating-cart .moderncart-floating-cart-count` directly in the DOM — **eliminating the second `moderncart_refresh_floating_cart` network request** (fall back to calling `post('moderncart_refresh_floating_cart')` only when the cart becomes empty `count === 0` if launcher visibility needs refreshing).
         - If the nonce was rejected, refresh the nonce once via `fetchNonce()` and retry automatically.
3. **Subtle Non-Blocking Sync Indicator (`site/theme-child/style.css`)**:
   - Style `.odella-qty-syncing` with a soft `opacity: 0.65; transition: opacity 120ms ease;` on the price/total numbers only, never blocking pointer events on the `+` / `-` buttons.

### Verify (Phase 6C)
1. Deploy `--target mu`, `--target plugin`, and `--target theme`.
2. Re-run the SSH benchmark from 6C.1:
   - Verify active plugin count inside `moderncart_update_cart` dropped from `~31` to `9`.
   - Verify `moderncart_update_cart` server execution time is `< 300 ms` (record exact before/after ms for guest and logged-in, 1 item and 5 items).
   - Verify Georgian strings (`თქვენი კალათა`, `ჯამი`, `გაფორმება`) still appear in the returned `moderncart_update_cart` HTML on `/ka/`.
- **Rollback:** `python tools\deploy.py --target mu --rollback`, `--target plugin --rollback`, `--target theme --rollback`
- **Commit:** `cart(6C): instant optimistic quantity updates and slim cart AJAX`

---

## Phase 6D — "Is Slow?" Diagnostic Dashboard, Auto-Warmer & Multi-Language Gemini Report

### Goal
Build the `Odella → Speed Check` page (opened from the top-bar **Is Slow?** button) that runs 9 deterministic checks + 2 parallel waves of Google PageSpeed Insights (Mobile + Desktop), automatically warms the cache when cold cache is the culprit, provides a confirmation-guarded **Purge & Rebuild** when CSS 404s occur, and calls Gemini Flash to produce a multi-language tabbed explanation (up to 3 languages) when other bottlenecks exist.

### Files
- **[NEW]** `Odella-Plugins/includes/speed-check.php`
- **[NEW]** `Odella-Plugins/assets/js/speed-check.js`
- **[NEW]** `Odella-Plugins/assets/css/speed-check.css`
- **[MODIFY]** `Odella-Plugins/includes/admin-menu.php`
- **[MODIFY]** `Odella-Plugins/includes/plugin.php`

### Implementation Steps

#### 6D.1 Step 1 — 9 Deterministic Checks (`wp_ajax_odella_speed_check`, `substep=deterministic`)
Requires `current_user_can('manage_woocommerce')` and nonce `odella_speed_check`. Runs all 9 checks and returns `{ checks: [...], recommended_action: 'none'|'warm_cache'|'reindex_and_warm'|'purge_and_rebuild', needs_ai: bool }`:

1. **LiteSpeed Page Cache & TTFB (4 URLs × Mobile & Desktop)**:
   - URLs: Home (`https://odella.ge/ka/`), Store (`https://odella.ge/ka/shop/`), first published Georgian product category URL, and newest published priced Georgian product URL.
   - Measures TTFB and `x-litespeed-cache` header (`hit` vs `miss`) for both Mobile and Desktop UAs.
   - Status: `pass` if `>= 6/8` are `hit` and max cached TTFB `< 400 ms`; `warn` if any `miss` (sets `recommended_action = 'warm_cache'`); `fail` if cached `hit` TTFB `> 800 ms` (sets `needs_ai = true`).
2. **Cache-Wipe Loop Detector**:
   - Inspects whether any of the 8 probe responses emitted an `x-litespeed-purge` header on a normal GET request, and checks `_elementor_pro_license_v2_data['timeout'] > time() + 86400`.
   - Status: `pass` if no purge header and license timeout valid; `fail` otherwise (`needs_ai = true`).
3. **Elementor External CSS 404 Probe**:
   - Parses all `<link rel="stylesheet" href="...">` URLs from the Home and Product HTML responses and sends HEAD requests (`wp_remote_head`) to each local stylesheet.
   - Status: `pass` if all return `200`; `fail` if any returns `404` or `>= 400` → sets `recommended_action = 'purge_and_rebuild'`.
4. **LiteSpeed & Object Cache Configuration**:
   - Checks `get_option('litespeed.conf.object') == 0` and `get_option('elementor_css_print_method') === 'external'`.
   - Status: `pass` if both match; `fail` if object cache is enabled (since Redis/Memcached daemons do not run in CageFS).
5. **Autoloaded Options Size**:
   - Queries `$wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')")` and fetches top 5 largest autoloaded option names + sizes.
   - Status: `pass` `< 800 KB`; `warn` `800 KB – 1.5 MB`; `fail` `> 1.5 MB` (`needs_ai = true`).
6. **Cron & Action Scheduler Health**:
   - Checks `DISABLE_WP_CRON`, next run of `nmj_product_index_cron` and `odella_daily_rates` (overdue by `> 1 hour` = `warn`), and `actionscheduler_actions` `pending` (`> 250` = `warn`) and `failed` (`> 50` = `warn`) counts.
7. **Catalog Filter Index Freshness**:
   - Reads `odella-catalog/current.json` (`v` timestamp) and compares against newest published product `MAX(post_modified_gmt)`.
   - Status: `pass` if index exists and is newer than the latest product edit (or within 10 minutes); `warn` if stale or missing → upgrades `recommended_action` to `'reindex_and_warm'`.
8. **`admin-ajax.php` Response Time**:
   - Times an internal loopback POST to `admin-ajax.php` (`action=moderncart_refresh_floating_cart`).
   - Status: `pass` `< 350 ms`; `warn` `350–700 ms`; `fail` `> 700 ms` (`needs_ai = true`).
9. **WooCommerce Sessions & Expired Transients**:
   - Counts rows in `{$wpdb->prefix}woocommerce_sessions` (`> 5000` = `warn`) and expired `_transient_timeout_*` rows in `{$wpdb->options}` (`> 1000` = `warn`).

#### 6D.2 Step 2 — Auto-Remediation & Guarded Purge
- **Auto-Warm (`recommended_action === 'warm_cache'` or `'reindex_and_warm'`)**:
  - When the culprit is cold cache (or stale catalog index + cold cache) and Check 3 (CSS 404s) passed:
    - The UI displays a green/blue banner: **"Culprit found: Cold cache after recent changes. Rebuilding index and warming cache automatically…"**
    - Calls the Phase 6B batch endpoints (`start` → `batch` → `finish` if index was stale, then `warm_start` → `warm_batch` → `warm_finish`) with a live progress bar.
    - Once warming completes, automatically re-runs Check 1 (`substep=recheck_cache`) to verify that all probed URLs are now `hit` and fast!
- **Confirmation-Guarded Purge & Rebuild (`substep=purge_only`)**:
  - Shown when Check 3 detects Elementor CSS 404s (or via a secondary **"Force Purge & Rebuild Cache"** button).
  - Clicking opens a confirmation dialog explaining: *"Purging clears all cached pages across the site and takes ~5–8 minutes to re-warm. Continue?"*
  - When confirmed:
    1. Calls `substep=purge_only` (runs `\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush(); do_action('litespeed_purge_all');`).
    2. Displays a clear callout reminding the user to purge Hostinger Edge CDN if needed (`hPanel → Websites → odella.ge → Performance → CDN → Purge all`).
    3. Immediately chains into the full Phase 6B `Reindex + Warm Cache` flow.

#### 6D.3 Step 3 — PageSpeed Insights in Two Parallel Waves (`substep=psi_wave`)
- Reads decrypted `psi_key_enc` from `odella_speed_settings`. If not set, shows a notice linking to `Odella → Settings` (`admin.php?page=odella-settings`) while still completing the deterministic checks.
- Benchmark URLs (3):
  1. Home: `https://odella.ge/ka/`
  2. Store: `https://odella.ge/ka/shop/`
  3. Product: newest published priced Georgian product permalink (or `https://odella.ge/ka/product/cardigan-dream-bordeaux/` if available).
- **Wave 1 (`wave=mobile`)**:
  - Uses `curl_multi_init()` in PHP to request all 3 URLs concurrently from `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=...&strategy=mobile&category=performance&key=...` (`CURLOPT_TIMEOUT => 75`).
  - Returns scores and core metrics (`score`, `fcp`, `lcp`, `tbt`, `cls`, `ttfb`, and top 5 failed audits with `title` and `displayValue`).
- **Wave 2 (`wave=desktop`)**:
  - Immediately after Wave 1 renders in the browser, JS triggers `wave=desktop` for the same 3 URLs in parallel and renders the desktop cards alongside mobile.
- If any Mobile score `< 75` or Desktop score `< 85` (or LCP `> 3.0s`), sets `needs_ai = true`.

#### 6D.4 Step 4 — Multi-Language Gemini Flash Diagnosis (`substep=ai_explain`)
- Triggered automatically when `needs_ai === true` after auto-warm + PSI, or anytime the user clicks **"Explain with AI"**.
- Reads decrypted `gemini_key_enc`, `gemini_model` (default `gemini-flash-latest`), `languages` (up to 3, e.g. `['en', 'ka']`), and `odella_effective_ai_prompt()`.
- Builds the prompt payload containing:
  1. `odella_effective_ai_prompt()`
  2. Appended output contract:
     ```
     REQUIRED OUTPUT FORMAT:
     Return ONLY valid JSON (no markdown fences) with translations for these exact language codes: [LANG_CODES_CSV].
     Schema:
     {
       "reports": {
         "<lang_code>": {
           "headline": "One-sentence verdict",
           "summary": "2-3 sentence plain-language explanation of why the site is slow or healthy",
           "root_causes": ["Cause 1 with metric evidence", "Cause 2"],
           "actions": ["Actionable fix 1", "Actionable fix 2"]
         }
       }
     }
     ```
  3. Sanitized JSON telemetry block (`checks`, `auto_warm_result`, `psi.mobile`, `psi.desktop`). **Zero PII, zero customer/order data, zero secrets.**
- Calls `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` via `wp_remote_post` with:
  - Header `x-goog-api-key: $gemini_key`
  - Header `Content-Type: application/json`
  - Body `{"contents":[{"parts":[{"text": $prompt}]}], "generationConfig":{"responseMimeType":"application/json","temperature":0.2}}`
  - `timeout => 35`
- Parses the returned JSON and renders interactive **language tabs** (e.g. `[ English ] [ ქართული ]`) above the AI Diagnosis card.
- Persists the complete run (`timestamp`, `checks`, `psi`, `ai_reports`) in `update_option('odella_speed_last_report', $report, false)` so visiting `Odella → Speed Check` always displays the most recent report until a new check is run.

### Verify (Phase 6D)
1. Deploy `--target plugin`.
2. Run `substep=deterministic` via `wp eval` and confirm all 9 checks return structured statuses (`pass`/`warn`/`fail`) with zero PHP notices.
3. Verify the Gemini prompt builder appends the JSON schema for both default and custom prompts and enforces the 1–3 language array.
- **Rollback:** `python tools\deploy.py --target plugin --rollback`
- **Commit:** `speed(6D): Is Slow diagnostic engine, parallel PSI waves, auto-warmer, and multi-language Gemini report`

---

## Phase 6E — Registration Speed Profiling & Email Deferral

### Goal
Find and eliminate the delay when a new customer submits the registration form on `/ka/my-account-2/` (`post 4085`).

### Files
- **[MODIFY]** `Odella-Plugins/includes/account.php`
- **[MODIFY]** `site/mu-plugins/odella-performance.php`

### Implementation Steps

#### 6E.1 Add Registration Profiler & Measure Baseline (`Odella-Plugins/includes/account.php`)
1. Add lightweight phase timers around customer registration in `Odella-Plugins/includes/account.php`:
   - Hook `woocommerce_register_post` (priority `1`) → record `$t_start`.
   - Hook `user_register` (priority `1` and `999`) → record user insert + `user_register` hooks duration.
   - Hook `woocommerce_created_customer` (priority `1` and `999`) → record `woocommerce_created_customer` duration (which includes `WC_Emails::send_transactional_email` when synchronous).
   - Hook `pre_wp_mail` / `phpmailer_init` and `wp_mail_succeeded` / `wp_mail_failed` during registration → record exact `mail_ms`.
   - Save the timing breakdown (`total_ms`, `insert_user_ms`, `created_customer_ms`, `mail_ms`, `deferred`, `timestamp` — **no email address or user PII**) to `update_option('odella_last_registration_profile', $profile, false)`.
2. Run a baseline test registration over SSH (using `curl` or `wp eval` simulating the `woocommerce-register-nonce` POST for a disposable `test-reg-<timestamp>@odella.ge` user, then immediately deleting that test user with `wp user delete <id> --yes`) and record the baseline numbers.

#### 6E.2 Slim Registration POST & Flush Redirect Before Email Dispatch
1. **Skip admin-only bulk plugins on frontend form POSTs (`site/mu-plugins/odella-performance.php`)**:
   - Verify `odella_is_public_frontend_request()` returns `true` on `POST /ka/my-account-2/` so the 10 admin-only spreadsheet/utility plugins stay unloaded during registration and login submits. Also add `bulky-woocommerce-bulk-edit-products/bulky-woocommerce-bulk-edit-products.php` to `$admin_only_plugins` in `odella-performance.php` (it was installed after the original list was written!).
2. **Defer Transactional Emails & Flush HTTP 302 Redirect Before `wp_mail()` (`Odella-Plugins/includes/account.php`)**:
   - Enable WooCommerce's transactional email deferral:
     ```php
     add_filter('woocommerce_defer_transactional_emails', '__return_true');
     ```
   - Even with `woocommerce_defer_transactional_emails`, WooCommerce queues the email onto a `shutdown` action (`WC_Emails::send_queued_transactional_emails`), which normally holds the HTTP connection open unless the response is flushed first!
   - When `woocommerce_created_customer` fires on a frontend registration POST:
     - Hook `wp_redirect` at priority `999` (only when a new customer was just created in this request):
       - Emit the `Status: 302 Moved Temporarily`, `Location: $location`, `Cache-Control: no-store, private`, and `Connection: close` headers.
       - Call `litespeed_finish_request()` if `function_exists('litespeed_finish_request')`, else `fastcgi_finish_request()` if available, else flush output buffers (`ob_end_flush(); flush();`).
       - Return `false` / `exit` after running `shutdown` hooks (`do_action('shutdown')` runs automatically on `exit`), so PHP finishes `WC_Emails::send_queued_transactional_emails()` and `wp_mail()` **after** the visitor's browser has already received the `302` redirect and started loading the My Account dashboard!

### Verify (Phase 6E)
1. Deploy `--target mu` and `--target plugin`.
2. Run the automated registration test via `curl` against `https://odella.ge/ka/my-account-2/` with a temporary `test-reg-<timestamp>@odella.ge` user (and delete the test user immediately after):
   - Verify the HTTP `302` response returns in `< 400 ms`.
   - Read `wp option get odella_last_registration_profile --format=json` and verify the profile was recorded and `deferred` is `true`.
- **Rollback:** `python tools\deploy.py --target mu --rollback` and `--target plugin --rollback`
- **Commit:** `account(6E): flush registration redirect before wp_mail and skip bulk-edit plugins`

---

## Phase 6F — Docs, Version Drift Sync & Owner Manual Test Guide

### Files
- **[MODIFY]** `DECISIONS.md`
- **[MODIFY]** `PROJECT_BRIEF.md`
- **[NEW]** `roadmaps/manual-test-04-speed-and-cart.html`

### Implementation Steps
1. **`DECISIONS.md`**:
   - Add dated entries (`2026-11-15`) for:
     - **Unified `Odella` admin menu & encrypted DB settings**: `Speed Check`, `Catalog & Cache`, `Pricing & Rates`, and `Settings` live under the top-level `Odella` menu; PageSpeed and Gemini API keys are encrypted with `libsodium` in `odella_speed_settings` (`autoload=no`), with up to 3 report languages and a read-only Default Prompt + `Custom` toggle.
     - **Reindex warms LiteSpeed cache**: Rebuilding the catalog index automatically warms cold sitemap URLs (mobile + desktop UAs) without purging already-warm pages; the 24h cron warms within a 45s budget.
     - **Modern Cart instant quantity & slim AJAX**: Cart AJAX skips non-cart plugins, updates quantity/totals optimistically in the DOM with a 280–300 ms debounce, and updates the floating badge without a second AJAX round-trip.
     - **Registration flushes HTTP 302 before `wp_mail()`**: Customer registration defers transactional emails and calls `litespeed_finish_request()` on redirect so the visitor never waits on `sendmail`/SMTP.
     - **Live Elementor is 4.4.0 / Pro 4.4.0**: Supersedes the 2026-11-08 note (`4.3.6 / 4.3.3`).
2. **`PROJECT_BRIEF.md`**:
   - Update plugin version and Elementor version references (`4.4.0`), document the top-level `Odella` menu (`Speed Check`, `Catalog & Cache`, `Pricing & Rates`, `Settings`), and note the new files in `Odella-Plugins/includes/`.
3. **`roadmaps/manual-test-04-speed-and-cart.html`**:
   - Build a clean, self-contained interactive HTML checklist (matching the style of `roadmaps/manual-test-03-shop-checkout.html`) walking the owner through:
     1. Entering and testing the client's **PageSpeed API Key** and **Gemini API Key** in `wp-admin → Odella → Settings`.
     2. Testing the **Custom** prompt toggle and choosing up to 3 report languages (e.g., English + Georgian).
     3. Clicking **Is Slow?** in the top admin bar and verifying the 9 checks, Mobile/Desktop PageSpeed waves, auto-warmer, and multi-language Gemini tabs.
     4. Clicking **Reindex** and watching `Indexing…` → `Warming…` complete.
     5. Testing **Modern Cart** `+` / `-` rapid clicks on `/ka/shop/` (both logged in and in an incognito window).
     6. Testing **My Account Registration** on `/ka/my-account-2/` with a real email address, confirming instant redirect and Georgian password email delivery.

### Verify (Phase 6F)
1. Run `python tools\deploy.py --check --all` and `python tools\probe_css.py`.
2. Confirm `git status` is clean after committing.
- **Commit:** `docs(6F): record roadmap 06 decisions and manual test guide`
