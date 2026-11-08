# Roadmap 04 — Cart & checkout: "cannot be purchased", wide-screen text clipping, +995 phone field, stale cart drawer

**For:** the Cursor agent implementing this. **Written:** 2026-11-08 from a grilling session; decisions are agreed with the owner — do not re-open them.
**Prerequisites:** Roadmap 01 Phase 0 (git, `tools/deploy.py` targets incl. `theme`, `tools/snapshot.py`, purge + smoke test). Roadmap 02 Phase A (unpriced products hidden) and roadmap 03 Phase 3A (translation map `Odella-Plugins/includes/i18n.php`) should be live first. Same SSH / `wp eval-file -` / Python-3.6 rules as roadmap 01.

## Do NOT change (owner decisions)
- **All payment methods stay disabled.** The site is live but must not take orders yet (bacs, cheque, cod, ppcp-gateway, ppcp-card-button-gateway are all `enabled=no` on purpose). The checkout notice "no payment method for your region" is expected. Record this in `DECISIONS.md`.
- **Shipping is not configured on purpose.** Zone "Local" (GE) has no methods; the owner's client wants Glovo/Wolt distance-based delivery with automatic order hand-off. That is an open question for a future roadmap — record it in `DECISIONS.md` → Open Questions. Do not add shipping methods.

---

## Facts (verified 2026-11-08)

| Problem | Cause |
|---|---|
| "Some products can't be bought" | 50 products have no price (roadmap 02). The Odella add-to-cart widget renders an active button for **any** product: `Odella-Plugins/includes/widgets/add-to-cart-widget.php` ~line 281 uses `$product->add_to_cart_url()` without checking `is_purchasable()`. Clicking it → WooCommerce refuses ("cannot be purchased"). |
| Product name cut off at the top in the cart drawer, only on big screens | Elementor Site Settings (kit `16`) global typography **"Body" `7778499`**: desktop `typography_line_height = {unit: "px", size: 1.6}` (should be `em`). Laptop/tablet/mobile values are `1.6em`. The desktop value applies from **1600 px and wider**, to body text and all links (`link_normal_typography` uses `7778499`), so on wide screens text has a ~2 px line box and overflows/clips. **Sitewide**, not only the drawer — very likely also the Terms/Privacy wide-screen bug (roadmap 05). |
| "+995" wraps under the flag, outside the field | Phone Validator with Flags (intl-tel-input 29.0.1) renders `.iti__selected-country` as a `<button>`. Hello Elementor's default button styles win: `display:inline-block; padding:8px 16px; border:1px solid #c36` (and pink hover). intl-tel-input expects `display:flex`, no padding/border. Field: `#billing_phone`, input `padding-left: 89px`, country container 85 px wide. |
| Cart drawer shows "0" / empty cart on cached pages although the cart has items | Modern Cart 1.1.1 prints the drawer and count into the page HTML, which LiteSpeed caches; it never refreshes on load (only after an add-to-cart). Its AJAX actions (`moderncart_refresh_slide_out_cart`, `moderncart_refresh_floating_cart`) need `moderncart_nonce` = `moderncart_ajax_object.ajax_nonce`, which is also baked into the cached HTML (expires after 12–24 h, cache TTL is 7 days). |
| English strings in the drawer on KA | "Your Cart Is Empty", "Check out our shop to see what's available.", "Your cart is empty. Shop now →", "Total", "Remove", "Edit Cart", "Somethings wrong! try again later". |

Checkout page: `15559` `/ka/elementor-15559/`, widget `5a7cbe0` (`woocommerce-checkout-page`). Billing fields are managed by WooCommerce Checkout Manager (`wooccm_billing`). Phone plugin options: `pvfwc_default_country=auto`, `pvfwc_country_source=wc`, `pvfwc_respect_wc_validation=yes`, `pvfwc_enable_billing=yes`.

---

## Phase 4A — Fix the global Body line height (sitewide, wide screens)

> **Owner already fixed this on 2026-11-08** in Site Settings → Global Fonts → Body. Do **only** the read-only check below plus step 3 (audit the other typographies) and the "Verify" list; skip steps 1–2 if `typography_line_height` of `7778499` is already `{unit: "em", size: 1.6}`.

1. `python tools\snapshot.py save --label 4A-kit --post 16`
2. `wp eval-file -`:
   ```php
   <?php
   $s = get_post_meta( 16, '_elementor_page_settings', true );
   $done = false;
   foreach ( $s['system_typography'] ?? [] as $i => $t ) {
       if ( ( $t['_id'] ?? '' ) === '7778499' ) {
           $s['system_typography'][ $i ]['typography_line_height'] = [ 'unit' => 'em', 'size' => 1.6, 'sizes' => [] ];
           $done = true;
       }
   }
   if ( ! $done ) {
       foreach ( $s['custom_typography'] ?? [] as $i => $t ) {
           if ( ( $t['_id'] ?? '' ) === '7778499' ) {
               $s['custom_typography'][ $i ]['typography_line_height'] = [ 'unit' => 'em', 'size' => 1.6, 'sizes' => [] ];
               $done = true;
           }
       }
   }
   if ( ! $done ) { WP_CLI::error( 'Typography 7778499 not found — nothing saved.' ); }
   update_post_meta( 16, '_elementor_page_settings', $s );
   \Elementor\Plugin::$instance->files_manager->clear_cache();
   WP_CLI::success( 'Body line-height desktop = 1.6em' );
   ```
   (Check first in which array — `system_typography` or `custom_typography` — the item lives; the 2026-11-08 read found it with `_id 7778499`, title "Body".)
3. **Audit the other global typographies** in the same kit for the same mistake: print every typography item's `typography_line_height*` values; any `unit: "px"` with `size < 5` is the same bug → fix to `em` the same way (list them in the slice notes before changing).
4. `python tools\deploy.py --purge-only`

Verify at **1920×1080 and 2560×1080** (and confirm nothing changed at 1440 and mobile):
- Cart drawer with 1–2 items: product names fully visible (the owner's screenshot case: "აბრეშუმის თავსაბურავი Argani").
- Body text and links on: Home, Store cards, product page description, Our Story `/ka/our-story/`, Privacy `/ka/privacy-policy-2/`, footer links. Lines no longer overlap.
- DevTools: computed `line-height` of `.elementor-kit-16 a` = `19.2px` at 12 px font (1.6em), not `1.6px`.
Rollback: `snapshot.py restore …4A-kit.json`.
**Commit:** `checkout(4A): body typography line-height px→em (wide screens)`.

---

## Phase 4B — Never offer "add to cart" for non-purchasable products

1. `Odella-Plugins/includes/widgets/add-to-cart-widget.php`: at the start of the render path, if `! $product->is_purchasable()` (no price, or otherwise not purchasable) **render nothing** (return before output) — on cards and on the product page. For variable products, keep the existing behaviour (variations decide purchasability) but if the parent has no purchasable variation, render nothing.
2. `Odella-Plugins/includes/widgets/product-price-widget.php`: if the product has no price, render nothing (no empty price box).
3. Quick View template (`load_quick_view_template()` in `includes/plugin.php`): same rule — no add-to-cart for non-purchasable products.
4. Existing carts: WooCommerce already drops non-purchasable items when a cart loads and shows its own notice. Add nothing; just confirm in verification.
5. Deploy `--target plugin`.

Verify (KA + EN, mobile + desktop):
- Direct URL of an unpriced product (e.g. 17550 Morbido): no price, **no add-to-cart button**, no quick-view add button.
- A priced simple product (18781 Cardigan Dream Bordeaux): button works, item appears in the drawer.
- The variable product 16128 "Pera": select a variation → add works.
- Store/category cards: every card that has a button has a price (unpriced ones are hidden by roadmap 02 anyway).
Rollback: `deploy.py --target plugin --rollback`.
**Commit:** `checkout(4B): hide add-to-cart for non-purchasable products`.

---

## Phase 4C — +995 phone field

### 4C.1 Layout (child theme CSS)
Add to `site/theme-child/style.css`, section "Checkout phone (intl-tel-input)":
```css
/* Hello Elementor's global <button> styles break intl-tel-input's country button */
.iti .iti__selected-country,
.iti .iti__selected-country:hover,
.iti .iti__selected-country:focus {
  display: flex;
  align-items: center;
  height: 100%;
  margin: 0;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: inherit;
  box-shadow: none;
  white-space: nowrap;
}
.iti .iti__selected-country-primary { padding: 0 6px 0 12px; }
.iti .iti__selected-dial-code { margin-left: 4px; color: var(--e-global-color-256943e); }
.iti .iti__selected-country:focus-visible { outline: 2px solid var(--e-global-color-9333735); outline-offset: -2px; }
```
Do not hard-code the input's `padding-left` — intl-tel-input sets it from the measured country-button width; after the fix, confirm it recalculates so typed digits never sit under "+995" (if not, call the plugin's `iti` instance `.setCountry()` once or add `padding-left: 96px !important` only as a last resort and note it).
Deploy `--target theme`.

### 4C.2 Behaviour
1. Default country Georgia: `wp option update pvfwc_default_country GE` (keep `pvfwc_country_source=wc`). Confirm "+995" + Georgian flag show for a visitor from abroad too.
2. Saved format: international E.164, e.g. `+995555123456`. Orders cannot be placed (payments off) and there is no staging site, so find out what the plugin submits by reading its source (`wp-content/plugins/phone-validator-with-flags-for-woocommerce/`): look for where it rewrites `billing_phone` on submit (a hidden field, JS that sets the full number, or a `woocommerce_checkout_posted_data` filter). If it does not already submit E.164, add a `woocommerce_checkout_posted_data` filter in `Odella-Plugins/includes/checkout.php` (new) that normalises `billing_phone` using the dial code the plugin posts.
3. Georgian mobile rule: when the selected country is GE, the national number must be **9 digits starting with 5** (`^5\d{8}$`). Add the check in `woocommerce_after_checkout_validation` (server-side) with a translated error (`nmj_t('phone_ge_invalid')`: KA "შეიყვანეთ სწორი მობილურის ნომერი: 9 ციფრი, იწყება 5-ით", EN "Enter a valid Georgian mobile number: 9 digits starting with 5"). Other countries: leave to the plugin's validation.
4. Because payments are disabled, a full order cannot be placed — verify validation by submitting the form and reading the **validation** errors (WooCommerce validates fields before it complains about payment): wrong GE number → our error; correct number → no phone error (only the payment notice remains).

### 4C.3 Verify
Mobile 390 px, desktop 1440, 2560: flag + arrow + "+995" on one line inside the field; no pink border/background on hover/focus; dropdown opens, search works, choosing another country updates the code; typed digits start after "+995". KA and EN.
Rollback: `deploy.py --target theme --rollback`, `--target plugin --rollback`, `wp option update pvfwc_default_country auto`.
**Commit:** `checkout(4C): phone field layout, GE default, E.164, GE mobile rule`.

---

## Phase 4D — Cart drawer: always fresh, Georgian texts

### 4D.1 Fresh drawer on every page load
In the Odella plugin (`Odella-Plugins/includes/cart-refresh.php` new + `assets/js/cart-refresh.js`, enqueued on all frontend pages after `modern-cart`'s script; skip in Elementor editor/preview):
1. **Fresh nonce endpoint:** REST route `GET /wp-json/odella/v1/cart-nonce` → `{ "nonce": wp_create_nonce( 'moderncart_ajax_nonce' ) }` with headers `Cache-Control: no-store, private` and `do_action( 'litespeed_control_set_nocache', 'odella cart nonce' )`. `permission_callback` `__return_true` (it only returns a nonce for the visitor's own session). WooCommerce must load the session for REST: call `WC()->initialize_session()` if needed before creating the nonce (guest nonces are tied to the Woo session — verify by calling the endpoint from a browser that has items, then using the nonce).
2. **JS on load:** if `document.cookie` contains `woocommerce_items_in_cart=1` (or the count badge differs from the cookie state):
   - fetch the nonce; set `window.moderncart_ajax_object.ajax_nonce = nonce`;
   - POST `action=moderncart_refresh_slide_out_cart` and `action=moderncart_refresh_floating_cart` (both with `moderncart_nonce`) to `moderncart_ajax_object.ajax_url`; replace `#moderncart-slide-out` and the floating button/count with the returned `content` (inspect each response shape — the slide-out returns `{content: "<div id=\"moderncart-slide-out\" …"}`).
   - Also run the same refresh when the page is restored from bfcache (`pageshow` with `persisted`).
   - If the cookie says the cart is empty and the badge shows a number, refresh too (removes stale counts).
3. Any later Modern Cart AJAX must use the fresh nonce (it reads `moderncart_ajax_object.ajax_nonce` at call time — confirm in `wp-content/plugins/modern-cart/assets/js/cart.js`; if it caches the value at init, re-initialise or patch via the object before first use).
4. Deploy `--target plugin`.

### 4D.2 Georgian texts
1. First check Modern Cart's own settings (wp-admin → Modern Cart → Settings/Texts): set the KA wording there if it supports per-language text (Polylang string translation may list them under "Modern Cart"). If it does, use Polylang → Strings translations for KA/EN.
2. Otherwise add to the roadmap-03 map and translate via `gettext` filter for domain `modern-cart` (find the text domain in the plugin headers), only for these strings:
   | EN | KA |
   |---|---|
   | Your Cart Is Empty | თქვენი კალათა ცარიელია |
   | Check out our shop to see what's available. | ნახეთ ჩვენი მაღაზია. |
   | Your cart is empty. Shop now | კალათა ცარიელია. დაიწყეთ შოპინგი |
   | Total | ჯამი |
   | Remove | წაშლა |
   | Edit Cart | კალათის რედაქტირება |
   | Your Cart | თქვენი კალათა |
   | Checkout / Proceed to checkout | გაფორმება |
   | Somethings wrong! try again later | რაღაც შეცდომაა, სცადეთ თავიდან |
   **Ask the owner to proof-read the KA column before deploying.** The localized `general_error` / `edit_cart_text` in `moderncart_ajax_object` come from PHP — translate them through the same filter or `wp_localize_script` data filter.

### 4D.3 Verify
- Add an item on a product page → go to Store (cached page) → badge shows 1 immediately on load; open drawer → item listed with full name (4A) and Georgian texts. Reload → still correct. Remove item in the drawer → works (fresh nonce). Repeat after ~25 h on a page cached the day before (owner check, or simulate by fetching a page with an old `ajax_nonce`: call the refresh with a deliberately wrong nonce → the script must recover by fetching a fresh one).
- Empty cart: drawer shows the Georgian empty state on KA, English on EN.
- Mobile + desktop + 2560.
Rollback: `deploy.py --target plugin --rollback`.
**Commit:** `checkout(4D): fresh cart drawer on cached pages, KA texts`.

---

## Phase 4E — Docs
- `DECISIONS.md`:
  - Decision: payments disabled by design while the site is live; do not re-enable without the owner.
  - Open question: delivery — Glovo/Wolt distance-based fee + automatic order hand-off (owner to confirm with client); shipping zones intentionally empty until then.
  - Gotcha: Body typography `7778499` desktop line-height was `1.6px` → text clipped ≥ 1600 px; fixed to `1.6em`.
  - Gotcha: Hello Elementor `<button>` defaults override third-party buttons (intl-tel-input); reset scoped in child theme.
  - Gotcha: Modern Cart drawer HTML + nonce are cached by LiteSpeed; refreshed client-side with `/wp-json/odella/v1/cart-nonce`.
**Commit:** `checkout(4E): docs`.
