<?php
/**
 * Playground store setup for the EU/UK/AU demo. Berlin base, EUR, selling to DE/FR/IT/IE/BE/NL/GB/AU,
 * one €4.90 flat rate zone. Load the EU example tax categories, rules and rates, assign categories to
 * the product categories, and switch the feature on. The 111-case US suite is unaffected: it still
 * runs on the US blueprint built from setup.php.
 *
 * @package WCTC
 */

require_once '/wordpress/wp-load.php';

// Store basics: Berlin store, EUR, taxes on, based on the customer's shipping address, prices ex. VAT
// (keeps the "20 × 7%" try-it arithmetic readable; inclusive pricing is suite case D5 on the US side).
update_option( 'woocommerce_default_country', 'DE' );
update_option( 'woocommerce_store_address', 'Unter den Linden 1' );
update_option( 'woocommerce_store_city', 'Berlin' );
update_option( 'woocommerce_store_postcode', '10115' );
update_option( 'woocommerce_currency', 'EUR' );
update_option( 'woocommerce_currency_pos', 'left_space' );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_display_shop', 'excl' );
update_option( 'woocommerce_tax_display_cart', 'excl' );
update_option( 'woocommerce_tax_total_display', 'itemized' );
update_option( 'woocommerce_shipping_tax_class', 'inherit' );
update_option( 'woocommerce_specific_allowed_countries', array( 'DE', 'FR', 'IT', 'IE', 'BE', 'NL', 'GB', 'AU' ) );
update_option( 'woocommerce_allowed_countries', 'specific' );
update_option( 'woocommerce_ship_to_countries', '' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes', 'title' => 'Cash on delivery' ) );
update_option( 'woocommerce_weight_unit', 'kg' );

// One shipping zone covering all eight selling countries. Flat rate €4.90 (the number every example
// uses), Local pickup, and Free shipping over €100.
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'EU, UK and Australia' );
foreach ( array( 'DE', 'FR', 'IT', 'IE', 'BE', 'NL', 'GB', 'AU' ) as $cc ) {
	$zone->add_location( $cc, 'country' );
}
$zone->save();
$flat_id = $zone->add_shipping_method( 'flat_rate' );
update_option( 'woocommerce_flat_rate_' . $flat_id . '_settings', array( 'title' => 'Flat rate', 'tax_status' => 'taxable', 'cost' => '4.90' ) );
$pickup_id = $zone->add_shipping_method( 'local_pickup' );
update_option( 'woocommerce_local_pickup_' . $pickup_id . '_settings', array( 'title' => 'Local pickup', 'tax_status' => 'taxable', 'cost' => '0' ) );
$free_id = $zone->add_shipping_method( 'free_shipping' );
update_option( 'woocommerce_free_shipping_' . $free_id . '_settings', array( 'title' => 'Free shipping', 'requires' => 'min_amount', 'min_amount' => '100' ) );

// Product categories. Clothing with a Kids child so Children's clothing can be assigned to the
// child category while an uncategorized adult Clothing product falls back to its own class.
function wctc_eu_cat( $name, $parent = 0 ) {
	$found = term_exists( $name, 'product_cat' );
	if ( $found ) {
		return (int) $found['term_id'];
	}
	$term = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return (int) $term['term_id'];
}
$books    = wctc_eu_cat( 'Books' );
$clothing = wctc_eu_cat( 'Clothing' );
$kids     = wctc_eu_cat( 'Kids', $clothing );
$pantry   = wctc_eu_cat( 'Pantry' );
$kitchen  = wctc_eu_cat( 'Kitchen' );

function wctc_eu_product( $name, $price, $cat, $weight, $extra = array() ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_category_ids( (array) $cat );
	$product->set_weight( $weight );
	$product->set_status( 'publish' );
	$product->set_tax_status( 'taxable' );
	$product->set_tax_class( '' );
	foreach ( $extra as $k => $v ) {
		if ( 'meta' === $k ) {
			foreach ( $v as $mk => $mv ) {
				$product->update_meta_data( $mk, $mv );
			}
		} else {
			$product->{"set_$k"}( $v );
		}
	}
	return $product->save();
}

$ids = array(
	'paperback'  => wctc_eu_product( 'Paperback: The Tin Drum', '20', $books, '0.4' ),
	'jumper'     => wctc_eu_product( "Children's jumper", '30', $kids, '0.3' ),
	'coffee'     => wctc_eu_product( 'Coffee beans 1 kg', '12', $pantry, '1.0' ),
	// Virtual e-book with a product-level override to Digital books, so it uses the digital-books
	// rule even though its category "Books" would otherwise send it through the Books rule set.
	'ebook'      => wctc_eu_product( 'E-book: The Tin Drum', '10', $books, '', array( 'virtual' => true, 'meta' => array( '_wctc_tax_category' => 'digital-books' ) ) ),
	'mug'        => wctc_eu_product( 'Mug', '10', $kitchen, '0.4' ),
	'jacket'     => wctc_eu_product( 'Rain jacket', '60', $clothing, '0.8' ),
);
update_option( 'wctc_playground_products', $ids );

// Load the EU example data: four categories, country rules with gaps, one BE shipping rule.
\WCTC\Examples::load_eu();
\WCTC\Examples::add_rates_eu();

// Assign tax categories to the product categories. The Kids child category overrides its Clothing
// parent so an adult jacket keeps its own class while a kids jumper picks up Children's clothing.
update_term_meta( $books, \WCTC\Store::META_TERM, 'books' );
update_term_meta( $kids, \WCTC\Store::META_TERM, 'childrens-clothing' );
update_term_meta( $pantry, \WCTC\Store::META_TERM, 'food' );

update_option( \WCTC\Store::OPT_ENABLED, 'yes' );

// Make sure a Zero rate class exists (default in WooCommerce, but belt and braces for a fresh site).
if ( ! in_array( 'zero-rate', WC_Tax::get_tax_class_slugs(), true ) ) {
	WC_Tax::create_tax_class( 'Zero rate' );
}
