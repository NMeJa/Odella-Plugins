# Performance roadmap — before and after

Baseline: `reports/psi-20261108-1347.md` (2026-11-08 13:47). After phases 1–4: `reports/psi-20261108-1649.md` (2026-11-08 16:49). Both runs measure home, the store, and the Cardigan Dream Bordeaux product (`tools/psi.py` reads lines 1, 2, and 4 of `tools/urls.txt`).

## PageSpeed

| URL | Mobile score | Desktop score | Mobile LCP | Desktop LCP | Mobile CLS | Desktop CLS | Server response mobile | Server response desktop |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `/ka/` | n/a → n/a | n/a → n/a | n/a → n/a | n/a → n/a | 0.036 → 0.017 | 0 → 0.015 | 300 ms → 230 ms | 40 ms → 50 ms |
| `/ka/store/` | 86 → 80 | 90 → 93 | 3.6 s → 3.5 s | 0.8 s → 0.8 s | 0.053 → 0.047 | 0.036 → 0.149 | 300 ms → 250 ms | 280 ms → 60 ms |
| Cardigan Dream Bordeaux | 84 → 77 | 83 → 79 | 3.2 s → 5.1 s | 0.8 s → 1.0 s | 0.18 → 0.001 | 0.315 → 0.315 | 240 ms → 20 ms | 440 ms → 390 ms |

Bytes: store 1,755 → 1,487 KiB mobile and 2,619 → 1,956 KiB desktop. Product stayed near 740 / 920–980 KiB. Home stayed multi-megabyte because of the hero media (3,232 KiB mobile, 32,252 KiB desktop).

## TTFB from the cache warm

`python tools\warm_cache.py` requested 224 sitemap URLs, once mobile and once desktop (448 requests, 1 s apart).

- **88 hit, 356 miss.**
- The tool prints one TTFB per request and does not split a cached average from an uncached average. The slowest 10 are the cold tail, all HTTP 200:

| TTFB | UA | URL |
|---:|---|---|
| 22890 ms | mobile | `/ka/product/women/kimono-women/პალტო-cardinal/` |
| 13304 ms | mobile | long kimono Doro |
| 13176 ms | mobile | long kimono Piume |
| 12478 ms | mobile | short kimono Vineyard |
| 12040 ms | desktop | short kimono Magnolia |
| 11373 ms | mobile | short kimono Escama |
| 8892 ms | mobile | პალტო Passo |
| 7975 ms | desktop | long kimono Jeirani |
| 7219 ms | mobile | beach mat Dory Large |
| 6729 ms | desktop | short kimono Vineyard |

PageSpeed server-response times above were taken after this warm, so they are the cached document times for the three measured URLs.

## Targets

| Target | Result |
|---|---|
| Mobile score ≥ 80 on home, store, product | Store is 80. Home has no score (`NO_LCP`, same as the baseline). Product is 77. |
| Uncached TTFB < 1 s | Missed. The slowest cold product requests are 6.7–22.9 s. |
| Cached TTFB < 300 ms | Home and store meet it. The product desktop document is 390 ms. |
| A prefetched product feels instant | Not timed by these two tools. Phase 4 left `eagerness: moderate` on the page, with cart, checkout, and account excluded. |

## Remaining causes

These are the three largest misses. No fix was started.

1. **Home has no performance score** because Lighthouse reports `NO_LCP`. The first screen is the hero video in `Odella-Plugins/includes/widgets/responsive-video-widget.php`. This was already true in the baseline.
2. **Product mobile score 77** comes from LCP moving from 3.2 s to 5.1 s. The report’s largest unused-JavaScript saving (600 ms) is `wp-content/plugins/elementor-pro/assets/js/elements-handlers.min.js` and `wp-content/plugins/woocommerce-paypal-payments/assets/ppcp-sdk-v6-js-boot.js`. The heaviest image it names is `wp-content/uploads/5391021.Cardigan-Dream-Bordeaux-768x1152.jpg`.
3. **Cold TTFB on product URLs** is a LiteSpeed miss while Elementor builds the single-product template (post 15154). `tools/warm_cache.py` does not attribute that time to a source file in this repo.
