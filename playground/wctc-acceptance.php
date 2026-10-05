<?php
/**
 * One-click runner for the README acceptance example: $45 Hoodie + $20 book shipped to NYC 10001.
 * Clears the current cart, adds the two seeded products, pins the customer's shipping address to
 * NYC, picks the $10 flat rate, then forwards to /checkout so the reviewer sees the tax breakdown
 * (hoodie exempt by the Clothing-in-NY <$110 rule, book $1.78, shipping $0.27, total $2.05).
 * Not part of the plugin: it exists only in the Playground test store.
 *
 * @package WCTC
 */

require_once __DIR__ . '/wp-load.php';
wc_load_cart();

$ids = get_option( 'wctc_playground_products', array() );
if ( empty( $ids['hoodie'] ) || empty( $ids['book'] ) ) {
	wp_die( 'Seeded products not found. The blueprint may not have finished booting yet.' );
}

WC()->cart->empty_cart();
WC()->cart->add_to_cart( (int) $ids['hoodie'], 1 );
WC()->cart->add_to_cart( (int) $ids['book'], 1 );

$ny = array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York', 'address_1' => '1 Example St', 'first_name' => 'Reviewer', 'last_name' => 'Example' );
WC()->customer->set_shipping_country( $ny['country'] );
WC()->customer->set_shipping_state( $ny['state'] );
WC()->customer->set_shipping_postcode( $ny['postcode'] );
WC()->customer->set_shipping_city( $ny['city'] );
WC()->customer->set_shipping_address_1( $ny['address_1'] );
WC()->customer->set_shipping_first_name( $ny['first_name'] );
WC()->customer->set_shipping_last_name( $ny['last_name'] );
WC()->customer->set_billing_country( $ny['country'] );
WC()->customer->set_billing_state( $ny['state'] );
WC()->customer->set_billing_postcode( $ny['postcode'] );
WC()->customer->set_billing_city( $ny['city'] );
WC()->customer->set_billing_address_1( $ny['address_1'] );
WC()->customer->set_billing_first_name( $ny['first_name'] );
WC()->customer->set_billing_last_name( $ny['last_name'] );
WC()->customer->set_calculated_shipping( true );
WC()->customer->save();

// Pre-pick the first available rate so the totals row shows a shipping tax without a second click.
WC()->cart->calculate_shipping();
$packages = WC()->shipping->get_packages();
$first    = $packages[0]['rates'] ?? array();
if ( $first ) {
	WC()->session->set( 'chosen_shipping_methods', array( array_key_first( $first ) ) );
}
WC()->cart->calculate_totals();

wp_safe_redirect( wc_get_checkout_url() );
exit;
