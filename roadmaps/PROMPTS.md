# Starter prompts for Cursor (one per slice)

Paste **one prompt per new chat**, in this order. Wait until the slice is verified and committed before starting the next one. Each prompt is self-contained: the agent reads the rules and its roadmap section itself.

All prompts end the same way, so the slice stays small and safe:

> Rules: read `AGENTS.md`, `PROJECT_BRIEF.md`, `DECISIONS.md` and `roadmaps/README.md` first. Do only this slice — nothing from later phases. Every decision in the roadmap is agreed: implement it, don't redesign it. Snapshot before any database change. Deploy only through the deploy script. Don't run PageSpeed or warm the whole cache — ask me to run `tools/psi.py` / `tools/warm_cache.py`. At a **USER STEP**, stop and tell me exactly what to do. When done, run the slice's Verify list, show me the results, commit with the roadmap's commit message, and tell me how to roll back.

That paragraph is called **\[RULES\]** below — paste it in full at the end of every prompt.

---

## 1 · Roadmap 01 — Performance

**01-0a — Safety net (Phase 0.1–0.3)**

```
Implement roadmap 01-performance.md Phase 0, sections 0.1 to 0.3 only: the owner backup (USER STEP — stop and ask me first), git + local mirror of the child theme and mu-plugins, and tools/deploy.py with targets plugin/mu/theme, drift guard, remote backups, --rollback and the purge sequence. If HOSTINGER_API_TOKEN is empty in .env, purge the CDN with the Hostinger MCP (hosting_cache_clear, odella.ge, scope cdn) — don't ask me for a token. Test deploy.py with --check and one --no-bump deploy of each target. [RULES]
```

**01-0b — Tools and cleanup (Phase 0.4–0.6)**

```
Implement roadmap 01-performance.md Phase 0, sections 0.4 to 0.6: the measurement, warming, snapshot and probe tools in tools/, the odella-pagespeed skill (manual only), the revisions cleanup and the docs. PSI_API_KEY is already in .env. Don't run psi.py or warm_cache.py yourself — run the other tools once each to prove they work. [RULES]
```

**01-1 — Footer under full-page scroll**

```
Implement roadmap 01-performance.md Phase 1 (footer reachable under full-page scroll, widget c596a33 on home 13863). Verify on KA home at mobile 390, desktop 1440 and 2560. [RULES]
```

**01-2 — Image sizes**

```
Implement roadmap 01-performance.md Phase 2 (re-enable image sizes in Perfect Images, 2560 px upload cap, regenerate in batches, gallery f48a81a uses woocommerce_single). Run the regeneration in small batches and report progress; stop if the server errors. [RULES]
```

**01-3 — Cacheable CSS**

```
Implement roadmap 01-performance.md Phase 3 (inline CSS threshold 2 KB, Elementor CSS as external files). Take the 3.1 baseline numbers before changing anything and compare after. [RULES]
```

**01-4 — Page transitions + prefetch**

```
Implement roadmap 01-performance.md Phase 4 (moderate speculative prefetch, custom page transition — 200 ms fade-out with the logo, 250 ms fade-in, no minimum wait — and Elementor's own page transitions off in kit 16). Check that cart, checkout and my-account are excluded from prefetch. [RULES]
```

**01-5 — Measure**

```
Do roadmap 01-performance.md Phase 5: tell me exactly which tools/warm_cache.py and tools/psi.py commands to run, wait for my output, then record the before/after numbers as the roadmap says. [RULES]
```

---

## 2 · Roadmap 02 — Pricing

**02-A — Hide unpriced products**

```
Implement roadmap 02-pricing.md Phase A in site/mu-plugins/odella-pricing.php: hide the 50 unpriced products from catalog and search (with the _odella_auto_hidden marker), un-hide automatically when a price is added — including saves from WP Sheet Editor — then run the backfill and add noindex. Check against roadmaps/data/missing-prices.csv. [RULES]
```

**02-B — Draft guard**

```
Implement roadmap 02-pricing.md Phase B: new or newly unpriced products are kept as Draft with the bilingual admin notice. Never touch legacy hidden products. Test with one new test product, then delete it. [RULES]
```

**02-C — Price audit tool**

```
Implement roadmap 02-pricing.md Phase C: tools/price_audit.py and the hand-over notes for whoever fills the 50 prices. [RULES]
```

**02-D — EUR/USD rates**

```
Implement roadmap 02-pricing.md Phase D: daily NBG EUR/USD rates into Price Based on Country, a manually entered zone price always wins, otherwise convert and round up to …9 (odella_round_up_9). The Sheet Editor columns and the VPN check are USER STEPs — stop and give me the steps. [RULES]
```

**02-E — Docs**

```
Do roadmap 02-pricing.md Phase E (docs). [RULES]
```

---

## 3 · Roadmap 04 §4A — Line height (verify only)

**04-A — Verify the line-height fix**

```
Roadmap 04-checkout-cart.md Phase 4A: I already changed the kit 16 Body typography line height from 1.6px to 1.6em. Don't change it again. Verify it is saved, audit the other global typographies and the Terms/Privacy pages for the same px-instead-of-em bug, and check the cart drawer product name at 1600 px and 2560 px. Report anything else you find before fixing it. [RULES]
```

---

## 4 · Roadmap 03 — Shop UX

**03-A — Translation map + Load More**

```
Implement roadmap 03-shop-ux.md Phase 3A: Odella-Plugins/includes/i18n.php with nmj_t(), Load More "მეტის ნახვა" / "Load More" in the new style, button hidden when nothing is left, no "no more posts" message. Use the odella-plugin-deploy skill. [RULES]
```

**03-B — Related products**

```
Implement roadmap 03-shop-ux.md Phase 3B: related products from the deepest same category, topped up from the parent; 4 on desktop, 2 on mobile (template 15154, widget 11ace090). [RULES]
```

**03-C — Headscarf category**

```
Implement roadmap 03-shop-ux.md Phase 3C: move Headscarf (term 684) under Women › Scarf (parent 77), slug headscarf-women, with 301 redirects from the old URLs. Snapshot the term first. [RULES]
```

**03-D — Build the filter widget**

```
Implement roadmap 03-shop-ux.md Phase 3D: the "Odella Product Filter" Elementor widget (AJAX grid swap, pushState to real category URLs, ?q= search, ?orderby= sort with Newest as default, empty-categories toggle default off, prefetch, progress line, swoof 301s). Test it on a separate test page only — don't place it on the live store yet. [RULES]
```

**03-E — Go live, retire WOOF**

```
Implement roadmap 03-shop-ux.md Phase 3E: place the filter widget on the store and archive template 15580, remove the WOOF shortcode 816677c and popup 16255, deactivate WOOF (keep it installed for 2 weeks). Run the full 3E.1 verify list. [RULES]
```

**03-F — Docs**

```
Do roadmap 03-shop-ux.md Phase 3F (docs and cleanup). [RULES]
```

---

## 5 · Roadmap 04 — Checkout and cart (rest)

**04-B — No add-to-cart for unpriced products**

```
Implement roadmap 04-checkout-cart.md Phase 4B: hide the add-to-cart button and price when the product is not purchasable (add-to-cart-widget.php). Payments stay disabled by design — don't touch them. [RULES]
```

**04-C — +995 phone field**

```
Implement roadmap 04-checkout-cart.md Phase 4C: phone field layout fix (.iti), GE as the default country, E.164 format, GE mobile rule ^5\d{8}$. Verify on checkout at 390, 1440 and 2560. [RULES]
```

**04-D — Fresh cart drawer**

```
Implement roadmap 04-checkout-cart.md Phase 4D: the cart-nonce REST endpoint plus the JS that refreshes the Modern Cart drawer on every page load, and the Georgian drawer texts. Test with a cached page in a private window. [RULES]
```

**04-E — Docs**

```
Do roadmap 04-checkout-cart.md Phase 4E (docs). [RULES]
```

---

## 6 · Roadmap 05 — Pages (Georgian only)

**05 — whole roadmap, one phase at a time**

```
Start roadmap order item 6: roadmaps/05-pages.md. Shop UX (roadmaps/03-shop-ux.md) and checkout/cart (roadmaps/04-checkout-cart.md, phases 4A–4E) are finished. Do not start anything after this file.

Do Phase 5A, then 5B, 5C, 5D, and 5E, one phase at a time. Verify, then commit with that phase's commit message, then start the next. Do not push.

Where things stand:
- Plugin is 1.0.71. Owned code deploys only via python tools\deploy.py.
- Body typography 7778499 desktop line-height is 1.6em. Do not change it again.
- Unpriced products have no price and no add-to-cart button (Morbido 17550). Priced simple 18781 and variable Pera 16128 add normally.
- Checkout phone defaults to Georgia +995, submits E.164, and rejects a Georgian number that is not 9 digits starting with 5. Payments and shipping stay disabled. Do not place an order.
- Cart drawer refreshes from GET /wp-json/odella/v1/cart-nonce. Georgian drawer texts are already live. /ka/cart/ is still a draft 404 (page 4083). My Account is the published page 4085 at /ka/my-account-2/, with Hello's English title still showing.
- WOOF is deactivated, not deleted. On 2026-11-22, if no rollback was needed, delete it and popup 16255. Leave English drafts alone.
- The manual check for this shop and checkout work is roadmaps/manual-test-03-shop-checkout.html. Do not rewrite it unless a 05 change makes a step wrong.

[RULES]
```

**05-A — Hello page titles off**

```
Implement roadmap 05-pages.md Phase 5A: first list every published page and whether it has its own heading, then disable Hello's automatic page titles in the child theme. Ask me about any page (other than Cart and My Account) that would be left without a title. [RULES]
```

**05-B — Cart page**

```
Implement roadmap 05-pages.md Phase 5B: trash duplicate 5086, build cart page 4083 with the Elementor Cart widget styled like checkout 15559, then publish /ka/cart/. If you can't drive the Elementor editor, stop and give me the steps. [RULES]
```

**05-C — My Account**

```
Implement roadmap 05-pages.md Phase 5C: My Account 4085 with the Elementor My Account widget, registration on, Downloads and Payment methods tabs removed. The test registration is a USER STEP — stop and give me the steps. [RULES]
```

**05-D — Trash duplicate drafts**

```
Implement roadmap 05-pages.md Phase 5D: trash drafts 5088, 5759 and 5768 (Trash only, not permanent). Keep 4086, 14595 and every EN draft. [RULES]
```

**05-E — Docs**

```
Do roadmap 05-pages.md Phase 5E (docs). [RULES]
```