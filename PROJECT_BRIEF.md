# Project Brief: Odella Website

## 1. Server & SSH Connection
* **SSH Access**: `ssh -p 65002 u981153228@46.17.175.131` *(authenticates automatically via local SSH key)*
* **Hosting**: Hostinger (LiteSpeed Web Server, hPanel, Hostinger CDN)
* **Web Root**: `/home/u981153228/domains/odella.ge/public_html`
* **Active Theme**: Hello Elementor Child
  * Path: `.../wp-content/themes/hello-theme-child-master/`
  * Primary custom stylesheet: `style.css` *(enqueued with priority 999 and dynamic `filemtime` cache-busting)*
* *Note for AI terminal commands*: Outbound SSH/SCP connections need `BypassSandbox: true` if the sandbox blocks port 65002.

---

## 2. About the Website
* **Domain**: `https://odella.ge`
* **Brand / Niche**: **Odella** — an e-commerce brand selling premium silk clothing and accessories (silk kimonos, scarves, vests, beachwear, dresses, gift sets).
* **Languages**: Multi-language setup via Polylang (Georgian `/ka/` as primary, English `/en/`).

---

## 3. Tech Stack & Key Plugins
* **Core**: WordPress + WooCommerce (over 200 published products).
* **Page Builder**: Elementor & Elementor Pro.
  * **CSS Print Method**: **External** (`elementor_css_print_method: external`). The mu-plugin inlines a local stylesheet only when the file is ≤ 2 KB. Purge order is Elementor, then LiteSpeed, then the Hostinger CDN, and `tools/probe_css.py` checks for 404s.
  * **Page transitions**: custom. A click fades to white with the O'Della logo (200 ms out, 250 ms in, no minimum wait). Elementor Pro page transitions in kit 16 are off. Logged-out pages prefetch links at eagerness `moderate`, excluding cart, checkout, and account.
* **Custom Widgets**: `odella-elementor-widgets` plugin (provides custom widgets: `nmj-gallery-grid`, `nmj-add-to-cart`, `nmj-product-price`, `nmj-variation-selector`, `nmj_product_filter`).
* **Product Filters**: Odella Product Filter widget (`nmj_product_filter`) on the store archive. Category URLs are the filter. Search is `?q=` and sort is `?orderby=`. Clicks use the cached catalog index in `wp-content/uploads/odella-catalog/`. WOOF is deactivated until 2026-11-22.
* **Multilingual**: `polylang` + `connect-polylang-elementor`.

---

## 4. Key Template IDs & Architecture
* **Global Kit (Site Settings)**: ID `16`
  * Global brand colors are permanently defined in `style.css` `:root`:
    * `OdellaGreen`: `#00A79D` (`--e-global-color-9333735`)
    * `OdellaPurple`: `#7966B1` (`--e-global-color-357c029`)
    * `OdellaSilver`: `#8A8D8F` (`--e-global-color-256943e`)
    * `OdellaLightBlue`: `#80C7C7` (`--e-global-color-a33bc50`)
* **Header Template**: Elementor Library ID `14412`
* **Footer Template**: Elementor Library ID `3023`
* **Product Archive / Store Page**: Template ID `15580`
  * Uses an Elementor Loop Grid with AJAX **Load More** pagination and card template ID `14786`.
  * The Odella Product Filter widget (`nmj_product_filter`, element `f3e0d21`) sits above Loop Grid `c490a4e`.
* **Shop strings**: `nmj_t()` in `Odella-Plugins/includes/i18n.php`.
* **Related products**: `Odella-Plugins/includes/related-products.php`. Same deepest category, then its parent only. Widget `11ace090` on single product template `15154`.
* **Single Product Template**: Elementor Library ID `15154`
* **Our Story / About Us Page**: Post ID `58` (`/ka/our-story/`)
* **Pricing guards**: [`site/mu-plugins/odella-pricing.php`](file:///c:/Users/Nick/Projects/Hostinger-Connect/site/mu-plugins/odella-pricing.php). A published product with no GEL price is hidden from the catalog and search. A newly unpriced product stays Draft. EUR and USD follow the daily National Bank of Georgia rate unless a manual zone price is entered, and a converted price is rounded up to the next whole number ending in 9.

---

## 5. Development Best Practices for This Site
* **Local-first owned code**: Edit the plugin in [`Odella-Plugins/`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins), the mu-plugin in [`site/mu-plugins/`](file:///c:/Users/Nick/Projects/Hostinger-Connect/site/mu-plugins), and the child theme in [`site/theme-child/`](file:///c:/Users/Nick/Projects/Hostinger-Connect/site/theme-child). Deploy with `python tools\deploy.py --target plugin|mu|theme` (`--check`, `--no-bump`, `--rollback`). Never edit those trees on the server. Exit **2** purge failed, **3** drift, **4** smoke test failed. Remote copies of each deploy live in `~/odella-deploy-backups`.
* All responsive layout overrides, SVG constrainers, and custom fixes are kept clean and organized in the child theme's `style.css`.
* After updating template data or options, flush the WP object cache using `wp_cache_flush()` or `\Elementor\Plugin::$instance->files_manager->clear_cache()`.
* **Measurement tools** (owner-run for the two that hit the live site hard): `python tools\deploy.py`, `python tools\snapshot.py`, `python tools\probe_css.py`, `python tools\warm_cache.py`, `python tools\psi.py`. PageSpeed reports go to `reports/psi-YYYYMMDD-HHMM.md`. Raw JSON stays in `reports/raw/` and is gitignored.
