<?php
/**
 * Removes temporary spreadsheet files. Products and pricing settings stay.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$uploads = wp_upload_dir();
$dir = $uploads['basedir'] . '/odella-product-sheet';
if (is_dir($dir)) {
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
