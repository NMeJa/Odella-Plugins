# Odella roadmaps — read this first

Written 2026-11-08 from grilling sessions with the owner. Each file is a self-contained plan for the Cursor agent; every decision in them is agreed — implement, don't re-decide.

## Order

| # | File | Depends on | What it fixes |
|---|---|---|---|
| 1 | [01-performance.md](01-performance.md) | — | **Phase 0 first: git, deploy targets, rollback, CDN purge, measurement tools.** Then footer under full-page scroll, image sizes, cacheable CSS, page transitions, speculative prefetch |
| 2 | [02-pricing.md](02-pricing.md) | 01 Phase 0 | 50 products without price hidden + guards (incl. WP Sheet Editor), EUR/USD real rates rounded up to …9 |
| 3 | [04-checkout-cart.md](04-checkout-cart.md) §4A only | 01 Phase 0 | Body typography line-height `1.6px` → `1.6em` — **already fixed by the owner**; only verify and audit the other typographies. |
| 4 | [03-shop-ux.md](03-shop-ux.md) | 01 Phase 0 + Phase 4, 02 Phase A | New AJAX filter widget replacing WOOF, Load More, Related Products, KA translation map |
| 5 | [04-checkout-cart.md](04-checkout-cart.md) rest | 02 Phase A, 03 Phase 3A | No add-to-cart for unpriced products, +995 phone field, fresh cart drawer |
| 6 | [05-pages.md](05-pages.md) | 04 §4A, §4D | Cart page, My Account, Hello page titles, duplicate drafts |
| 7 | [06-speed-and-cart.md](06-speed-and-cart.md) | 01–05 | Unified `Odella` menu & encrypted settings, Reindex + cache warmer, Modern Cart instant quantity, "Is Slow?" diagnostic + PSI + multi-language Gemini report, fast registration |

Phases 1–4 of roadmap 01 may run before or after roadmap 02; Phase 0 must come first because every other phase uses its tools.

## Rules that apply everywhere
- Read `AGENTS.md`, `PROJECT_BRIEF.md`, `DECISIONS.md` first.
- One slice → verify → commit → next. Never batch phases into one deploy.
- Code: edit locally (`Odella-Plugins/`, `site/mu-plugins/`, `site/theme-child/`), deploy only with `python tools\deploy.py --target …`.
- Database edits: `tools/snapshot.py save` first, then a `wp eval-file -` script.
- Do not call PageSpeed or warm the whole cache yourself — ask the owner to run `tools/psi.py` / `tools/warm_cache.py`.
- Payments and shipping stay disabled/unconfigured on purpose (see roadmap 04).
- Georgian only for now; leave EN drafts alone.

## Data
- [data/missing-prices.csv](data/missing-prices.csv) — the 50 unpriced products for whoever enters prices.
