# Roadmap 02 — Missing prices, unpriced-product guards, EUR/USD pricing

**For:** the Cursor agent implementing this. **Written:** 2026-11-08 from a grilling session; decisions below are agreed with the owner — do not re-open them.
**Prerequisite:** Roadmap 01 **Phase 0** is done (git, `tools/deploy.py --target mu`, `tools/snapshot.py`, purge sequence, smoke test). Everything here deploys through the `mu` target. Same ground rules as roadmap 01 (SSH form, `wp eval-file -`, Python 3.6 on the server, no `wp db *`, commit per verified slice).

---

## What is wrong (verified 2026-11-08)

1. **50 of 212 published products have no price.** `_regular_price` and `_price` are empty, so WooCommerce shows no price and the product **cannot be added to the cart**. Breakdown: 36 long kimonos (all of them), 6 square scarves, 6 headscarves (თავსაბურავი), 3 beachwear, 2 scarves, 1 short kimono, 1 coat, 1 vest, 1 gift set (some products sit in several categories). Full list: `roadmaps/data/missing-prices.csv` (ID, SKU, name, categories, suggested price, edit link). Suggested prices are filled only where every priced sibling uses one price: square scarf 90 ₾ (63 of 63 siblings), short kimono 295 ₾ (13 of 13).
   Product 16128 "Pera" is the only variable product; its price lives on its variations (60–110 ₾) and is fine.
2. **Foreign prices are wrong.** Plugin *Price Based on Country for WooCommerce* **4.3.6 (free)**. Zones: `georgia` (GEL, GE), `eurozone` (EUR), `global` (USD, rest of world). Both EUR and USD zones have `exchange_rate = 1` and `auto_exchange_rate = no`, and **no product has a manual EUR/USD price**. A 430 ₾ coat therefore shows as €430 / $430 abroad. The free plugin has no automatic rates and only rounds decimals.
3. **Prices are edited with WP Sheet Editor** (`wp-sheet-editor-premium` + `woo-products-bulk-editor-premium`) as well as the normal product screen. Sheet Editor writes through a mix of `update_post_meta`, `$product->set_regular_price()` + `->save()`, `wp_update_post`, and fires `vg_sheet_editor/save_rows/after_saving_post` / `…/after_saving_rows`. **Guards must work whichever way the data was written.**

## Agreed behaviour

- **Q1** Someone else enters the 50 prices (in WP admin or WP Sheet Editor). No import script; we provide the list and a progress check.
- **Q2** Two guards, both working with WP Sheet Editor:
  1. A product that ends a save as *Published* with no price is switched back to *Draft*, and the admin sees a notice listing it.
  2. Any published product without a price is hidden from the Store, category pages, search and Related Products.
- **Q3** EUR/USD: a **manual zone price wins** when one is entered for that product. Otherwise the price is **converted at the real exchange rate and rounded up to the next whole number ending in 9** (e.g. €148.30 → €149, €150.20 → €159, €149 stays €149).
- **Q4** The 50 existing unpriced products are hidden (guard 2) from the moment it is deployed, and reappear automatically as soon as a price is saved.

---

## Phase A — Hide unpriced products (guard 2) — do this first

Design: use WooCommerce's own **catalog visibility** taxonomy (`product_visibility` terms `exclude-from-catalog`, `exclude-from-search`). WooCommerce queries, Related Products (`wc_get_related_products`) and search already respect it, it is fast (no meta joins), and it does not depend on how the price was saved.

### A.1 Code — new mu-plugin `site/mu-plugins/odella-pricing.php`

All pricing code in this roadmap goes in a **new file** `site/mu-plugins/odella-pricing.php` (header `Plugin Name: Odella Pricing Guards (MU)`, `Version: 1.0.0`), separate from `odella-performance.php`. Extend `tools/deploy.py`'s `mu` target to manage **both** files (each uploaded as `.new`, `php -l`, renamed), and add it to `.deploy-state/mu.json`.

Functions:

1. `odella_product_has_price( WC_Product $p ): bool`
   - simple/external: `'' !== $p->get_regular_price()` (base GEL price).
   - variable: at least one child variation with a non-empty regular price.
   - grouped: true (it has no own price).
2. `odella_sync_unpriced_visibility( int $product_id ): void`
   - If the product is published **and** has no price: add terms `exclude-from-catalog` and `exclude-from-search` (`wp_set_object_terms( $id, $terms, 'product_visibility', true )`), and set meta `_odella_auto_hidden = 1` — **only** if it was not already excluded by the owner (record which of the two terms we added in `_odella_auto_hidden_terms`, JSON).
   - If it has a price and `_odella_auto_hidden` is set: remove only the terms we added, delete both metas.
   - Then `wc_delete_product_transients( $id )`.
3. **Collect touched products during a request** in a static array:
   - `save_post_product` (normal product screen, quick edit, REST),
   - `woocommerce_update_product`, `woocommerce_new_product` (WC CRUD, used by Sheet Editor's `->save()`),
   - `added_post_meta`, `updated_post_meta`, `deleted_post_meta` when `$meta_key` is one of `_regular_price`, `_price`, `_sale_price` (direct `update_post_meta` writes). For a `product_variation`, collect its parent ID.
   - `vg_sheet_editor/save_rows/after_saving_post` (`$post_id` arg) — belt and braces for Sheet Editor.
   - `transition_post_status` for `product` posts.
4. **Process at the end of the request:** `add_action( 'shutdown', … )` — for each collected ID, run a price sync first (if `_regular_price` is set but `_price` is empty, `wc_get_product( $id )->save()` so WooCommerce rebuilds `_price` and the lookup table), then `odella_sync_unpriced_visibility()`. Guard against recursion with a static flag (`->save()` re-fires the hooks).
5. **One-time backfill** for the existing 50: a WP-CLI-safe function `odella_pricing_backfill()` that loops over all published products and runs the sync. Called once via `wp eval 'odella_pricing_backfill();'` (and available for re-runs).
6. **Direct URL of a hidden product** still opens (so links never 404), but print `noindex` via Rank Math: `add_filter( 'rank_math/frontend/robots', … )` set `index` → `noindex` when `_odella_auto_hidden` is set.

### A.2 Deploy and backfill

1. `snapshot.py` does not cover taxonomy terms, so save the current visibility terms separately: a `wp eval-file -` script that prints `{ product_id: [product_visibility term slugs] }` for all products, saved locally as `snapshots/<date>-product-visibility.json`. Commit it.
2. `python tools\deploy.py --target mu`
3. `wp eval 'odella_pricing_backfill();'` → prints `hidden=50 restored=0`.

### A.3 Verify

- `python tools\deploy.py --check --target mu` parity; smoke test passed.
- Store `/ka/store/`, category `/ka/product-category/women/kimono-women/long-kimono-women/` (should show the empty-category message or only priced items), search for "Morbido": **no card without a price**, mobile + desktop. Load More still works and never brings an unpriced card.
- A product page's Related Products: no unpriced card.
- **If the Elementor Loop Grid (template 15580, card 14786) still shows unpriced products**, it is not respecting catalog visibility: add a `pre_get_posts` / `elementor/query/query_args` fallback that adds a `tax_query` excluding `product_visibility` term `exclude-from-catalog` for frontend `product` queries, then re-verify. Do the same check for the WOOF/Husky filter results (filter by a category with unpriced items).
- Open one hidden product's URL directly → loads, no price, page source contains `noindex`.
  Rollback: `deploy.py --target mu --rollback`, then restore visibility terms from the JSON snapshot with a small `wp eval-file -` script (write it now, alongside the snapshot, and test it on one product).
  **Commit:** `pricing(A): hide unpriced products via catalog visibility`.

---

## Phase B — Keep new unpriced products as Draft (guard 1)

Agreed rule: when a request ends with a product **Published** and without a price, set it back to **Draft** and tell the admin.

1. In the same `shutdown` processing (A.1.4), before the visibility sync, for each collected ID:
   - if `post_status === 'publish'` and `! odella_product_has_price()` **and the product was not already published-and-unpriced before this request** (i.e. it is not one of the 50 legacy items left untouched — use `_odella_auto_hidden` set *before* this request as the "legacy" marker), then:
     - `wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] )` with the recursion flag set;
     - append `$id` to a per-user transient `odella_unpriced_drafted_<user_id>` (expires 1 day).
       Legacy items that are only *edited* (e.g. a description change) stay published-and-hidden; they never get drafted, so whoever is filling prices in Sheet Editor does not see rows vanish to Draft.
2. Admin notice: on `admin_notices` (all admin screens — Sheet Editor runs inside wp-admin), if the transient exists, show a yellow notice: "These products were kept as Draft because they have no price: <title (ID)> …" with edit links; delete the transient after showing.
   Georgian + English text: `"შენახულია მონახაზად, რადგან ფასი არ აქვს:"` / `"Kept as Draft because they have no price:"` — show the one matching the admin user's locale (`get_user_locale()` starts with `ka`).
3. Deploy `--target mu`.

### B.1 Verify (create test products, delete them after)

- **Product screen:** Add New product "TEST unpriced", no price, click Publish → reloads as Draft; notice lists it. Add price 1 ₾ → Publish → stays Published, visible in Store. Delete it permanently afterwards (Trash → Delete permanently).
- **WP Sheet Editor:** create a row "TEST sheet unpriced", status Published, empty price, save → after save, reload sheet: status Draft; open any wp-admin page → notice lists it. Then fill price, set status Published, save → stays Published and appears in Store. Delete the test product.
- **Bulk editor:** select 2 priced products, clear their regular price in the bulk editor, save → both become Draft and hidden; restore their prices and status from the snapshot/`missing-prices` logic; confirm they are back. (Use two products in a low-traffic category and do it quickly, or use the test products above.)
- A legacy unpriced product (e.g. ID 17550 Morbido): edit only its description in Sheet Editor → stays Published, still hidden.
  Rollback: `deploy.py --target mu --rollback`.
  **Commit:** `pricing(B): draft guard for products saved without price`.

---

## Phase C — Help whoever fills the 50 prices

1. Hand-over file: `roadmaps/data/missing-prices.csv` (already generated, UTF-8 with BOM so Excel shows Georgian correctly). Column "GEL price (fill in)" is for notes only; prices are entered in WP admin / Sheet Editor.
2. Sheet Editor tip for the person filling prices (add to `roadmaps/data/README-prices.md`): in WP Sheet Editor → Products, filter "Regular price" **is empty** and status **Published**; type prices in the Regular price column; save. Products appear in the Store automatically (Phase A) — no need to touch visibility.
3. `tools/price_audit.py` (owner-run): runs one `wp eval-file -` and prints: number of published products without a price, number auto-hidden, and the first 20 IDs/names still missing. Must also flag any product with `_odella_auto_hidden` set but which now has a price (should be zero — means the sync did not run; fix by running `odella_pricing_backfill()`).
   **Commit:** `pricing(C): price audit tool and hand-over notes`.

---

## Phase D — EUR / USD prices: manual first, otherwise real rate rounded up to …9

### D.1 Daily exchange rate (replaces the free plugin's missing auto rate)

Source: National Bank of Georgia official rates (free, no key):
`GET https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/en/json/?currencies=EUR,USD`
Response (checked 2026-11-08): `[{"date":…,"currencies":[{"code":"EUR","quantity":1,"rate":2.8998,…},{"code":"USD","quantity":1,"rate":2.5949,…}]}]` — `rate` is **GEL per 1 unit** (divide by `quantity`).

The plugin's zone `exchange_rate` is **zone currency per 1 GEL** (base currency is GEL), so: `eurozone.exchange_rate = quantity / rate_EUR` (≈ 0.3449), `global.exchange_rate = quantity / rate_USD` (≈ 0.3854).

In `site/mu-plugins/odella-pricing.php`:

1. `odella_update_zone_rates()`:
   - `wp_remote_get( $url, [ 'timeout' => 10 ] )`; on error or bad JSON → `error_log`, keep old rates, return.
   - Sanity: new rate within **0.15–0.80** and within **±10 %** of the current stored rate (skip the ±10 % check when the stored rate is exactly `1`, i.e. the first run). Otherwise log and keep the old rate.
   - Update `wc_price_based_country_regions['eurozone']['exchange_rate']` and `['global']['exchange_rate']` (round to 6 decimals) with `update_option`. Store `odella_rates_last = [ 'date' => …, 'EUR' => …, 'USD' => … ]`.
   - Clear plugin/WC price caches: `wc_delete_product_transients()` and, if it exists, the plugin's own cache-clear function (search the plugin for `delete_transient` / `cache` helpers and call the public one). Purge LiteSpeed (`do_action( 'litespeed_purge_all' )`).
2. Schedule daily at 03:30 Asia/Tbilisi via WP-Cron (`wp_next_scheduled` guard on `init`, hook `odella_daily_rates`). Hostinger runs WP-Cron on page traffic, which is enough for a daily job.
3. First run manually: `wp eval 'odella_update_zone_rates(); print_r( get_option( "odella_rates_last" ) );'`.

### D.2 Manual price wins

The free plugin already supports per-product **price method** per zone: meta `_eurozone_price_method` / `_global_price_method` = `manual` uses `_eurozone_regular_price` / `_global_regular_price` (and `_sale_price`), otherwise exchange rate. **Confirm these exact meta keys in the plugin source** (`includes/class-wcpbc-pricing-zone.php`: `get_postmeta( $post_id, '_price_method' )` prefixes the zone id) before writing code.

Make "manual if entered" automatic, so editors (including Sheet Editor users) only have to type the EUR/USD price:

- In the same `shutdown` processing, for each touched product and each zone (`eurozone`, `global`): if `_<zone>_regular_price` is non-empty → set `_<zone>_price_method = manual`; if it is empty → set it to `exchange_rate`. Then let the plugin recompute `_<zone>_price`: call its public product-sync method if one exists (search for where it writes `_price` per zone on product save, e.g. a `WCPBC_Product_Sync` class or `maybe_update_price`), otherwise write `_<zone>_price` = sale price if set, else regular price, for manual products.
- Sheet Editor columns: **USER STEP** — in WP Sheet Editor → Products → "Columns visibility/Custom fields", enable the columns `_eurozone_regular_price` and `_global_regular_price` and rename them "EUR price" and "USD price". (If Sheet Editor does not detect them until one product has a value, set one manual price on the product screen first.)

### D.3 Round exchange-rate prices up to …9

The free plugin's rounding is a protected method with no filter, so round at the WooCommerce getter level, **only** when the current zone is EUR/USD **and** that product uses the exchange rate (manual prices are never touched):

```php
function odella_round_up_9( $v ) {
    if ( '' === $v || null === $v || ! is_numeric( $v ) ) { return $v; }
    $v = (float) $v;
    if ( $v <= 0 ) { return $v; }
    $whole = ceil( $v );                                   // never round down
    return (string) ( ceil( ( $whole + 1 ) / 10 ) * 10 - 1 ); // next number ending in 9, >= $whole
}
```

Hook at `PHP_INT_MAX` priority on: `woocommerce_product_get_price`, `woocommerce_product_get_regular_price`, `woocommerce_product_get_sale_price`, `woocommerce_product_variation_get_price`, `…_get_regular_price`, `…_get_sale_price`, `woocommerce_variation_prices_price`, `woocommerce_variation_prices_regular_price`, `woocommerce_variation_prices_sale_price`. Inside: get the active zone (find the plugin's accessor, e.g. `wcpbc_the_zone()`; confirm the name in source), skip if none or its currency is `GEL`, skip unless `$zone->is_exchange_rate_price( $product )` (exists — line ~485 of `class-wcpbc-pricing-zone.php`). Add `'odella_r9'` and the zone id to `woocommerce_get_variation_prices_hash` so cached variation prices are rebuilt.

Write `tools/test_round9.php` (pure PHP, run with `wp eval-file -`) asserting exactly: `148.3→149`, `149→149`, `149.01→159`, `150.2→159`, `9→9`, `9.5→19`, `0→0`, `''→''`. All must pass before deploying.

### D.4 Verify

- `wp eval` prints new zone rates ≈ 0.345 (EUR) and ≈ 0.385 (USD).
- For product 18781 (430 ₾, exchange-rate priced): compute through the plugin with the zone forced (find the plugin's API for "price for zone X" — e.g. `WCPBC_Pricing_Zones::get_zone_by_country( 'DE' )->get_post_price( 18781, '_price' )` — confirm names in source) → raw ≈ €148.31; on the storefront it must display **€149**. USD: ≈ $165.71 → **$169**.
- Real check from abroad (USER STEP): the owner opens a product page with a VPN set to Germany → EUR and …9 price; set to USA → USD; Georgia (no VPN) → GEL unchanged. Add to cart → cart and checkout totals match the shown prices. Do one checkout up to the payment step (do not pay).
- Manual price: set `_eurozone_regular_price = 200` on one test product (via Sheet Editor column) → Germany VPN shows €200 exactly (no rounding). Clear it → back to the converted …9 price.
- GEL prices on `/ka/` from Georgia: byte-for-byte unchanged (spot-check 5 products).
  Rollback: `deploy.py --target mu --rollback`; restore `wc_price_based_country_regions` from `snapshots/…phaseD.json` (take it **before** D.1: `snapshot.py save --label phaseD --option wc_price_based_country_regions`).
  **Commit:** `pricing(D): NBG daily rates, manual-first zone prices, round up to 9`.

---

## Phase E — Docs

- `DECISIONS.md`: entries for (1) catalog-visibility hiding of unpriced products, legacy items never auto-drafted; (2) NBG daily rates instead of buying the plugin's Pro; (3) …9 rounding at getter level, manual prices untouched. Update the open question "GeoIP routing" with the fact that foreign zones now have real rates.
- `PROJECT_BRIEF.md`: add `odella-pricing.php` to the architecture list.
  **Commit:** `pricing(E): docs`.
- Lastly: Generate the previous Roadmap 1 and This Roadmap 2 complete html test documentation which will show everything which was changed so that we can test them manually. It will be given to a non technical personal so be precise. Written as [manual-test-01-performance.html](manual-test-01-performance.html) and [manual-test-02-pricing.html](manual-test-02-pricing.html).  

## Notes for other roadmaps

- Roadmap 04 (checkout "some products cannot be purchased"): check first whether the products in those carts are among the 50 unpriced — after Phase A they can no longer be added, but **old carts/sessions may still contain them**. Expect this to be part of that bug.
