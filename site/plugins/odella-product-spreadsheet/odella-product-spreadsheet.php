<?php
/**
 * Plugin Name: Odella Product Spreadsheet
 * Description: Download Georgian products to Excel or CSV, edit them, and import only the cells that changed.
 * Version: 1.2.3
 * Author: Odella
 * Requires Plugins: woocommerce
 * Text Domain: odella-product-spreadsheet
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OPS_PATH', plugin_dir_path(__FILE__));

require_once OPS_PATH . 'includes/columns.php';
require_once OPS_PATH . 'includes/meta.php';
require_once OPS_PATH . 'includes/prices.php';
require_once OPS_PATH . 'includes/categories.php';
require_once OPS_PATH . 'includes/attributes.php';
require_once OPS_PATH . 'includes/html.php';
require_once OPS_PATH . 'includes/xlsx.php';
require_once OPS_PATH . 'includes/csv.php';
require_once OPS_PATH . 'includes/images.php';
require_once OPS_PATH . 'includes/validate.php';
require_once OPS_PATH . 'includes/apply.php';
require_once OPS_PATH . 'includes/export.php';
require_once OPS_PATH . 'includes/sheet-scripts.php';
require_once OPS_PATH . 'includes/admin.php';
require_once OPS_PATH . 'includes/rest-api.php';
