# Roadmap 05 — Cart page, My Account, page titles, duplicate drafts

**For:** the Cursor agent implementing this. **Written:** 2026-11-08 from a grilling session; decisions are agreed with the owner — do not re-open them.
**Prerequisites:** Roadmap 01 Phase 0 (git, `tools/deploy.py --target theme`, `tools/snapshot.py`, purge + smoke test). Roadmap 04 Phase 4A (Body line-height fix) and 4D (fresh cart drawer) should be live. Same SSH / `wp eval-file -` / Python-3.6 rules as roadmap 01.

## Scope decisions
- **Georgian only.** English pages stay as they are (drafts, Polylang EN inactive) — translation happens after the Georgian site is finished. Do not publish or edit EN pages (`14598`, `14602`, …).
- **Terms (`5748`) and Privacy (`4081`) are already fixed by the owner** (a `1.6px` → `1.6em` line-height setting, same bug family as roadmap 04 §4A). Do not redesign them. Only Phase 5A (page titles) touches them — verify they still look right afterwards.
- Payments stay disabled (roadmap 04) — the cart page will show the cart, the checkout button leads to the checkout that cannot be paid; that is expected.

## Facts (verified 2026-11-08)

| Page | ID | URL | State |
|---|---|---|---|
| Cart (WooCommerce cart page) | `4083` | `/ka/cart/` | **draft → 404**; `woocommerce_cart_page_id = 4083`; content `[woocommerce_cart]`; default template |
| Duplicate Cart drafts | `5086` (KA), `14598` (EN — leave) | | draft |
| My Account (WooCommerce page) | `4085` | `/ka/my-account-2/` | published; Elementor: container `6cd18d54` → text-editor `13e89522` containing the `[woocommerce_my_account]` output; Hello page title "My account" (English) |
| Duplicate My Account draft | `5088` | | draft |
| Duplicate legal drafts | `5759` Privacy, `5768` Terms | | draft |
| Refund policy drafts | `4086`, `14595` | | draft — **keep** (future policy) |
| Registration | `woocommerce_enable_myaccount_registration = no` | | |
| Checkout page (style reference) | `15559`, widget `5a7cbe0` | | |
| Footer menu "Footer 2 - GE" | "ჩემი პროფილი" → `/ka/my-account-2/` | | OK |

The "View cart / კალათის ნახვა" link in WooCommerce's add-to-cart notice and Modern Cart's cart link point at the cart page, so today they 404.

---

## Phase 5A — Turn off Hello's automatic page titles sitewide

1. Before changing anything, list every **published page** and whether its content has its own heading, so no page ends up without a title:
   `wp eval-file -` → for each published `page`: ID, title, slug, `_wp_page_template`, whether `_elementor_data` (or `post_content`) contains a heading widget / `<h1>`/`<h2>`. Save the list in the slice notes.
2. Child theme `site/theme-child/functions.php`:
   ```php
   // Pages show their own Georgian headings; Hello's automatic (often English) title is not used.
   add_filter( 'hello_elementor_page_title', '__return_false' );
   ```
3. For any page from step 1 **without** its own heading (expected: Cart `4083` and My Account `4085` — handled in 5B/5C), add a Georgian heading in that phase. For any other page without a heading, list it and ask the owner what title it should show.
4. Deploy `python tools\deploy.py --target theme`.

Verify (desktop 1440, 2560, mobile; KA): Terms `/ka/terms-conditions-2/` and Privacy `/ka/privacy-policy-2/` show only their Georgian heading, no English "Terms & Conditions"; spacing at the top still looks right (no header overlap). Home, Store, product, Our Story unchanged (they use Elementor templates).
Rollback: `deploy.py --target theme --rollback`.
**Commit:** `pages(5A): disable Hello page titles`.

---

## Phase 5B — A real Cart page

1. `python tools\snapshot.py save --label 5B-cart --post 4083 --post 15559`
2. Trash the duplicate first so the slug `cart` is free for `4083`: `wp post delete 5086` (no `--force` → goes to Trash).
3. Build `4083` in Elementor (USER STEP if the agent cannot drive the editor; otherwise insert via `wp eval-file -` and then open it in the editor to check):
   - Template: "Elementor Full Width" (`elementor_header_footer`, same as checkout `15559`).
   - Container (boxed, max width 1400 px — the same width the checkout uses on wide screens; read it from `15559`'s container settings and copy).
   - Heading widget: **"კალათა"** (H1), styled like the checkout page heading.
   - Elementor Pro **Cart** widget (`woocommerce-cart`): layout two columns (items left, totals right) on desktop, one column on mobile; "Update cart automatically" on; coupon **off** (no coupons in use); cross-sells off. Copy colours, typography, button styles and borders from checkout widget `5a7cbe0` (same globals: OdellaGreen buttons, OdellaSilver text, Noto Sans).
   - Empty-cart state: Georgian text from WooCommerce's KA translation (check it renders Georgian; if not, add to the roadmap-03 translation map) and a button **"მაღაზიაში დაბრუნება"** → `/ka/store/`.
   - Proceed button text: "გაფორმება" (Georgian), linking to the checkout page.
4. Publish: `wp post update 4083 --post_status=publish`. Confirm permalink `https://odella.ge/ka/cart/`.
5. Caching: confirm LiteSpeed does **not** cache it — response header `X-LiteSpeed-Cache-Control: no-cache` (WooCommerce marks cart as non-cacheable). If it is cached, add `/ka/cart/` to LiteSpeed → Cache → Excludes → URIs (`litespeed.conf.cache-exc`) and purge. Speculative prefetch already excludes `/ka/cart/*` (roadmap 01 §4.1).
6. Links: the WooCommerce notice "კალათის ნახვა" and Modern Cart's cart/"Edit Cart" link must open `/ka/cart/` (they use `wc_get_cart_url()`; just verify). Roadmap 01's page transition should apply to these links (they are normal navigations).
7. Purge (`deploy.py --purge-only`).

Verify (mobile 390, desktop 1440, 2560):
- Add a priced product (e.g. 18781) → notice "კალათის ნახვა" → `/ka/cart/` 200, shows the item with image, name (fully visible — roadmap 04 §4A), price, quantity.
- Change quantity → totals update without a page reload; remove item → empty state with the Georgian text and the store button.
- "გაფორმება" → checkout page loads with the same items.
- Cart drawer and cart page stay in sync (change in one, reload the other).
Rollback: `snapshot.py restore …5B-cart.json` (restores content/status), `wp post update 4083 --post_status=draft`; restore `5086` from Trash with `wp post update 5086 --post_status=draft` if needed.
**Commit:** `pages(5B): published cart page`.

---

## Phase 5C — My Account: styled, with registration

1. `python tools\snapshot.py save --label 5C-account --post 4085 --option woocommerce_enable_myaccount_registration --option woocommerce_registration_generate_password --option woocommerce_registration_generate_username`
2. Registration on, email-only, password set by link:
   ```
   wp option update woocommerce_enable_myaccount_registration yes
   wp option update woocommerce_registration_generate_username yes
   wp option update woocommerce_registration_generate_password yes
   ```
3. Rebuild `4085` content in Elementor: replace text-editor `13e89522` with:
   - Heading **"ჩემი პროფილი"** (H1), styled like the cart/checkout headings.
   - Elementor Pro **My Account** widget (`woocommerce-my-account`): tabs on the left on desktop, on top (horizontal scroll) on mobile; styles copied from checkout widget `5a7cbe0` (inputs, buttons, borders, typography, OdellaGreen accents).
   - Logged-out view: Elementor's widget shows WooCommerce's login + (now enabled) register forms side by side on desktop, stacked on mobile. Style both forms the same way (if the widget does not expose controls for the logged-out forms, style them in `site/theme-child/style.css` under a `.page-id-4085` scope).
   - Same container width as cart/checkout.
4. Remove tabs that make no sense here — Odella plugin, `Odella-Plugins/includes/account.php` (new):
   ```php
   add_filter( 'woocommerce_account_menu_items', function ( $items ) {
       unset( $items['downloads'], $items['payment-methods'] ); // no digital products; payments are off
       return $items;
   } );
   ```
   Deploy `--target plugin`.
5. Language check: tab labels, form labels, buttons and messages must be Georgian on KA (WooCommerce's KA translation is installed — "შესვლა" already renders). Any English string that remains → add to the roadmap-03 translation map.
6. Email check: registration sends the "set your password" email. **USER STEP:** the owner registers with a test email address of their own, confirms the email arrives (and is in Georgian), sets a password, logs in, opens every tab (Dashboard, Orders, Addresses, Account details, Logout), then deletes the test user in wp-admin → Users. If no email arrives, stop and report — email delivery is a separate fix.
7. Purge. My Account must not be cached for logged-in users (LiteSpeed default for logged-in — verify `X-LiteSpeed-Cache-Control: no-cache` when logged in).

Verify (mobile, desktop, 2560; KA): logged-out page shows title + login + register; no English text; logged-in dashboard has 5 tabs (no Downloads / Payment methods); addresses form uses the same +995 phone field fixes from roadmap 04 §4C; lost-password flow works (USER STEP with the test account).
Rollback: `snapshot.py restore …5C-account.json` (restores content and the 3 options), `deploy.py --target plugin --rollback`.
**Commit:** `pages(5C): styled my account with registration`.

---

## Phase 5D — Trash duplicate drafts

`5086` was trashed in 5B. Now:
```
wp post delete 5088 5759 5768
```
(no `--force` → Trash, recoverable for 30 days). **Keep** `4086` and `14595` (Refund policy drafts) and all EN drafts.
Verify: `wp post list --post_type=page --post_status=trash --fields=ID,post_title` shows exactly `5086, 5088, 5759, 5768`. Cart, My Account, Terms, Privacy still load.
Rollback: `wp post update <id> --post_status=draft` for any of them.
**Commit:** `pages(5D): trash duplicate drafts` (commit only the notes; no code).

---

## Phase 5E — Docs
- `DECISIONS.md`: Hello page titles disabled sitewide (pages carry their own Georgian headings); cart page is the Elementor Cart widget on `4083`; registration enabled with emailed password links; Downloads/Payment-methods tabs removed; EN translation of all pages deferred until the Georgian site is complete.
- `PROJECT_BRIEF.md` §4: add Cart `4083` (`/ka/cart/`), My Account `4085` (`/ka/my-account-2/`), Checkout `15559`.
**Commit:** `pages(5E): docs`.
