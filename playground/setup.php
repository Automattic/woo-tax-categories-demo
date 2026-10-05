<?php
/**
 * Playground store setup: a US/UK test store with the example data, so the prototype can be tried in one click.
 * Runs once from the Playground blueprint after WooCommerce and the plugin are active.
 *
 * @package WCTC
 */

require_once '/wordpress/wp-load.php';

// Store basics: New York store, USD, taxes on, based on the customer's shipping address, prices exclusive of tax.
update_option( 'woocommerce_default_country', 'US:NY' );
update_option( 'woocommerce_store_address', '1 Example St' );
update_option( 'woocommerce_store_city', 'New York' );
update_option( 'woocommerce_store_postcode', '10001' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_tax_total_display', 'itemized' );
update_option( 'woocommerce_shipping_tax_class', 'inherit' );
update_option( 'woocommerce_specific_allowed_countries', array( 'US', 'GB' ) );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery' ) );
update_option( 'woocommerce_weight_unit', 'lbs' );

// One shipping zone for the US and UK with a taxable $10 flat rate.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'US and UK' );
$zone->add_location( 'US', 'country' );
$zone->add_location( 'GB', 'country' );
$zone->save();
$instance_id = $zone->add_shipping_method( 'flat_rate' );
update_option(
	'woocommerce_flat_rate_' . $instance_id . '_settings',
	array(
		'title'      => 'Flat rate',
		'tax_status' => 'taxable',
		'cost'       => '10',
	)
);

// Product categories: Clothing (with a Hoodies child), Books, Kitchen.
function wctc_cat( $name, $parent = 0 ) {
	$found = term_exists( $name, 'product_cat' );
	if ( $found ) {
		return (int) $found['term_id'];
	}
	$term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return (int) $term['term_id'];
}
$clothing = wctc_cat( 'Clothing' );
$hoodies  = wctc_cat( 'Hoodies', $clothing );
$books    = wctc_cat( 'Books' );
$kitchen  = wctc_cat( 'Kitchen' );

function wctc_product( $name, $price, $cat, $weight ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_category_ids( array( $cat ) );
	$product->set_weight( $weight );
	$product->set_status( 'publish' );
	$product->set_tax_status( 'taxable' );
	$product->set_tax_class( '' );
	return $product->save();
}
$ids = array(
	'hoodie'  => wctc_product( 'Hoodie', '45', $hoodies, '2' ),
	'coat'    => wctc_product( 'Winter coat', '150', $clothing, '4' ),
	'jacket'  => wctc_product( 'Jacket', '60', $clothing, '2' ),
	'book'    => wctc_product( 'The Penderwicks at Last', '20', $books, '1' ),
	'mug'     => wctc_product( 'Mug', '10', $kitchen, '1' ),
	'blender' => wctc_product( 'Blender', '40', $kitchen, '6' ),
	// Priced above the Massachusetts $175 and Rhode Island $250 limits so the excess-only rules fire.
	'ma_coat' => wctc_product( 'Designer coat ($200, for MA excess test)', '200', $clothing, '3' ),
	'ri_coat' => wctc_product( 'Luxury coat ($300, for RI excess test)', '300', $clothing, '3' ),
);
update_option( 'wctc_playground_products', $ids );
file_put_contents( ABSPATH . 'wctc-products.json', wp_json_encode( $ids ) ); // Read by tools/checkout.spec.js.

// Prototype example data and rates, then assign tax categories and switch the feature on.
\WCTC\Examples::load();
\WCTC\Examples::add_rates();
update_term_meta( $clothing, \WCTC\Store::META_TERM, 'clothing' );
update_term_meta( $books, \WCTC\Store::META_TERM, 'books' );
update_option( \WCTC\Store::OPT_ENABLED, 'yes' );

// Make sure a Zero rate class exists (it does by default).
if ( ! in_array( 'zero-rate', WC_Tax::get_tax_class_slugs(), true ) ) {
	WC_Tax::create_tax_class( 'Zero rate' );
}
