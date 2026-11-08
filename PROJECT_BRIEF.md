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
  * **CSS Print Method**: Configured to **Internal** (`elementor_css_print_method: internal`) to prevent 404 race conditions with Hostinger’s Edge CDN cache.
* **Custom Widgets**: `odella-elementor-widgets` plugin (provides custom widgets: `nmj-gallery-grid`, `nmj-add-to-cart`, `nmj-product-price`, `nmj-variation-selector`).
* **Product Filters**: `woocommerce-products-filter` (WOOF / Husky).
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
* **Single Product Template**: Elementor Library ID `15154`
* **Our Story / About Us Page**: Post ID `58` (`/ka/our-story/`)

---

## 5. Development Best Practices for This Site
* **Local-First Plugin Workflow (`Odella-Plugins`)**: Always make changes to `odella-elementor-widgets` locally in [`Odella-Plugins/`](file:///c:/Users/Nick/Projects/Hostinger-Connect/Odella-Plugins) and deploy using `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py"` (or `--check` / `--no-bump`). Never manually edit files inside `/wp-content/plugins/odella-elementor-widgets/` on the server.
* All responsive layout overrides, SVG constrainers, and custom fixes are kept clean and organized in the child theme's `style.css`.
* After updating template data or options, flush the WP object cache using `wp_cache_flush()` or `\Elementor\Plugin::$instance->files_manager->clear_cache()`.
