#!/usr/bin/env python3
"""Report published products that still have no GEL price.

One `wp eval-file -` on the server. Prints how many published products have
no price, how many are auto-hidden, and the first 20 still missing. Exits 1
when a product is marked `_odella_auto_hidden` but now has a price: the
visibility sync did not run. Fix that with:

  wp eval 'odella_pricing_backfill();'
"""

from __future__ import annotations

import json
import subprocess
import sys

SSH = ["ssh", "-p", "65002", "-o", "BatchMode=yes", "u981153228@46.17.175.131"]
REMOTE = "cd /home/u981153228/domains/odella.ge/public_html && wp eval-file -"
START = "__PRICE_AUDIT_START__"
END = "__PRICE_AUDIT_END__"

PHP = r"""<?php
if ( ! function_exists( 'odella_product_has_price' ) ) {
    WP_CLI::error( 'odella_product_has_price is not loaded' );
}
$published = get_posts(
    array(
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'suppress_filters'       => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    )
);
$missing = array();
foreach ( $published as $id ) {
    $id      = (int) $id;
    $product = wc_get_product( $id );
    if ( $product instanceof WC_Product && odella_product_has_price( $product ) ) {
        continue;
    }
    $missing[] = array(
        'id'   => $id,
        'name' => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
    );
}
$hidden = get_posts(
    array(
        'post_type'              => 'product',
        'post_status'            => 'any',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'orderby'                => 'ID',
        'order'                  => 'ASC',
        'meta_key'               => '_odella_auto_hidden',
        'meta_value'             => '1',
        'suppress_filters'       => true,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    )
);
$stale = array();
foreach ( $hidden as $id ) {
    $id      = (int) $id;
    $product = wc_get_product( $id );
    if ( $product instanceof WC_Product && odella_product_has_price( $product ) ) {
        $stale[] = array(
            'id'   => $id,
            'name' => html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
        );
    }
}
echo "__PRICE_AUDIT_START__\n";
echo wp_json_encode(
    array(
        'published_without_price' => count( $missing ),
        'auto_hidden'             => count( $hidden ),
        'missing'                 => array_slice( $missing, 0, 20 ),
        'stale'                   => $stale,
    )
);
echo "\n__PRICE_AUDIT_END__\n";
"""


def wp_eval(php: str) -> str:
    proc = subprocess.run(
        [*SSH, REMOTE],
        input=php.encode("utf-8"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    out = proc.stdout.decode("utf-8", "replace")
    err = proc.stderr.decode("utf-8", "replace")
    if proc.returncode != 0:
        raise RuntimeError(f"wp eval-file failed (exit {proc.returncode}):\n{err}\n{out}")
    if err.strip():
        print(err.rstrip(), file=sys.stderr)
    return out


def extract(raw: str) -> dict:
    start = raw.find(START)
    end = raw.find(END)
    if start < 0 or end < 0 or end < start:
        raise RuntimeError(f"Price audit markers missing:\n{raw[:1500]}")
    data = json.loads(raw[start + len(START) : end].strip())
    if not isinstance(data, dict):
        raise RuntimeError("Price audit JSON was not an object.")
    return data


def main() -> None:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    data = extract(wp_eval(PHP))
    missing = data.get("missing") or []
    stale = data.get("stale") or []
    print(f"published_without_price={data.get('published_without_price', 0)}")
    print(f"auto_hidden={data.get('auto_hidden', 0)}")
    print(f"stale_hidden_with_price={len(stale)}")
    print("missing:")
    if not missing:
        print("  (none)")
    for row in missing:
        print(f"  {row['id']}  {row['name']}")
    if stale:
        print("FLAG: auto-hidden product(s) now have a price. The sync did not run.")
        for row in stale:
            print(f"  {row['id']}  {row['name']}")
        print("Fix: wp eval 'odella_pricing_backfill();'")
        raise SystemExit(1)


if __name__ == "__main__":
    main()
