# Roadmap 03 — Shop UX: new AJAX filter (replaces WOOF), Load More, Related Products, KA translations

**For:** the Cursor agent implementing this. **Written:** 2026-11-08 from a grilling session; decisions are agreed with the owner — do not re-open them.
**Prerequisites:** Roadmap 01 Phase 0 (git, `tools/deploy.py` targets, `tools/snapshot.py`, purge + smoke test) and roadmap 01 Phase 4 (page transition — the filter must not trigger it). Roadmap 02 Phase A (unpriced products hidden) should be live, so counts and Related Products are right.
**Plugin work follows** `.claude/skills/odella-plugin-deploy/SKILL.md`: edit in `Odella-Plugins/`, deploy with `python tools\deploy.py --target plugin`. Same SSH / `wp eval-file -` / Python-3.6 rules as roadmap 01.

---

## Facts (verified 2026-11-08)

| Thing | Where |
|---|---|
| Store page | `16217` `/ka/store/` (WooCommerce shop page; KA only) |
| Archive template (Store + all category pages) | Elementor `15580`, condition `include/product_archive` |
| Loop Grid in it | element `c490a4e`, card template `14786`, 24 per page, 4 cols / 2 mobile, `pagination_type = load_more_on_click`, `text = "Load More"`, `load_more_no_posts_custom_message = "No more posts to show"`, `nothing_found_message_text = "It seems we can’t find what you’re looking for."` |
| WOOF shortcode in archive | element `816677c`: `[woof_slideout image=…Filter-Hover.svg …]` |
| WOOF also in | popup `16255` "Filter Popup - GE" (condition `include/woocommerce`), sidebar widget `sidebar-1: woof_widget-2` |
| WOOF asset handling | `site/mu-plugins/odella-performance.php` section 4 `odella_is_catalog_filter_page()` and section 10 (dequeue on non-catalog pages) |
| Single product template | `15154`; Related widget `11ace090` (`woocommerce-product-related`, 3 per page, 3 cols / 1 mobile); its heading `7bc6c2db` "Related Products" |
| Odella widgets with English strings | `nmj_product_price` `47db2a3` "Out of Stock"; `nmj_variation_selector` `b7afd24` "Clear"; `nmj_add_to_cart` `e768832` "Select option", "Quick View" |
| Category permalinks | `/ka/product-category/<parent>/<child>/`; product base `/product/%product_cat%/` (product URLs contain the category path) |
| Stray category | term `684` "აბრეშუმის თავსაბურავი" (Headscarf, KA, 6 products), top level, URL-encoded Georgian slug |
| Scarf category (KA) | term `77` `scarf-women` (parent `66` women) |
| Default catalog order | `woocommerce_default_catalog_orderby = popularity` (all products have 0 sales → effectively random) |
| Attributes in use | none useful (no colour/tag/brand; size only on 3 scarf sizes) |

## Agreed behaviour (summary)

- **Filter** = new Elementor widget **"Odella Product Filter"** (`nmj_product_filter`) in the Odella plugin, replacing WOOF entirely.
  - Contents: full category tree (top level first, each expandable; current category highlighted and opened), search box, sort control. **No price slider.**
  - Selecting a category **does not reload the page**: AJAX fetches that category's page, swaps in the product grid, and updates the address bar to the **real category URL**. Search = `?q=`, sort = `?orderby=`. Back/forward work. Hovering a category prefetches it.
  - Search looks **inside the current category**; with no results, show a "Search the whole store" link.
  - Sort: **Newest (default)**, Price low → high, Price high → low.
  - Empty categories hidden by default; widget toggle "Show empty categories" (default **off**). Counts shown (grey, small).
  - Desktop: "Filter" button above the grid next to the product count opens a 360 px panel from the left. Mobile: bottom sheet, 85 % height. Rows: hover scale 1.03, OdellaGreen border always present, expand/collapse 200 ms, tap targets ≥ 44 px. Floating left-edge icon removed.
  - Active state: button reads "Filter · <category> ✕" (✕ → back to Store); active search/sort shown as removable chips above the grid.
  - Old `/<lang>/store/swoof/product_cat-<slug>/` URLs → 301 to the category URL.
  - WOOF retirement: remove shortcode, unpublish popup `16255` (snapshot first), remove sidebar widget, deactivate WOOF, remove WOOF code from the mu-plugin, **keep WOOF installed 2 weeks**, then delete.
- **Load More**: KA "მეტის ნახვა", EN "Load More". When nothing is left, the button is **hidden** — no "no more products" message. Outlined pill, green border/text, fills green with white text on hover, Noto Sans, small spinner while loading. Progress line "ნაჩვენებია 24 / 161" / "Showing 24 of 161" under it (toggle, default on), hidden together with the button.
- **Related Products**: same most-specific category first; top up from its parent category only; never other categories; no unpriced products; **4 desktop / 2 mobile**; heading "მსგავსი პროდუქტები" / "You may also like".
- **Headscarf** → Women › Scarf › Headscarf, slug `headscarf-women`, with 301s.
- **Translation sweep**: all English shop UI strings on KA pages translated through one map in the Odella plugin.

---

## Phase 3A — Translation map + Load More (quick wins)

### 3A.1 Translation map — `Odella-Plugins/includes/i18n.php` (new), loaded from `includes/plugin.php`
- `function nmj_t( string $key ): string` returning the string for `pll_current_language()` (fallback `ka`), from one array:
  ```php
  'load_more'        => [ 'ka' => 'მეტის ნახვა',              'en' => 'Load More' ],
  'showing_x_of_y'   => [ 'ka' => 'ნაჩვენებია %1$d / %2$d',     'en' => 'Showing %1$d of %2$d' ],
  'nothing_found'    => [ 'ka' => 'პროდუქტი ვერ მოიძებნა',       'en' => 'No products found' ],
  'search_all_store' => [ 'ka' => 'მთელ მაღაზიაში ძებნა',        'en' => 'Search the whole store' ],
  'related_heading'  => [ 'ka' => 'მსგავსი პროდუქტები',         'en' => 'You may also like' ],
  'out_of_stock'     => [ 'ka' => 'მარაგში არ არის',             'en' => 'Out of Stock' ],
  'clear'            => [ 'ka' => 'გასუფთავება',                 'en' => 'Clear' ],
  'select_option'    => [ 'ka' => 'აირჩიეთ ვარიანტი',            'en' => 'Select option' ],
  'quick_view'       => [ 'ka' => 'სწრაფი ნახვა',                'en' => 'Quick View' ],
  'filter'           => [ 'ka' => 'ფილტრი',                     'en' => 'Filter' ],
  'search_ph'        => [ 'ka' => 'ძებნა…',                     'en' => 'Search…' ],
  'sort_newest'      => [ 'ka' => 'უახლესი',                    'en' => 'Newest' ],
  'sort_price_asc'   => [ 'ka' => 'ფასი: ზრდადობით',             'en' => 'Price: low to high' ],
  'sort_price_desc'  => [ 'ka' => 'ფასი: კლებადობით',            'en' => 'Price: high to low' ],
  'all_products'     => [ 'ka' => 'ყველა პროდუქტი',              'en' => 'All products' ],
  'products_count'   => [ 'ka' => '%d პროდუქტი',                 'en' => '%d products' ],
  ```
  **Ask the owner to proof-read the Georgian column before deploying** (show it as a table).
- Odella widgets (`nmj_product_price`, `nmj_variation_selector`, `nmj_add_to_cart`): where a text setting is empty **or equals the English default**, render `nmj_t(...)` instead. Change in each widget's `render()`; do not change saved Elementor data.
- Elementor core widgets (Loop Grid button, Related heading): filter `elementor/widget/render_content` and replace by **element ID** (not by text match), only on KA:
  - `c490a4e`: button label → `nmj_t('load_more')`; nothing-found text → `nmj_t('nothing_found')`.
  - `7bc6c2db`: heading text → `nmj_t('related_heading')`.
- `60d003d3` (product title widget) has a leftover setting "Add Your Heading Text Here": open a product page and check whether it is ever visible. If not visible, leave it; note it in the slice notes.

### 3A.2 Load More behaviour and style
1. `python tools\snapshot.py save --label 3A-archive --post 15580`
2. In `c490a4e` settings (via `wp eval-file -`, walk pattern from roadmap 01 §1.2): set `load_more_no_posts_custom_message` to `""` and turn off the "no more posts" message switcher (find the exact key in the saved settings or in Elementor Pro's `modules/loop-builder` source, e.g. `load_more_no_posts_message_switcher`). Verify on the last page the button disappears and no message shows.
3. Style in `site/theme-child/style.css` (scoped to `.elementor-element-c490a4e .e-loop__load-more .elementor-button`):
   - `background: transparent; color: var(--e-global-color-9333735); border: 1.5px solid var(--e-global-color-9333735); border-radius: 999px; padding: 12px 32px; font-family: "Noto Sans", sans-serif; font-weight: 500; transition: background-color .2s, color .2s;`
   - `:hover, :focus-visible` → `background: var(--e-global-color-9333735); color: #fff;`
   - loading state (Elementor adds a loading class/spinner; inspect the DOM while loading and style its spinner in OdellaGreen, 16 px, left of the label).
   Deploy `--target theme`.
4. Progress line: implemented in Phase 3D's JS (it knows the total); until then skip.

### 3A.3 Verify
KA + EN, mobile + desktop: Store and one category: button text right, style right, hover right; click until the end → button vanishes, no message. Product page: "Out of Stock" (test on a product set temporarily out of stock — restore after), "Clear", "Select option", "Quick View" in Georgian on KA, English on EN.
Rollback: `deploy.py --target plugin --rollback`, `--target theme --rollback`, `snapshot.py restore …3A-archive.json`.
**Commit:** `shop(3A): KA translation map, Load More style and behaviour`.

---

## Phase 3B — Related Products: same category only

1. `Odella-Plugins/includes/related-products.php` (new):
   - `add_filter( 'woocommerce_product_related_posts_relate_by_tag', '__return_false' );`
   - `add_filter( 'woocommerce_related_products', function ( $ids, $product_id, $args ) { … }, 20, 3 );` replacing the list:
     1. Find the product's **deepest** `product_cat` term (most ancestors; ties → lowest term_order / first).
     2. Query published, in-stock, **visible** (not `exclude-from-catalog`), **priced** products in that term (no children), excluding the product itself, random order, limit = `$args['limit']` (or 4).
     3. If fewer than the limit: top up from the **parent** term (include children of the parent, excluding already chosen). Stop there — never go higher.
     4. Return IDs (cache per product for 6 hours in a transient keyed by product ID + language; clear on `save_post_product`).
2. Widget `11ace090`: `posts_per_page` 4, `columns` 4, `columns_mobile` 2 (snapshot `15154` first; edit via `wp eval-file -`).
3. Deploy `--target plugin`, purge.

Verify: long kimono → 4 long kimonos; short kimono (14 in category) → 4 short; a product in a small category (beach-bag-women, 5) → 4 from beach bags; a category with only 1–2 siblings → topped up from parent only. Mobile 2 per row, desktop 4. No unpriced ones.
Rollback: plugin rollback + snapshot restore.
**Commit:** `shop(3B): related products from same category`.

---

## Phase 3C — Move Headscarf under Women › Scarf

1. Record old URLs: category URL of term `684` and the 6 product URLs (`wp eval-file -` printing `get_term_link(684,'product_cat')` and `get_permalink()` of each product in it). Save to `snapshots/<date>-headscarf-old-urls.json`.
2. `wp term update product_cat 684 --parent=77 --slug=headscarf-women`
3. Print the new URLs. Add 301 redirects old → new (category + 6 products) in Rank Math → Redirections (module must be on: `wp eval "echo json_encode(get_option('rank_math_modules'));"` — if `redirections` is missing, enable it in Rank Math → Dashboard, USER STEP if it needs the UI). Alternative if Rank Math redirections is unavailable: add the 7 pairs to a `$odella_redirects` array handled on `template_redirect` in `Odella-Plugins/includes/filter-query.php` (Phase 3D creates this file; create it now).
4. Purge. Verify: old URLs → 301 → new; new URLs 200; Women › Scarf in the filter (after 3D) shows Headscarf; breadcrumb on a headscarf product shows Women › Scarf › Headscarf.
Rollback: `wp term update product_cat 684 --parent=0 --slug=<old slug from JSON>` and remove the redirects.
**Commit:** `shop(3C): headscarf under women/scarf with redirects`.

---

## Phase 3D — "Odella Product Filter" widget (build and test before placing)

### 3D.1 Files
- `Odella-Plugins/includes/widgets/product-filter-widget.php` — class `\NMJ\Widgets\Product_Filter_Widget extends \Elementor\Widget_Base`, `get_name() = 'nmj_product_filter'`, title "Odella Product Filter", category `nmj-widgets`, `get_script_depends() = ['nmj-product-filter']`, `get_style_depends() = ['nmj-product-filter']`.
- Register in `includes/plugin.php`: add `'product-filter-widget' => \NMJ\Widgets\Product_Filter_Widget::class` to `$widgets`; register `nmj-product-filter` CSS/JS in `enqueue_frontend_assets()` and the CSS in `enqueue_editor_assets()` (same pattern as existing widgets). JS deps: `['elementor-frontend']`, in footer.
- `Odella-Plugins/assets/js/product-filter.js`, `Odella-Plugins/assets/css/product-filter.css`.
- `Odella-Plugins/includes/filter-query.php` — query handling + redirects (below).

### 3D.2 Widget controls
Content tab:
- `show_empty` switcher, default **off** ("Show empty categories")
- `show_counts` switcher, default on
- `show_search` switcher, default on
- `show_sort` switcher, default on
- `show_progress` switcher, default on ("Show 'Showing X of Y' under Load More")
- `button_text_ka` default `ფილტრი`, `button_text_en` default `Filter`
- `expand_level` select: `current` (default: open the branch of the current category), `1`, `all`
- `grid_element_id` text, default `c490a4e` (the Loop Grid to swap; documented in the control description)
Style tab (selectors on the widget wrapper; defaults from Odella globals):
- colours: text, active text, border, active background, panel background, overlay
- typography: rows, counts, button
- border width (default 1px), radius (default 10px)
- hover scale (number, default 1.03)
- panel width desktop (default 360px), sheet height mobile (default 85vh)
- row height desktop (default 40px) and mobile (default 48px; never below 44)

### 3D.3 Server-rendered markup (works without JS)
- Button: `<button class="nmj-pf-toggle" aria-expanded="false" aria-controls="nmj-pf-panel-<id>">` label = button text, plus `· <current term name>` and a `<a class="nmj-pf-clear" href="<store URL>">✕</a>` when a category is active. Next to it: product count `nmj_t('products_count')` using `$wp_query->found_posts`.
- Panel (`role="dialog" aria-modal="true"`): search form (`GET` to current archive URL, input `name="q"`), sort `<select name="orderby">` (`date`, `price`, `price-desc`), category tree as nested `<ul>` of **real links** (`get_term_link`) for the current language (`get_terms( [ 'taxonomy' => 'product_cat', 'lang' => pll_current_language(), 'hide_empty' => false ] )`), "All products" link to the Store page (Polylang translation of `16217` if it exists, else `wc_get_page_permalink('shop')`).
- Counts: use WooCommerce's visibility-aware term meta `product_count_product_cat` (`get_term_meta( $id, 'product_count_product_cat', true )`), falling back to `$term->count`. With `show_empty` off, drop terms whose count (including descendants) is 0.
- Current term (`is_product_category()` → queried object) gets `aria-current="page"` and its ancestors are opened per `expand_level`.
- Chips above the grid: active search (`q`) and non-default sort, each a link that removes that param.
- `data-total="<found_posts>"`, `data-per-page="24"` on the wrapper (progress line).

### 3D.4 `filter-query.php`
1. **Search inside category:** `pre_get_posts` (frontend, main query, `is_shop() || is_product_category()`), if `$_GET['q']` non-empty: `$q->set( 's', sanitize_text_field( wp_unslash( $_GET['q'] ) ) )`. Because `is_search` is already decided at parse time, the archive template stays. Also match SKU exactly: `posts_search` filter adding `OR {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value = %s)` for that query only.
2. **Sort:** WooCommerce already handles `?orderby=date|price|price-desc` on the main archive query. Set the default: `wp option update woocommerce_default_catalog_orderby date`.
3. **Old WOOF URLs:** on `template_redirect` priority 1 (runs on 404s too), if the request path matches `#^/(ka|en)/store/swoof/(.*)$#`: take the first `product_cat-<slug>` segment (comma lists → first slug), find the term in that language (`get_terms` by slug + `lang`), `wp_safe_redirect( get_term_link(...), 301 )`; no category segment → 301 to the Store URL. Keep `q`/`orderby` if present; drop other WOOF params.

### 3D.5 JS behaviour (`product-filter.js`, no jQuery needed)
- Init on `elementor/frontend/init` → `elementorFrontend.hooks.addAction('frontend/element_ready/nmj_product_filter.default', init)`.
- Panel: open/close with the button; Esc closes; click on overlay closes; focus trapped inside while open, returned to the button on close; body scroll locked while open. Desktop slides in from left (transform translateX, 250 ms); mobile (`max-width: 767px`) slides up as a bottom sheet with a drag handle (drag down > 80 px closes).
- Tree: chevron buttons toggle sub-lists (`aria-expanded`), height animation 200 ms (`grid-template-rows: 0fr → 1fr` trick); row hover `transform: scale(var(--nmj-pf-hover-scale))` with `transform-origin: left center`.
- **Navigation (category link click, sort change, search submit, chip click, ✕ click):**
  1. `e.preventDefault()` (this also stops the roadmap-01 page transition, which skips default-prevented clicks).
  2. Build the target URL (category link href + current `q`/`orderby` as appropriate; search submit keeps the current category).
  3. Show a loading state on the grid (opacity .5 + spinner), close the panel on mobile.
  4. `fetch(url, { credentials: 'same-origin' })` (served from LiteSpeed cache when warm). On non-200 or network error → `location.href = url` (full navigation fallback).
  5. Parse with `DOMParser`; replace these nodes from the response, matched by `data-id`/class: the Loop Grid element (`.elementor-element-<grid_element_id>`), this filter widget (so counts/active state/chips match), and any archive title/breadcrumb elements present in template 15580 (list them in code after inspecting the template). Update `document.title`, `<link rel="canonical">` and the `hreflang` alternates from the response.
  6. Re-initialise Elementor handlers on the new nodes: `elementorFrontend.elementsHandler.runReadyTrigger( jQuery(newNode) )` for the Loop Grid and the filter, so Load More keeps working. Re-check add-to-cart buttons and the Modern Cart drawer still work on swapped cards (they should use delegated events; if not, trigger `jQuery(document.body).trigger('post-load')` and fix).
  7. `history.pushState({ nmjPf: true }, '', url)`; on `popstate` repeat steps 3–6 for `location.href` without pushing.
  8. Scroll to the top of the grid (smooth) unless it is already in view.
- **Prefetch:** on `pointerenter` of a category link (desktop) or `touchstart` (mobile), after 80 ms, `fetch` the URL and keep the response text in a small in-memory cache (max 6 entries, 60 s). Navigation uses the cache when present.
- **Progress line:** under the Loop Grid's Load More button insert `<p class="nmj-pf-progress">` with `nmj_t('showing_x_of_y')` (pass the KA/EN template via `wp_localize_script`), count = number of `.e-loop-item` in the grid, total = `data-total`. Update via a `MutationObserver` on the grid. Hide it when the Load More button is hidden/removed. Respect the `show_progress` toggle.
- No results: if the response grid has no items and `q` is set, show `nmj_t('nothing_found')` + link `nmj_t('search_all_store')` → Store URL with the same `q`.

### 3D.6 Test page first
An archive template cannot be tested as a draft, so test on a private page instead: create Elementor page "TEST filter" (status Private), add a Loop Grid (same card template 14786, query: products, 24) and the new widget with `grid_element_id` set to that Loop Grid's ID. Exercise all behaviour there (category switching updates the URL only on archives; on the test page just confirm panel, tree, styles, prefetch, swap and Load More re-init). Delete the test page after Phase 3E passes.

Deploy `--target plugin` (widget is unused on live pages until 3E, so this is safe).
**Commit:** `shop(3D): Odella Product Filter widget`.

---

## Phase 3E — Place the widget, retire WOOF

1. Snapshots: `snapshot.py save --label 3E --post 15580 --post 16255 --option woof_settings --option sidebars_widgets --option woocommerce_default_catalog_orderby`.
2. In template `15580`: replace shortcode element `816677c` with the `nmj_product_filter` widget, placed above the Loop Grid (left-aligned row with the product count), `grid_element_id = c490a4e`. Do this in the Elementor editor (USER STEP if the agent cannot use the editor) or via `wp eval-file -` inserting a widget element `{ id: <new 7-char hex>, elType: 'widget', widgetType: 'nmj_product_filter', settings: {} }` in the same container where `816677c` was, then remove `816677c`. Clear Elementor cache.
3. Unpublish popup `16255`: `wp post update 16255 --post_status=draft`.
4. Remove sidebar widget: remove `woof_widget-2` from `sidebar-1` in `sidebars_widgets` via `wp eval-file -`.
5. `wp option update woocommerce_default_catalog_orderby date`
6. Remove WOOF handling from `site/mu-plugins/odella-performance.php`: section 4 (`odella_is_catalog_filter_page`), section 10 (WOOF unhook/dequeue), and the WOOF/chosen/icheck lines in `odella_dequeue_unused_frontend_assets()`; keep everything else. Bump version. Deploy `--target mu`.
7. Deactivate WOOF: `wp plugin deactivate woocommerce-products-filter`. Record the date in `DECISIONS.md` ("delete after 2026-11-<+14 days>").
8. Purge (`deploy.py --purge-only`), then the owner runs `tools\warm_cache.py --only pages`.

### 3E.1 Verify (KA + EN where it exists; mobile, desktop, ultra-wide)
- `/ka/store/`: Filter button + count; panel shows full tree with top-level categories first, empty ones hidden; Women expandable; counts correct (match the grid's total after choosing).
- Choose Women › Kimono › Long kimono: grid changes without full reload (DevTools Network shows one `fetch` document request, no navigation), URL = `/ka/product-category/women/kimono-women/long-kimono-women/`, title updates, button shows "ფილტრი · …" with ✕. Reload that URL directly → same result server-rendered.
- Back button returns to the previous selection; forward again.
- Sort Price low → high: order correct, URL has `?orderby=price`, chip shows; remove chip → default.
- Search "Sakura" in Women: results only from Women; nonsense search → message + "Search the whole store" link works.
- Load More after a swap still loads the next 24; progress line updates and hides with the button.
- Add to cart from a swapped card works; cart drawer opens.
- `/ka/store/swoof/product_cat-coat-women/` → 301 → `/ka/product-category/women/coat-women/`.
- Toggle "Show empty categories" on in the editor → Men etc. appear; switch back off.
- Mobile: bottom sheet, 85 % height, drag-to-close, rows ≥ 44 px, no horizontal scroll; desktop hover scale and borders never disappear (check rows at all states: normal, hover, active, focus).
- No WOOF assets in page source (`woocommerce-products-filter` count = 0). Store page HTML smaller than before (record numbers).
- Keyboard: Tab to button, Enter opens, Tab cycles inside, Esc closes.
Rollback: `snapshot.py restore …3E.json` (template, popup, sidebar, options), `wp plugin activate woocommerce-products-filter`, `deploy.py --target mu --rollback`.
**Commit:** `shop(3E): new filter live, WOOF retired`.

---

## Phase 3F — Docs and cleanup
- `DECISIONS.md`: entries for (1) custom AJAX filter replacing WOOF, category URLs as the only filter URLs, `?q=`/`?orderby=` params; (2) Related Products rule; (3) KA translation map location. Remove the WOOF trade-off note from the 2026-11-07 mu-plugin entry.
- `PROJECT_BRIEF.md` §3: replace "Product Filters: WOOF" with "Odella Product Filter widget (`nmj_product_filter`)"; §4: add the filter widget.
- Calendar note for the owner: on 2026-11-22 delete WOOF (`wp plugin delete woocommerce-products-filter`) if no rollback was needed; also delete popup `16255` then.
- Delete the "TEST filter" page.
**Commit:** `shop(3F): docs`.
