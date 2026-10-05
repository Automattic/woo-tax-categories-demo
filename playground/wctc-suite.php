<?php
/**
 * Full validation suite for the tax categories prototype. Builds real carts and orders in WooCommerce and
 * checks them against expected results. Open /wctc-suite.php in the Playground site (add ?format=json for data).
 *
 * Sections: A opt-out parity · B category resolution · C rule matching · D shipping · E order editing ·
 * F Store API · G analytics · H merchant setup mistakes.
 *
 * @package WCTC
 */

require_once __DIR__ . '/wp-load.php';
if ( ! current_user_can( 'manage_woocommerce' ) ) {
	wp_die( 'Log in as an admin.' );
}
wc_load_cart();
set_time_limit( 300 );

$cases = array();
$ids   = get_option( 'wctc_playground_products' );
$money = fn( $v ) => round( (float) $v, 2 );

/** Record a test case. */
function tc( $id, $area, $scenario, $steps, $expected, $actual, $note = '' ) {
	global $cases;
	if ( is_float( $expected ) || is_int( $expected ) ) {
		$pass   = abs( (float) $expected - (float) $actual ) < 0.005;
		$exp_s  = is_float( $expected ) ? number_format( $expected, 2 ) : (string) $expected;
		$act_s  = is_numeric( $actual ) ? number_format( (float) $actual, 2 ) : var_export( $actual, true );
	} else {
		$pass  = $expected === $actual;
		$exp_s = is_bool( $expected ) ? ( $expected ? 'yes' : 'no' ) : (string) $expected;
		$act_s = is_bool( $actual ) ? ( $actual ? 'yes' : 'no' ) : (string) ( is_scalar( $actual ) ? $actual : wp_json_encode( $actual ) );
	}
	$cases[] = compact( 'id', 'area', 'scenario', 'steps', 'note', 'pass' ) + array( 'expected' => $exp_s, 'actual' => $act_s );
}

/** Switch the two settings. */
function wctc_set( $enabled, $split = false ) {
	update_option( 'wctc_enabled', $enabled ? 'yes' : 'no' );
	update_option( 'wctc_shipping_split', $split ? 'yes' : 'no' );
	\WCTC\Store::bump();
}

/** Detach every runtime hook the plugin adds, so WooCommerce runs as if the plugin were deactivated. */
$wctc_hooks = array(
	array( 'woocommerce_product_get_tax_class', array( 'WCTC\Resolver', 'filter_tax_class' ), 20 ),
	array( 'woocommerce_product_variation_get_tax_class', array( 'WCTC\Resolver', 'filter_tax_class' ), 20 ),
	array( 'woocommerce_calc_tax', array( 'WCTC\Resolver', 'filter_calc_tax' ), 10 ),
	array( 'woocommerce_before_calculate_totals', array( 'WCTC\Resolver', 'reset_pending' ), 10 ),
	array( 'woocommerce_after_calculate_totals', array( 'WCTC\Resolver', 'reset_pending' ), 10 ),
	array( 'woocommerce_order_before_calculate_taxes', array( 'WCTC\Resolver', 'reset_pending' ), 10 ),
	array( 'woocommerce_order_after_calculate_taxes', array( 'WCTC\Resolver', 'reset_pending' ), 10 ),
	array( 'woocommerce_cart_shipping_packages', array( 'WCTC\Shipping', 'tag_packages' ), 10 ),
	array( 'woocommerce_package_rates', array( 'WCTC\Shipping', 'retax_rates' ), 50 ),
	array( 'woocommerce_checkout_create_order_line_item', array( 'WCTC\Shipping', 'note_line_item' ), 10 ),
	array( 'woocommerce_order_before_calculate_taxes', array( 'WCTC\Shipping', 'resolve_order_items' ), 10 ),
	array( 'woocommerce_order_item_shipping_after_calculate_taxes', array( 'WCTC\Shipping', 'retax_order_shipping' ), 10 ),
);
function wctc_detach() {
	global $wctc_hooks;
	foreach ( $wctc_hooks as $h ) {
		remove_filter( $h[0], $h[1], $h[2] );
	}
	\WCTC\Store::bump();
}
function wctc_attach() {
	global $wctc_hooks;
	$args = array( 'woocommerce_product_get_tax_class' => 2, 'woocommerce_product_variation_get_tax_class' => 2, 'woocommerce_calc_tax' => 4, 'woocommerce_package_rates' => 2, 'woocommerce_checkout_create_order_line_item' => 4, 'woocommerce_order_before_calculate_taxes' => 2, 'woocommerce_order_item_shipping_after_calculate_taxes' => 2 );
	foreach ( $wctc_hooks as $h ) {
		add_filter( $h[0], $h[1], $h[2], $args[ $h[0] ] ?? 1 );
	}
	\WCTC\Store::bump();
}

/** Products made for this suite. */
function wctc_mk( $key, $fn ) {
	$made = get_option( 'wctc_suite_products', array() );
	if ( empty( $made[ $key ] ) || ! wc_get_product( $made[ $key ] ) ) {
		$made[ $key ] = $fn();
		update_option( 'wctc_suite_products', $made );
	}
	return $made[ $key ];
}
function wctc_term( $name, $parent = 0 ) {
	$t = get_term_by( 'name', $name, 'product_cat' );
	if ( $t ) {
		return (int) $t->term_id;
	}
	$r = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );
	return (int) $r['term_id'];
}
function wctc_simple( $name, $price, $cats, $weight = '1', $extra = array() ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_category_ids( (array) $cats );
	$p->set_weight( $weight );
	foreach ( $extra as $k => $v ) {
		if ( 'meta' === $k ) {
			foreach ( $v as $mk => $mv ) {
				$p->update_meta_data( $mk, $mv );
			}
		} else {
			$p->{"set_$k"}( $v );
		}
	}
	return $p->save();
}

$clothing = wctc_term( 'Clothing' );
$hoodies  = wctc_term( 'Hoodies', $clothing );
$zips     = wctc_term( 'Zip hoodies', $hoodies );
$books    = wctc_term( 'Books' );
$kitchen  = wctc_term( 'Kitchen' );

$zip_hoodie = wctc_mk( 'zip_hoodie', fn() => wctc_simple( 'Zip hoodie (grandchild category)', '50', $zips, '2' ) );
$sale_coat  = wctc_mk( 'sale_coat', fn() => wctc_simple( 'Sale coat ($120 on sale for $100)', '120', $clothing, '3', array( 'sale_price' => '100' ) ) );
$no_weight  = wctc_mk( 'no_weight', fn() => wctc_simple( 'Lamp (no weight)', '40', $kitchen, '' ) );
$uncat      = wctc_mk( 'uncat', fn() => wctc_simple( 'Gift card holder (no category)', '5', array(), '1' ) );
$ebook      = wctc_mk( 'ebook2', fn() => wctc_simple( 'Audiobook (virtual, Digital books)', '15', array(), '', array( 'virtual' => true, 'meta' => array( '_wctc_tax_category' => 'digital-books' ) ) ) );
$pantry     = wctc_mk( 'pantry', fn() => wctc_simple( 'Pantry box (Groceries)', '70', array(), '5', array( 'meta' => array( '_wctc_tax_category' => 'groceries' ) ) ) );
$ship_only  = wctc_mk( 'ship_only', fn() => wctc_simple( 'Poster (tax status: shipping only)', '12', $kitchen, '1', array( 'tax_status' => 'shipping' ) ) );
$cookbook   = get_option( 'wctc_stress_products' )['cookbook'] ?? 0;
$paperback  = 0;
if ( $cookbook ) {
	foreach ( wc_get_product( $cookbook )->get_children() as $child ) {
		if ( 'Paperback' === wc_get_product( $child )->get_attribute( 'format' ) ) {
			$paperback = $child;
		}
	}
}
$h = $ids['hoodie'];
$b = $ids['book'];
$m = $ids['mug'];
$j = $ids['jacket'];
$bl = $ids['blender'];

// Extra rates: Texas (no shipping rule), Canada/Quebec compound.
global $wpdb;
function wctc_rate_exists( $name ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = %s", $name ) ); // phpcs:ignore
}
if ( ! wctc_rate_exists( 'WCTC example TX State' ) ) {
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'TX', 'tax_rate' => '6.2500', 'tax_rate_name' => 'WCTC example TX State', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
}
if ( ! wctc_rate_exists( 'WCTC example GST' ) ) {
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CA', 'tax_rate_state' => 'QC', 'tax_rate' => '5.0000', 'tax_rate_name' => 'WCTC example GST', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'CA', 'tax_rate_state' => 'QC', 'tax_rate' => '9.9750', 'tax_rate_name' => 'WCTC example QST', 'tax_rate_priority' => 2, 'tax_rate_compound' => 1, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
}
$allowed = (array) get_option( 'woocommerce_specific_allowed_countries', array() );
foreach ( array( 'CA', 'BE' ) as $cc ) {
	if ( ! in_array( $cc, $allowed, true ) ) {
		$allowed[] = $cc;
		update_option( 'woocommerce_specific_allowed_countries', $allowed );
	}
}
if ( ! wctc_rate_exists( 'WCTC example IL State' ) ) {
	\WCTC\Examples::add_rates();
}

// Shipping zone and methods.
$zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'US and UK' === $z['zone_name'] ) {
		$zone_id = $z['id'];
	}
}
$zone = new WC_Shipping_Zone( $zone_id );
foreach ( array( 'CA', 'BE' ) as $cc ) {
	if ( ! in_array( $cc, array_column( $zone->get_zone_locations(), 'code' ), true ) ) {
		$zone->add_location( $cc, 'country' );
		$zone->save();
	}
}
$flat_id = 0;
$pickup_id = 0;
$free_id = 0;
foreach ( $zone->get_shipping_methods() as $mth ) {
	if ( 'flat_rate' === $mth->id ) {
		$flat_id = $mth->get_instance_id();
	}
	if ( 'local_pickup' === $mth->id ) {
		$pickup_id = $mth->get_instance_id();
	}
	if ( 'free_shipping' === $mth->id ) {
		$free_id = $mth->get_instance_id();
	}
}
if ( ! $pickup_id ) {
	$pickup_id = $zone->add_shipping_method( 'local_pickup' );
	update_option( 'woocommerce_local_pickup_' . $pickup_id . '_settings', array( 'title' => 'Local pickup', 'tax_status' => 'taxable', 'cost' => '5' ) );
}
if ( ! $free_id ) {
	$free_id = $zone->add_shipping_method( 'free_shipping' );
	update_option( 'woocommerce_free_shipping_' . $free_id . '_settings', array( 'title' => 'Free shipping', 'requires' => '' ) );
}
function wctc_flat( $cost, $tax_status = 'taxable' ) {
	global $flat_id;
	$opt               = get_option( 'woocommerce_flat_rate_' . $flat_id . '_settings' );
	$opt['cost']       = $cost;
	$opt['tax_status'] = $tax_status;
	update_option( 'woocommerce_flat_rate_' . $flat_id . '_settings', $opt );
	\WCTC\Store::bump();
}
/** Change fields on the shipping rule for a place. */
function wctc_ship_rule( $country, $state, $fields ) {
	$rules = \WCTC\Store::shipping_rules();
	foreach ( $rules as &$rule ) {
		if ( $rule['country'] === $country && $rule['state'] === $state ) {
			$rule = array_merge( $rule, $fields );
		}
	}
	\WCTC\Store::save_shipping_rules( $rules );
}
$FLAT = 'flat_rate:' . $flat_id;
$PICK = 'local_pickup:' . $pickup_id;
$FREE = 'free_shipping:' . $free_id;

// Coupons.
foreach ( array( 'book20' => array( 'fixed_product', 20, array( $b ) ), 'half' => array( 'percent', 50, array() ) ) as $code => $cfg ) {
	if ( ! wc_get_coupon_id_by_code( $code ) ) {
		$cp = new WC_Coupon();
		$cp->set_code( $code );
		$cp->set_discount_type( $cfg[0] );
		$cp->set_amount( $cfg[1] );
		$cp->set_product_ids( $cfg[2] );
		$cp->save();
	}
}

/**
 * Build and total a cart.
 *
 * @param array $ship   country, state, postcode, city.
 * @param array $items  product id => qty.
 * @param array $opts   method, coupons, billing, vat_exempt.
 * @return array lines (name => tax), line_totals, shipping, total, rates (method => tax), note
 */
function wctc_cart( $ship, $items, $opts = array() ) {
	global $FLAT;
	WC()->cart->empty_cart();
	WC()->cart->remove_coupons();
	WC()->session->set( 'shipping_for_package_0', null );
	$c = WC()->customer;
	$c->set_shipping_location( $ship[0], $ship[1], $ship[2], $ship[3] );
	$bill = $opts['billing'] ?? $ship;
	$c->set_billing_location( $bill[0], $bill[1], $bill[2], $bill[3] );
	$c->set_is_vat_exempt( ! empty( $opts['vat_exempt'] ) );
	$c->set_calculated_shipping( true );
	foreach ( $items as $id => $qty ) {
		WC()->cart->add_to_cart( $id, $qty );
	}
	foreach ( $opts['coupons'] ?? array() as $code ) {
		WC()->cart->apply_coupon( $code );
	}
	WC()->session->set( 'chosen_shipping_methods', array( $opts['method'] ?? $FLAT ) );
	WC()->cart->calculate_totals();
	WC()->session->set( 'chosen_shipping_methods', array( $opts['method'] ?? $FLAT ) );
	WC()->cart->calculate_totals();
	$lines = array();
	$totals = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$lines[ $item['data']->get_name() ]  = round( array_sum( $item['line_tax_data']['total'] ?? array() ), 2 );
		$totals[ $item['data']->get_name() ] = (float) $item['line_total'];
	}
	$rates = array();
	$note  = '';
	$packages = WC()->shipping()->get_packages();
	foreach ( $packages[0]['rates'] ?? array() as $rid => $rate ) {
		$rates[ $rid ] = round( array_sum( $rate->get_taxes() ), 2 );
		if ( $rid === ( $opts['method'] ?? $FLAT ) ) {
			$note = $rate->get_meta_data()['_wctc_shipping_tax'] ?? '';
		}
	}
	$c->set_is_vat_exempt( false );
	return array(
		'lines'       => $lines,
		'line_totals' => $totals,
		'shipping'    => round( (float) WC()->cart->get_shipping_tax(), 2 ),
		'total'       => round( (float) WC()->cart->get_total_tax(), 2 ),
		'rates'       => $rates,
		'note'        => $note,
	);
}

/** Place an order from the current cart through WC_Checkout. */
function wctc_order( $address ) {
	$posted = array( 'payment_method' => 'cod', 'billing_email' => 'suite@example.com', 'billing_phone' => '5555555555', 'ship_to_different_address' => 0 );
	$a = array( 'first_name' => 'Suite', 'last_name' => 'Buyer', 'address_1' => '1 Test St', 'city' => $address[3], 'state' => $address[1], 'postcode' => $address[2], 'country' => $address[0] );
	foreach ( $a as $k => $v ) {
		$posted[ 'billing_' . $k ]  = $v;
		$posted[ 'shipping_' . $k ] = $v;
	}
	$order_id = WC()->checkout()->create_order( $posted );
	return is_wp_error( $order_id ) ? null : wc_get_order( $order_id );
}

$NY = array( 'US', 'NY', '10001', 'New York' );
$AZ = array( 'US', 'AZ', '85001', 'Phoenix' );
$TX = array( 'US', 'TX', '73301', 'Austin' );
$GB = array( 'GB', '', 'SW1A 1AA', 'London' );
$HI = array( 'US', 'HI', '96813', 'Honolulu' );
$MN = array( 'US', 'MN', '55401', 'Minneapolis' );
$QC = array( 'CA', 'QC', 'H2X 1Y4', 'Montreal' );
$IL = array( 'US', 'IL', '60601', 'Chicago' );
$BE = array( 'BE', '', '1000', 'Brussels' );
$MA = array( 'US', 'MA', '02108', 'Boston' );
$RI = array( 'US', 'RI', '02903', 'Providence' );

\WCTC\Examples::load();
update_option( 'woocommerce_tax_based_on', 'shipping' );
update_option( 'woocommerce_prices_include_tax', 'no' );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
update_option( 'woocommerce_shipping_tax_class', 'inherit' );
wctc_flat( '10' );

// =====================================================================================================
// A. Opt-out parity: plugin active with both settings off must equal WooCommerce with the plugin gone.
// =====================================================================================================
$parity = array(
	'A1' => array( 'NYC hoodie + book', $NY, array( $h => 1, $b => 1 ), array() ),
	'A2' => array( 'UK book + mug', $GB, array( $b => 1, $m => 1 ), array() ),
	'A3' => array( 'Arizona blender (rate row Shipping unticked)', $AZ, array( $bl => 1 ), array() ),
	'A4' => array( 'Minnesota jacket + blender', $MN, array( $j => 1, $bl => 1 ), array() ),
	'A5' => array( 'NYC with $20 coupon on the book', $NY, array( $h => 1, $b => 1 ), array( 'coupons' => array( 'book20' ) ) ),
	'A6' => array( 'NYC local pickup', $NY, array( $h => 1, $b => 1 ), array( 'method' => $PICK ) ),
	'A7' => array( 'Billed NY, shipped AZ, tax by billing', $AZ, array( $h => 1, $b => 1 ), array( 'billing' => $NY, 'based_on' => 'billing' ) ),
	'A8' => array( 'Quebec compound rates', $QC, array( $h => 1, $b => 1 ), array() ),
	'A9' => array( 'Tax-exempt customer in NYC', $NY, array( $h => 1, $b => 1 ), array( 'vat_exempt' => true ) ),
);
foreach ( $parity as $id => $cfg ) {
	list( $name, $addr, $items, $opts ) = $cfg;
	update_option( 'woocommerce_tax_based_on', $opts['based_on'] ?? 'shipping' );
	wctc_set( false, false );
	wctc_detach();
	$stock = wctc_cart( $addr, $items, $opts );
	wctc_attach();
	$off = wctc_cart( $addr, $items, $opts );
	$same = $stock['lines'] === $off['lines'] && $stock['shipping'] === $off['shipping'] && $stock['total'] === $off['total'] && $stock['rates'] === $off['rates'];
	tc( $id, 'A. Opt-out parity', $name, 'Build the same cart with the plugin\'s hooks removed, then with the plugin active but both settings off. Compare every line tax, every shipping method\'s tax and the total.', 'identical', $same ? 'identical' : 'DIFFERENT: stock ' . wp_json_encode( $stock ) . ' vs off ' . wp_json_encode( $off ), 'Stock total tax ' . number_format( $stock['total'], 2 ) . ', shipping ' . number_format( $stock['shipping'], 2 ) . ( $off['note'] ? '. Off state wrongly left a note: ' . $off['note'] : '' ) );
}
update_option( 'woocommerce_tax_based_on', 'shipping' );

// Opt-out: product tax class getter and an order recalc.
wctc_set( false );
$p = wc_get_product( $h );
tc( 'A10', 'A. Opt-out parity', 'Product tax class with the feature off', 'Read the hoodie\'s tax class on the storefront with tax categories off (product category Clothing has a tax category and NY has a rule).', 'Standard (own class)', '' === $p->get_tax_class() ? 'Standard (own class)' : $p->get_tax_class(), 'The rule must not leak through when the setting is off.' );
wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$o = wctc_order( $NY );
$o->calculate_totals( true );
tc( 'A11', 'A. Opt-out parity', 'Order placed and recalculated with the feature off', 'Place the NYC order with the feature off, click Recalculate.', 6.66, $o->get_total_tax(), 'Stock WooCommerce: hoodie $3.99 + book $1.78 + shipping $0.89. Line notes must be absent: ' . ( $o->get_items() ? ( current( $o->get_items() )->get_meta( '_wctc_tax_category' ) ? 'PRESENT' : 'absent' ) : '' ) );
tc( 'A12', 'A. Opt-out parity', 'Order notes absent with the feature off', 'Check the order lines for tax category notes.', 'absent', current( $o->get_items() )->get_meta( '_wctc_tax_category' ) ? 'present' : 'absent' );
$o->delete( true );

// Turn the feature on for everything below.
wctc_set( true );

// =====================================================================================================
// B. Category resolution
// =====================================================================================================
$r = wctc_cart( $NY, array( $zip_hoodie => 1 ) );
tc( 'B1', 'B. Category resolution', 'Grandchild category inherits (Clothing → Hoodies → Zip hoodies)', 'Add a $50 product that sits only in "Zip hoodies", two levels under Clothing, ship to NYC.', 0.0, $r['lines']['Zip hoodie (grandchild category)'], 'Inherits Clothing; NY rule zero-rates it.' );

update_post_meta( $h, '_wctc_tax_category', 'groceries' );
$r = wctc_cart( $NY, array( $h => 1 ) );
tc( 'B2', 'B. Category resolution', 'Tax category set on the product beats the product category', 'Set the hoodie\'s own tax category to Groceries (its category says Clothing); ship to NYC.', 0.0, $r['lines']['Hoodie'], 'Groceries in NY → Zero rate. Source shown as "product".' );
$found = \WCTC\Resolver::category_for( wc_get_product( $h ) );
tc( 'B3', 'B. Category resolution', 'Source of the category is reported', 'Read where the hoodie\'s category came from.', 'product', $found['source'] );
delete_post_meta( $h, '_wctc_tax_category' );

if ( $paperback ) {
	$r = wctc_cart( $GB, array( $paperback => 1 ) );
	tc( 'B4', 'B. Category resolution', 'Variation without its own category inherits the parent product\'s', 'Add the Cookbook "Paperback" variation (parent in Books, no override), ship to the UK.', 0.0, $r['lines'][ wc_get_product( $paperback )->get_name() ] ?? -1, 'Books in GB → Zero rate.' );
}

delete_term_meta( $books, 'wctc_tax_category' );
\WCTC\Store::bump();
$r = wctc_cart( $GB, array( $b => 1 ) );
tc( 'B5', 'B. Category resolution', 'Removing a product category\'s tax category restores the product\'s own class', 'Clear the tax category on the Books product category, ship a book to the UK.', 4.0, $r['lines']['The Penderwicks at Last'], '£20 × 20% VAT.' );
update_term_meta( $books, 'wctc_tax_category', 'books' );
\WCTC\Store::bump();

$r = wctc_cart( $NY, array( $uncat => 1 ) );
tc( 'B6', 'B. Category resolution', 'Product with no product category', 'Add a $5 product in no category, ship to NYC.', 0.44, $r['lines']['Gift card holder (no category)'], 'Own class (Standard): $5 × 8.875%.' );

update_term_meta( $kitchen, 'wctc_tax_category', 'deleted-category' );
\WCTC\Store::bump();
$r = wctc_cart( $NY, array( $m => 1 ) );
tc( 'B7', 'B. Category resolution', 'Product category points at a tax category that no longer exists', 'Point the Kitchen category at a deleted tax category slug, ship a mug to NYC.', 0.89, $r['lines']['Mug'], 'Ignored: mug keeps Standard ($10 × 8.875%). The products list shows it as "Own tax class".' );
delete_term_meta( $kitchen, 'wctc_tax_category' );
\WCTC\Store::bump();

$r = wctc_cart( $NY, array( $ship_only => 1 ) );
tc( 'B8', 'B. Category resolution', 'Product with tax status "Shipping only"', 'Add a $12 poster whose tax status is "Shipping only", ship to NYC with $10 shipping.', 0.89, $r['shipping'], 'The item itself is untaxed (' . number_format( $r['lines']['Poster (tax status: shipping only)'], 2 ) . ') but its shipping is taxable, as the status says: $10 × 8.875%.' );

// =====================================================================================================
// C. Rule matching
// =====================================================================================================
$r = wctc_cart( $NY, array( $sale_coat => 1 ) );
tc( 'C1', 'C. Rule matching', 'Price limit uses the price actually charged (sale price)', 'A coat with regular price $120 on sale for $100, NY clothing rule "under $110", ship to NYC.', 0.0, $r['lines']['Sale coat ($120 on sale for $100)'], 'The $100 sale price is under the limit, so it is exempt.' );

$rules = \WCTC\Store::rules();
$base_rules = $rules;
$rules[] = array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '100*', 'max_price' => '', 'tax_class' => 'reduced-rate', 'start' => '', 'end' => '' );
\WCTC\Store::save_rules( $rules );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'NY', 'tax_rate' => '2.0000', 'tax_rate_name' => 'WCTC example NY reduced', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => 'reduced-rate' ) );
$r = wctc_cart( $NY, array( $h => 1 ) );
tc( 'C2', 'C. Rule matching', 'A ZIP-prefix rule beats the state rule', 'Add a Clothing rule for NY ZIPs 100* → Reduced rate (2%) alongside the state rule → Zero rate; ship to 10001.', 0.90, $r['lines']['Hoodie'], '$45 × 2%: the more specific rule wins.' );
$r = wctc_cart( array( 'US', 'NY', '11201', 'Brooklyn' ), array( $h => 1 ) );
tc( 'C3', 'C. Rule matching', 'Outside the ZIP prefix the state rule applies', 'Same rules, ship to 11201.', 0.0, $r['lines']['Hoodie'], 'Zero rate.' );
\WCTC\Store::save_rules( $base_rules );

$rules = $base_rules;
$rules[0]['start'] = '2020-01-01';
$rules[0]['end']   = '2020-12-31';
\WCTC\Store::save_rules( $rules );
$r = wctc_cart( $NY, array( $h => 1 ) );
tc( 'C4', 'C. Rule matching', 'Expired rule does not apply', 'Give the NY clothing rule an end date in 2020, ship a hoodie to NYC.', 3.99, $r['lines']['Hoodie'], 'Own class: $45 × 8.875%.' );
$rules[0]['start'] = '2030-01-01';
$rules[0]['end']   = '';
\WCTC\Store::save_rules( $rules );
$r = wctc_cart( $NY, array( $h => 1 ) );
tc( 'C5', 'C. Rule matching', 'Future rule does not apply yet', 'Give the NY clothing rule a start date in 2030.', 3.99, $r['lines']['Hoodie'] );
\WCTC\Store::save_rules( $base_rules );

$rules = $base_rules;
$rules[] = array( 'category' => 'digital-books', 'country' => '*', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' );
\WCTC\Store::save_rules( $rules );
$r = wctc_cart( $TX, array( $ebook => 1 ) );
tc( 'C6', 'C. Rule matching', 'A rule with country "*" applies everywhere', 'Add Digital books → Zero rate for country *, ship a $15 audiobook to Texas.', 0.0, $r['lines']['Audiobook (virtual, Digital books)'] );
\WCTC\Store::save_rules( $base_rules );

tc( 'C7', 'C. Rule matching', 'Place codes match regardless of case', 'Match rule state "ny" against address state "NY".', true, \WCTC\Engine::place_matches( 'ny', 'NY' ), 'Typed codes are uppercased on save as well.' );

$rules = $base_rules;
$rules[] = array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'reduced-rate', 'start' => '', 'end' => '' );
\WCTC\Store::save_rules( $rules );
$r = wctc_cart( $NY, array( $h => 1 ) );
tc( 'C8', 'C. Rule matching', 'Two rules with the same place: the first in the table wins', 'Add a second NY clothing rule (Reduced rate, no price limit) below the Zero rate one.', 0.0, $r['lines']['Hoodie'], 'First row wins. Nothing warns the merchant about the overlap: see UX findings.' );
\WCTC\Store::save_rules( $base_rules );

// =====================================================================================================
// D. Shipping
// =====================================================================================================
$r = wctc_cart( $NY, array( $ebook => 1 ) );
tc( 'D1', 'D. Shipping', 'Virtual-only cart has no shipping', 'Cart with only a virtual audiobook, ship to NYC.', 0.0, $r['shipping'], 'No shipping line and no error. Item tax: ' . number_format( $r['lines']['Audiobook (virtual, Digital books)'], 2 ) );

$r = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
tc( 'D2', 'D. Shipping', 'Every offered shipping method is re-taxed, not just the chosen one', 'NYC hoodie + book: read the tax on Flat rate, Local pickup and Free shipping.', '0.27 / 0.14 / 0.00', number_format( $r['rates'][ $FLAT ] ?? -1, 2 ) . ' / ' . number_format( $r['rates'][ $PICK ] ?? -1, 2 ) . ' / ' . number_format( $r['rates'][ $FREE ] ?? -1, 2 ), 'Flat rate $10 split by value; pickup $5 taxed at the store (NY); free shipping has nothing to tax.' );

$r = wctc_cart( $MN, array( $j => 1, $no_weight => 1 ) );
tc( 'D3', 'D. Shipping', 'Weight split when one item has no weight', 'Minnesota (split by weight): $60 jacket 2 lb + $40 lamp with no weight, $10 shipping.', 0.28, $r['shipping'], 'A missing weight would make the lamp weightless and all shipping exempt. Instead the split falls back to value: $4 taxable × 6.875%. Note: ' . $r['note'] );

wctc_flat( '10', 'none' );
$r = wctc_cart( $HI, array( $h => 1 ) );
tc( 'D4', 'D. Shipping', 'Shipping method marked "not taxable" is never taxed, even in Hawaii', 'Set Flat rate tax status to None, ship to Hawaii (rule: always taxed).', 0.0, $r['shipping'], 'The method\'s own setting wins, as in stock WooCommerce.' );
wctc_flat( '10' );

update_option( 'woocommerce_prices_include_tax', 'yes' );
\WCTC\Store::bump();
$r = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$net_book  = $r['line_totals']['The Penderwicks at Last'];
$net_total = array_sum( $r['line_totals'] );
$exp       = round( round( 10 * $net_book / $net_total, 2 ) * 0.08875, 2 );
tc( 'D5', 'D. Shipping', 'Prices entered inclusive of tax', 'Switch the store to prices inclusive of tax, NYC hoodie + book, $10 shipping.', $exp, $r['shipping'], 'Split uses net (ex-tax) line totals: book net ' . number_format( $net_book, 2 ) . ' of ' . number_format( $net_total, 2 ) . '. Item taxes: ' . wp_json_encode( $r['lines'] ) );
update_option( 'woocommerce_prices_include_tax', 'no' );
\WCTC\Store::bump();

update_option( 'woocommerce_tax_round_at_subtotal', 'yes' );
$r = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
tc( 'D6', 'D. Shipping', 'Round tax at subtotal level', 'Turn on "Round tax at subtotal level", NYC hoodie + book.', 2.05, $r['total'], 'Shipping tax ' . number_format( $r['shipping'], 2 ) );
update_option( 'woocommerce_tax_round_at_subtotal', 'no' );

$r = wctc_cart( $QC, array( $h => 1, $b => 1 ) );
tc( 'D7', 'D. Shipping', 'Compound rates (Quebec GST 5% + QST 9.975% compound)', 'Ship hoodie + book to Montreal; no Canadian category rules, so everything is Standard and shipping follows.', 1.55, $r['shipping'], '$10 × 5% = 0.50; 9.975% of $10.50 = 1.05; total 1.55. Item tax: ' . number_format( $r['total'] - $r['shipping'], 2 ) . ' (expected 10.06).' );

update_option( 'woocommerce_tax_based_on', 'base' );
\WCTC\Store::bump();
$r = wctc_cart( $AZ, array( $h => 1, $b => 1 ) );
tc( 'D8', 'D. Shipping', 'Tax based on the store address', 'Store taxes everything at its own (NY) address; customer in Arizona orders hoodie + book.', 0.27, $r['shipping'], 'NY rules and rates apply. Hoodie tax: ' . number_format( $r['lines']['Hoodie'], 2 ) . ' (expected 0.00).' );
update_option( 'woocommerce_tax_based_on', 'shipping' );
\WCTC\Store::bump();

wctc_flat( '5 + 3 * [qty]' );
$r = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
tc( 'D9', 'D. Shipping', 'Flat rate with a base fee plus a per-item amount', 'Flat rate "5 + 3 * [qty]" ($11): NYC hoodie + book.', 0.40, $r['shipping'], 'Each item\'s $3 stays with it (book\'s $3 taxed); the $5 base is split by value (book 30.8% = $1.54). ($3 + $1.54) × 8.875%.' );
wctc_flat( '8 * [qty]' );
$r = wctc_cart( $NY, array( $h => 2, $b => 1 ) );
tc( 'D10', 'D. Shipping', 'Per-item flat rate with quantities', 'Flat rate "8 * [qty]": 2 hoodies + 1 book to NYC ($24 shipping).', 0.71, $r['shipping'], 'Only the book\'s $8 is taxed.' );
wctc_flat( '10' );

$r = wctc_cart( $NY, array( $h => 1, $b => 1 ), array( 'coupons' => array( 'half' ) ) );
tc( 'D11', 'D. Shipping', '50% coupon on everything keeps the same split', 'Apply a 50% cart coupon to NYC hoodie + book.', 0.27, $r['shipping'], 'Both lines halve, so the shares are unchanged. Book tax: ' . number_format( $r['lines']['The Penderwicks at Last'], 2 ) . ' (expected 0.89).' );

$r = wctc_cart( $NY, array( $h => 1, $b => 1 ), array( 'method' => $FREE ) );
tc( 'D12', 'D. Shipping', 'Free shipping chosen', 'Choose Free shipping for NYC hoodie + book.', 0.0, $r['shipping'], 'Total tax ' . number_format( $r['total'], 2 ) . ' (expected 1.78).' );

// Belgium: whole charge at the lowest rate in the box. Book → Reduced (6%) by rule; mug stays Standard (21%).
$r = wctc_cart( $BE, array( $b => 1, $m => 1 ) );
tc( 'D13', 'D. Shipping', 'Belgium: whole charge at the lowest rate in the box', 'Ship a €20 book (Books → Reduced 6%) + €10 mug (Standard 21%) to Brussels with €10 shipping.', 0.60, $r['shipping'], 'Example shipping rule for BE uses "lowest rate". Whole €10 at 6%, not a 2/3–1/3 split (which would be €1.50). Note: ' . $r['note'] );
$r = wctc_cart( $BE, array( $m => 1, $bl => 1 ) );
tc( 'D14', 'D. Shipping', 'Belgium: all goods at one rate', 'Mug + blender (both Standard) to Brussels.', 2.10, $r['shipping'], '€10 × 21%: nothing to choose between.' );

// Illinois: whole charge at the rate of the majority of the value; no majority → split by value.
wctc_ship_rule( 'US', 'IL', array( 'conditions_met' => 0 ) );
$r = wctc_cart( $IL, array( $pantry => 1, $m => 1 ) );
tc( 'D15', 'D. Shipping', 'Illinois: majority of value is groceries', 'Ship a $70 pantry box (Groceries → Reduced 1%) + $10 mug (6.25%) to Chicago, $10 shipping, delivery terms not met.', 0.10, $r['shipping'], 'Groceries hold 87.5% of the value, so the whole $10 is taxed at 1%. Note: ' . $r['note'] );
$r = wctc_cart( $IL, array( $pantry => 1, $m => 7 ) );
tc( 'D16', 'D. Shipping', 'Illinois: no majority (50/50) falls back to a value split', '$70 pantry box + 7 mugs ($70) to Chicago.', 0.36, $r['shipping'], '$5 at 1% + $5 at 6.25% = $0.05 + $0.31. Note: ' . $r['note'] );
wctc_ship_rule( 'US', 'IL', array( 'conditions_met' => 1 ) );
$r = wctc_cart( $IL, array( $pantry => 1, $m => 1 ) );
tc( 'D17', 'D. Shipping', 'Illinois: delivery terms met → shipping exempt', 'Tick "Conditions met" on the IL rule (customer could pick up or ship separately), same cart.', 0.0, $r['shipping'], 'The delivery-terms rule decides whether shipping is taxed at all; the majority test only applies when it is.' );
wctc_ship_rule( 'US', 'IL', array( 'conditions_met' => 0 ) );

// ZIP-level shipping rule beats the state rule.
$ship_rules   = \WCTC\Store::shipping_rules();
$ship_rules[] = array( 'country' => 'US', 'state' => 'NY', 'postcode' => '100*', 'mode' => \WCTC\Engine::MODE_EXEMPT_STATED, 'conditions_met' => 0, 'split' => 'value' );
\WCTC\Store::save_shipping_rules( $ship_rules );
$r = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$r2 = wctc_cart( array( 'US', 'NY', '11201', 'Brooklyn' ), array( $h => 1, $b => 1 ) );
tc( 'D18', 'D. Shipping', 'A ZIP-prefix shipping rule beats the state rule', 'Add a shipping rule for NY ZIPs 100* → exempt, beside the NY state rule (follows). Ship to 10001, then Brooklyn 11201.', '0.00 / 0.12', number_format( $r['shipping'], 2 ) . ' / ' . number_format( $r2['shipping'], 2 ), 'Manhattan ZIP exempt by the ZIP rule. Brooklyn follows the goods under the state rule: the book\'s $3.08 share at the 4% state rate only, since the example NYC + MCTD rate row is limited to the city "New York".' );
array_pop( $ship_rules );
\WCTC\Store::save_shipping_rules( $ship_rules );

// =====================================================================================================
// E. Order editing
// =====================================================================================================
wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$o = wctc_order( $NY );
$o->set_address( array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '85001', 'city' => 'Phoenix', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'shipping' );
$o->set_address( array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '85001', 'city' => 'Phoenix', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'billing' );
$o->save();
$o->calculate_totals( true );
$hood = null;
foreach ( $o->get_items() as $it ) {
	if ( 'Hoodie' === $it->get_name() ) {
		$hood = $it;
	}
}
tc( 'E1', 'E. Order editing', 'Change the order\'s address, then Recalculate', 'Place the NYC order, change both addresses to Arizona, click Recalculate.', 2.52, $hood ? $hood->get_total_tax() : -1, 'Arizona has no clothing rule, so the hoodie goes back to Standard: $45 × 5.6%. Shipping tax: ' . number_format( $o->get_shipping_tax(), 2 ) . ' (expected 0.00, AZ exempts shipping).' );
tc( 'E2', 'E. Order editing', 'Shipping tax after the address change', 'Same order.', 0.0, $o->get_shipping_tax() );
tc( 'E3', 'E. Order editing', 'Line notes follow the new address', 'Read the hoodie\'s Tax rule note after Recalculate.', 'No rule for this place → own class', $hood ? $hood->get_meta( '_wctc_tax_rule' ) : '' );

$o->set_address( array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'shipping' );
$o->set_address( array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'billing' );
$o->save();
$o->add_product( wc_get_product( $m ), 1 );
$o->calculate_totals( true );
tc( 'E4', 'E. Order editing', 'Add an item in admin, then Recalculate', 'Move the order back to NYC, add a $10 mug in admin, Recalculate.', 3.03, $o->get_total_tax(), 'Book $1.78 + mug $0.89 + shipping on the taxable 40% of $75 ($4 × 8.875% = $0.36). Shipping tax: ' . number_format( $o->get_shipping_tax(), 2 ) );

foreach ( $o->get_items() as $iid => $it ) {
	if ( 'Hoodie' !== $it->get_name() ) {
		$o->remove_item( $iid );
	}
}
$o->calculate_totals( true );
tc( 'E5', 'E. Order editing', 'Remove every taxable item, then Recalculate', 'Remove the book and mug so only the exempt hoodie is left, Recalculate.', 0.0, $o->get_total_tax(), 'Shipping follows the (exempt) goods.' );

$o->update_meta_data( 'is_vat_exempt', 'yes' );
$o->save();
$o->add_product( wc_get_product( $b ), 1 );
$o->calculate_totals( true );
tc( 'E6', 'E. Order editing', 'Order flagged tax-exempt, then Recalculate', 'Mark the order VAT/tax exempt, add the book back, Recalculate.', 0.0, $o->get_total_tax() );
$o->update_meta_data( 'is_vat_exempt', 'no' );
$o->save();
$o->calculate_totals( true );
$ship_item = current( $o->get_items( 'shipping' ) );
$refund = wc_create_refund(
	array(
		'order_id'   => $o->get_id(),
		'amount'     => 10.27,
		'line_items' => array( $ship_item->get_id() => array( 'qty' => 0, 'refund_total' => 10, 'refund_tax' => array_map( fn( $t ) => $t, $ship_item->get_taxes()['total'] ) ) ),
	)
);
$refunded = is_wp_error( $refund ) ? -1 : round( (float) $o->get_total_tax_refunded(), 2 );
tc( 'E7', 'E. Order editing', 'Refund the shipping line with its split tax', 'Refund the full $10 shipping and its tax on the NYC order.', 0.27, $refunded, is_wp_error( $refund ) ? $refund->get_error_message() : 'Refunds use WooCommerce\'s own tax rows, so the split carries through. Shipping refunded: ' . number_format( $o->get_total_shipping_refunded(), 2 ) );
$suite_order_id = $o->get_id();

// =====================================================================================================
// F. Store API (block cart and checkout)
// =====================================================================================================
$classic = wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$req = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
$res = rest_do_request( $req );
$data = json_decode( wp_json_encode( $res->get_data() ), true );
$api_tax = isset( $data['totals']['total_tax'] ) ? (int) $data['totals']['total_tax'] / 100 : -1;
$api_ship_tax = isset( $data['totals']['total_shipping_tax'] ) ? (int) $data['totals']['total_shipping_tax'] / 100 : -1;
tc( 'F1', 'F. Store API', 'Block cart totals match the classic cart', 'GET /wc/store/v1/cart for the NYC hoodie + book cart.', number_format( $classic['total'], 2 ) . ' / ' . number_format( $classic['shipping'], 2 ), number_format( $api_tax, 2 ) . ' / ' . number_format( $api_ship_tax, 2 ), 'total tax / shipping tax.' );

// =====================================================================================================
// G. Analytics
// =====================================================================================================
wctc_cart( $NY, array( $h => 1, $b => 1 ) );
$ao = wctc_order( $NY );
$ao->update_status( 'completed' );
$synced = false;
if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore' ) ) {
	\Automattic\WooCommerce\Admin\API\Reports\Taxes\DataStore::sync_order_taxes( $ao->get_id() );
	$synced = true;
}
if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore' ) ) {
	\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $ao->get_id() );
}
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT l.tax_rate_id, r.tax_rate_name, l.order_tax, l.shipping_tax, l.total_tax FROM {$wpdb->prefix}wc_order_tax_lookup l LEFT JOIN {$wpdb->prefix}woocommerce_tax_rates r ON r.tax_rate_id = l.tax_rate_id WHERE l.order_id = %d ORDER BY l.tax_rate_id", $ao->get_id() ), ARRAY_A ); // phpcs:ignore
$by_name = array();
foreach ( $rows as $row ) {
	$by_name[ $row['tax_rate_name'] ] = $row;
}
tc( 'G1', 'G. Analytics', 'Analytics tax lookup rows for the NYC order', 'Complete the NYC order and sync it into the Analytics tax lookup table (what Analytics → Taxes reads).', 'NY State: order 0.80 / shipping 0.12 · NYC + MCTD: order 0.98 / shipping 0.15', implode( ' · ', array_map( fn( $r ) => str_replace( 'WCTC example ', '', (string) $r['tax_rate_name'] ) . ': order ' . number_format( $r['order_tax'], 2 ) . ' / shipping ' . number_format( $r['shipping_tax'], 2 ), $rows ) ), $synced ? 'Shipping tax is attributed per rate with the split applied.' : 'Analytics data store not available in this WooCommerce build.' );
$exp_names = array( 'WCTC example NY State', 'WCTC example NYC + MCTD' );
tc( 'G2', 'G. Analytics', 'Zero-rated sales do not appear in the Taxes report', 'Look for a row for the hoodie\'s Zero rate class.', 'no row (Zero rate has no rate ID)', count( $rows ) === 2 && ! array_diff( array_keys( $by_name ), $exp_names ) ? 'no row (Zero rate has no rate ID)' : 'unexpected rows: ' . wp_json_encode( array_keys( $by_name ) ), 'Same as stock WooCommerce: the Taxes report is keyed by rate row, so exempt and zero-rated amounts are invisible there. A merchant can\'t see how much they sold tax-free. See findings.' );

// Playground runs WordPress on SQLite, which has no SUBSTRING_INDEX(). Stock WooCommerce's Taxes list report
// uses it, so the report errors on Playground with or without this plugin. Shim it so the report can be checked.
if ( isset( $wpdb->dbh ) && is_object( $wpdb->dbh ) && ( $wpdb->dbh instanceof PDO || method_exists( $wpdb->dbh, 'get_sqlite_pdo' ) ) ) {
	$pdo = method_exists( $wpdb->dbh, 'get_sqlite_pdo' ) ? $wpdb->dbh->get_sqlite_pdo() : $wpdb->dbh;
	if ( $pdo instanceof PDO && method_exists( $pdo, 'sqliteCreateFunction' ) ) {
		$pdo->sqliteCreateFunction(
			'SUBSTRING_INDEX',
			function ( $s, $d, $c ) {
				$parts = explode( (string) $d, (string) $s );
				$c     = (int) $c;
				if ( $c > 0 ) {
					return implode( $d, array_slice( $parts, 0, $c ) );
				}
				return $c < 0 ? implode( $d, array_slice( $parts, $c ) ) : '';
			},
			3
		);
	}
}
if ( class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Cache' ) ) {
	\Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
}
$req = new WP_REST_Request( 'GET', '/wc-analytics/reports/taxes' );
$req->set_param( 'after', gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) . 'T00:00:00' );
$req->set_param( 'before', gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . 'T23:59:59' );
$req->set_param( 'per_page', 100 );
$res = rest_do_request( $req );
$rep = json_decode( wp_json_encode( $res->get_data() ), true );
$rep = is_array( $rep ) ? $rep : array();
$rep_by = array();
foreach ( $rep as $row ) {
	$rep_by[ $row['tax_rate_id'] ] = $row;
}
$ny_row = $by_name['WCTC example NY State'] ?? null;
$ny_rep = $ny_row && isset( $rep_by[ $ny_row['tax_rate_id'] ] ) ? $rep_by[ $ny_row['tax_rate_id'] ] : null;
tc( 'G3', 'G. Analytics', 'Analytics → Taxes REST report includes the split shipping tax', 'GET /wc-analytics/reports/taxes for today; read the NY State row.', 'NY State: order 0.80 / shipping 0.12', $ny_rep ? 'NY State: order ' . number_format( $ny_rep['order_tax'], 2 ) . ' / shipping ' . number_format( $ny_rep['shipping_tax'], 2 ) : 'row missing (' . count( $rep ) . ' rows returned)', $ny_rep ? 'Across ' . $ny_rep['orders_count'] . ' order(s) today. The report is keyed by rate, so the shipping tax column shows the split amount per rate.' : '' );

$req = new WP_REST_Request( 'GET', '/wc-analytics/reports/taxes/stats' );
$req->set_param( 'after', gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) . 'T00:00:00' );
$req->set_param( 'before', gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) . 'T23:59:59' );
$res = rest_do_request( $req );
$stats = json_decode( wp_json_encode( $res->get_data() ), true );
tc( 'G4', 'G. Analytics', 'Analytics → Taxes summary stats match the order', 'GET /wc-analytics/reports/taxes/stats for today; read totals.', 'total tax 2.05 / shipping tax 0.27', isset( $stats['totals']['total_tax'] ) ? 'total tax ' . number_format( $stats['totals']['total_tax'], 2 ) . ' / shipping tax ' . number_format( $stats['totals']['shipping_tax'], 2 ) : 'missing: ' . wp_json_encode( $stats ), 'Same numbers as the order: 1.78 item tax + 0.27 shipping tax.' );

$tax_items = array();
foreach ( $ao->get_items( 'tax' ) as $t ) {
	$tax_items[ $t->get_label() ] = array( 'tax' => round( (float) $t->get_tax_total(), 2 ), 'shipping' => round( (float) $t->get_shipping_tax_total(), 2 ) );
}
tc( 'G5', 'G. Analytics', 'Legacy order tax rows (used by Reports → Taxes and invoices)', 'Read the order\'s tax line items.', 'NY State 0.80/0.12 · NYC + MCTD 0.98/0.15', implode( ' · ', array_map( fn( $k, $v ) => str_replace( 'WCTC example ', '', $k ) . ' ' . number_format( $v['tax'], 2 ) . '/' . number_format( $v['shipping'], 2 ), array_keys( $tax_items ), $tax_items ) ), 'Item tax / shipping tax per rate.' );
$analytics_order_id = $ao->get_id();

// =====================================================================================================
// H. Merchant setup mistakes (through the settings save path)
// =====================================================================================================
global $current_section;
$current_section = 'tax_categories';
/** Read and clear the settings errors WooCommerce would show after Save. */
function wctc_errors() {
	$ref = new ReflectionProperty( 'WC_Admin_Settings', 'errors' );
	$ref->setAccessible( true );
	$errors = (array) $ref->getValue();
	$ref->setValue( null, array() );
	return $errors;
}
function wctc_post_rules( $rules, $cats = null, $ship = null, $new = null ) {
	wctc_errors();
	$_POST['wctc_rule'] = $rules;
	if ( null !== $new ) {
		$_POST['wctc_cat_new'] = $new;
	}
	$_POST['wctc_cat']  = $cats ?? array_map( fn( $c ) => array( 'name' => $c['name'], 'provider_code' => $c['provider_code'] ?? '' ), \WCTC\Store::categories() );
	$_POST['wctc_ship'] = $ship ?? array_map( fn( $s ) => $s + array( 'conditions_met' => $s['conditions_met'] ? '1' : '' ), \WCTC\Store::shipping_rules() );
	unset( $_POST['wctc_cat_new'] );
	\WCTC\Admin\Settings::save_tables();
	unset( $_POST['wctc_rule'], $_POST['wctc_cat'], $_POST['wctc_ship'] );
}
wctc_errors();
$saved_rules = \WCTC\Store::rules();
$saved_cats  = \WCTC\Store::categories();
$saved_ship  = \WCTC\Store::shipping_rules();

wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'us', 'state' => 'ny', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'zero-rate' ) ) );
$got = \WCTC\Store::rules();
tc( 'H1', 'H. Merchant setup', 'Lowercase place codes are normalised', 'Save a rule typed as country "us", state "ny", blank postcode.', 'US / NY / *', $got ? $got[0]['country'] . ' / ' . $got[0]['state'] . ' / ' . $got[0]['postcode'] : 'no rule saved' );
wctc_errors();
wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'United States (US)', 'state' => 'New York', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'zero-rate' ) ) );
$got = \WCTC\Store::rules();
tc( 'H1b', 'H. Merchant setup', 'Typed country and state names become codes', 'Save a rule typed as country "United States (US)", state "New York".', 'US / NY', $got ? $got[0]['country'] . ' / ' . $got[0]['state'] : 'no rule saved', 'The country is a picker; the state box offers that country\'s states and accepts a name.' );

wctc_post_rules( array( array( 'category' => 'clothing', 'country' => '', 'state' => 'NY', 'postcode' => '', 'max_price' => '', 'tax_class' => 'zero-rate' ) ) );
$errs = wctc_errors();
tc( 'H2', 'H. Merchant setup', 'Rule with no country is rejected with a message', 'Save a rule with a blank country.', 'not saved; message shown', 0 === count( \WCTC\Store::rules() ) && $errs && false !== strpos( $errs[0], 'pick a country' ) ? 'not saved; message shown' : 'rules ' . count( \WCTC\Store::rules() ) . '; errors ' . wp_json_encode( $errs ), $errs ? 'Message: ' . $errs[0] : '' );

wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => 'ten dollars', 'tax_class' => 'zero-rate' ) ) );
$got = \WCTC\Store::rules();
$errs = wctc_errors();
tc( 'H3', 'H. Merchant setup', 'Non-numeric price limit is rejected with a message', 'Save a rule with price limit "ten dollars".', 'not saved; message shown', ! $got && $errs && false !== strpos( $errs[0], 'not a number' ) ? 'not saved; message shown' : 'rules ' . count( $got ) . '; errors ' . wp_json_encode( $errs ), $errs ? 'Message: ' . $errs[0] : 'Before the fix this silently became "any price".' );

wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => '', 'tax_class' => 'zero-rate', 'start' => '2026-12-31', 'end' => '2026-01-01' ) ) );
$r = wctc_cart( $NY, array( $h => 1 ) );
$errs = wctc_errors();
tc( 'H4', 'H. Merchant setup', 'End date before start date is rejected with a message', 'Save a rule that ends before it starts, then ship a hoodie to NYC.', 3.99, $r['lines']['Hoodie'], ( $errs && false !== strpos( $errs[0], 'before it starts' ) ? 'Message: ' . $errs[0] : 'NO MESSAGE' ) . ' Hoodie keeps Standard: $45 × 8.875%.' );

wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'zero-rate' ) ), array( 'groceries' => array( 'name' => 'Groceries' ), 'books' => array( 'name' => 'Books' ), 'digital-books' => array( 'name' => 'Digital books' ), 'clothing' => array( 'name' => 'Clothing', 'remove' => '1' ) ) );
tc( 'H5', 'H. Merchant setup', 'Removing a tax category that rules point at', 'Tick Remove on the Clothing category while its rule is still in the table, save.', 'category and its rule both gone, products fall back to own class', ! isset( \WCTC\Store::categories()['clothing'] ) && 0 === count( \WCTC\Store::rules() ) ? 'category and its rule both gone, products fall back to own class' : 'cats ' . implode( ',', array_keys( \WCTC\Store::categories() ) ) . '; rules ' . count( \WCTC\Store::rules() ), 'The rule is deleted silently and product categories still point at the deleted slug. UX finding: confirm before removing, or block removal while rules or categories use it.' );
\WCTC\Store::save_categories( $saved_cats );
\WCTC\Store::save_rules( $saved_rules );
\WCTC\Store::save_shipping_rules( $saved_ship );

wctc_post_rules( array_map( fn( $r ) => $r, $saved_rules ), null, null, array( 'name' => 'Clothing', 'provider_code' => '' ) );
$errs = wctc_errors();
tc( 'H6', 'H. Merchant setup', 'Adding a category whose name already exists', 'Type "Clothing" into the new category row and save.', 'kept as one category, provider code kept, message shown', 1 === count( array_filter( array_keys( \WCTC\Store::categories() ), fn( $k ) => 'clothing' === $k ) ) && 'txcd_30011000' === ( \WCTC\Store::categories()['clothing']['provider_code'] ?? '' ) && $errs ? 'kept as one category, provider code kept, message shown' : wp_json_encode( array( array_keys( \WCTC\Store::categories() ), \WCTC\Store::categories()['clothing']['provider_code'] ?? '', $errs ) ), $errs ? 'Message: ' . $errs[0] : '' );
\WCTC\Store::save_categories( $saved_cats );
\WCTC\Store::save_rules( $saved_rules );
\WCTC\Store::save_shipping_rules( $saved_ship );

$rules = $saved_rules;
$rules[] = array( 'category' => 'clothing', 'country' => 'US', 'state' => 'TX', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'gone-class', 'start' => '', 'end' => '' );
\WCTC\Store::save_rules( $rules );
$r = wctc_cart( $TX, array( $h => 1 ) );
tc( 'H7', 'H. Merchant setup', 'Rule points at a tax class that was deleted', 'Rule Clothing in TX → a class that no longer exists; ship a hoodie to Texas.', 2.81, $r['lines']['Hoodie'], 'Skipped like a class with no rates: own class, $45 × 6.25%. Flagged under "Rate there".' );
\WCTC\Store::save_rules( $saved_rules );

update_term_meta( $clothing, 'wctc_tax_category', 'clothing' );
wp_delete_term( $zips, 'product_cat' );
$r = wctc_cart( $NY, array( $zip_hoodie => 1 ) );
tc( 'H8', 'H. Merchant setup', 'Deleting a product category a product relied on', 'Delete the "Zip hoodies" product category; the zip hoodie lands in Uncategorized; ship to NYC.', 4.44, $r['lines']['Zip hoodie (grandchild category)'], 'Loses Clothing, so it is taxed at Standard ($50 × 8.875%). Expected WooCommerce behaviour, but silent. UX finding: the products list shows "Own tax class" so it can be found.' );

wctc_errors();
wctc_post_rules( array(
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'zero-rate' ),
	array( 'category' => 'clothing', 'country' => 'us', 'state' => 'ny', 'postcode' => '*', 'max_price' => '110', 'tax_class' => 'reduced-rate' ),
) );
$errs = wctc_errors();
$dups = \WCTC\Engine::duplicate_category_rules( \WCTC\Store::rules() );
tc( 'H9', 'H. Merchant setup', 'Two rules for the same category, place and limit', 'Save a second NY clothing rule under $110 (Reduced) below the Zero rate one.', 'both saved, row 2 flagged as a duplicate of row 1, message shown', 2 === count( \WCTC\Store::rules() ) && array( 1 => 0 ) === $dups && $errs ? 'both saved, row 2 flagged as a duplicate of row 1, message shown' : 'rules ' . count( \WCTC\Store::rules() ) . '; dups ' . wp_json_encode( $dups ) . '; errors ' . wp_json_encode( $errs ), $errs ? 'The row is highlighted in the table with "Same as row 1, which wins". Message: ' . $errs[0] : '' );
wctc_post_rules( array(
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => '', 'tax_class' => 'reduced-rate' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'zero-rate' ),
) );
$errs = wctc_errors();
$r  = wctc_cart( $NY, array( $h => 1 ) );
$r2 = wctc_cart( $NY, array( $ids['coat'] => 1 ) );
tc( 'H10', 'H. Merchant setup', 'Tiered rules in either order: the tighter price limit wins', 'Save "NY clothing any price → Reduced 2%" above "NY clothing under $110 → Zero". Ship a $45 hoodie, then a $150 coat.', '0.00 / 3.00', number_format( $r['lines']['Hoodie'], 2 ) . ' / ' . number_format( $r2['lines'][ wc_get_product( $ids['coat'] )->get_name() ], 2 ), ( $errs ? 'Unexpected message: ' . $errs[0] : 'No duplicate warning: different limits are a tier, not a duplicate.' ) . ' Hoodie exempt under the $110 rule; coat $150 × 2% under the open rule.' );
\WCTC\Store::save_rules( $saved_rules );

// =====================================================================================================
// I. Excess-only price limits (MA $175, RI $250): first N exempt per item, excess taxed at Standard.
// =====================================================================================================
wctc_set( true );
WC()->cart->empty_cart();

// MA under limit: $60 jacket (Clothing) in Boston → fully exempt.
$r = wctc_cart( $MA, array( $j => 1 ) );
tc( 'I1', 'I. Excess-only limits', 'MA $60 jacket (under $175)', 'Ship a $60 jacket (Clothing) to Boston. Example MA rule: first $175 per item exempt.', 0.0, $r['lines']['Jacket'], 'Jacket well under the threshold → fully exempt. Shipping tax: ' . number_format( $r['shipping'], 2 ) );

// MA at the Winter coat $150 → still under $175 → fully exempt.
$r = wctc_cart( $MA, array( $ids['coat'] => 1 ) );
tc( 'I2', 'I. Excess-only limits', 'MA $150 winter coat (under $175)', 'Ship a $150 winter coat (Clothing) to Boston.', 0.0, $r['lines']['Winter coat'], 'Coat under the $175 threshold → fully exempt. Shipping tax: ' . number_format( $r['shipping'], 2 ) );

// Create a $200 Clothing product for over-limit cases.
$ma_coat = wctc_mk( 'ma_coat', fn() => wctc_simple( 'MA test coat ($200 Clothing)', '200', $clothing, '3' ) );
$r = wctc_cart( $MA, array( $ma_coat => 1 ) );
tc( 'I3', 'I. Excess-only limits', 'MA $200 coat: tax only the $25 excess', 'Ship a $200 Clothing coat to Boston. Excess $175 → taxable $25 at MA 6.25% = $1.56.', 1.56, $r['lines']['MA test coat ($200 Clothing)'], 'Shipping on taxable $25/$200 share: ' . number_format( $r['shipping'], 2 ) );

// RI $300 coat: taxable $50 at 7% = $3.50.
$ri_coat = wctc_mk( 'ri_coat', fn() => wctc_simple( 'RI test coat ($300 Clothing)', '300', $clothing, '3' ) );
$r = wctc_cart( $RI, array( $ri_coat => 1 ) );
tc( 'I4', 'I. Excess-only limits', 'RI $300 coat: tax only the $50 excess', 'Ship a $300 Clothing coat to Providence. Excess $250 → taxable $50 at RI 7% = $3.50.', 3.50, $r['lines']['RI test coat ($300 Clothing)'], 'Shipping tax: ' . number_format( $r['shipping'], 2 ) );

// Shipping follows the goods in RI: a $300 excess coat ($50 taxable, $250 exempt) with $10 shipping →
// shipping split: taxable share $50/$300 = $1.67 at 7% = $0.12 (rounded).
$r = wctc_cart( $RI, array( $ri_coat => 1 ) );
tc( 'I5', 'I. Excess-only limits', 'RI excess coat shipping follows the goods', 'Same cart, $10 flat shipping: shipping split by taxable value ($50 of $300).', 0.12, $r['shipping'], 'Dual-emit: the exempt $250 portion does not attract shipping tax. Note: ' . $r['note'] );

// Order round-trip: place an RI order and read back the recorded tax.
wctc_cart( $RI, array( $ri_coat => 1 ) );
$ri_order = wctc_order( $RI );
$ri_coat_line = null;
foreach ( $ri_order->get_items() as $it ) {
	if ( 'RI test coat ($300 Clothing)' === $it->get_name() ) {
		$ri_coat_line = $it;
	}
}
tc( 'I6', 'I. Excess-only limits', 'RI order: line tax saved as $3.50', 'Place the RI order from I4, read the saved line tax.', 3.50, $ri_coat_line ? (float) $ri_coat_line->get_total_tax() : -1, 'Rule note on the line: ' . ( $ri_coat_line ? $ri_coat_line->get_meta( '_wctc_tax_rule' ) : '' ) );

// Change the RI order to Arizona and recalculate: the excess rule no longer applies, so full price at AZ standard.
$ri_order->set_address( array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '85001', 'city' => 'Phoenix', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'shipping' );
$ri_order->set_address( array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '85001', 'city' => 'Phoenix', 'address_1' => '1 Test St', 'first_name' => 'Suite', 'last_name' => 'Buyer' ), 'billing' );
$ri_order->save();
$ri_order->calculate_totals( true );
foreach ( $ri_order->get_items() as $it ) {
	if ( 'RI test coat ($300 Clothing)' === $it->get_name() ) {
		$ri_coat_line = $it;
	}
}
tc( 'I7', 'I. Excess-only limits', 'Change to Arizona, recalc: AZ has no excess rule', 'Move the RI order to AZ, Recalculate.', 16.80, $ri_coat_line ? (float) $ri_coat_line->get_total_tax() : -1, '$300 × 5.6% at full Standard class.' );
$ri_order->delete( true );

// Validation: Excess mode without a limit is rejected.
wctc_errors();
wctc_post_rules( array( array( 'category' => 'clothing', 'country' => 'US', 'state' => 'VT', 'postcode' => '*', 'max_price' => '', 'tax_class' => '', 'limit_mode' => 'excess' ) ) );
$errs = wctc_errors();
tc( 'I8', 'I. Excess-only limits', 'Excess mode requires a limit', 'Save an excess rule with no price limit.', 'not saved; message shown', $errs && false !== strpos( $errs[0], 'Excess mode' ) ? 'not saved; message shown' : 'errors: ' . wp_json_encode( $errs ), $errs ? 'Message: ' . $errs[0] : '' );
\WCTC\Store::save_rules( $saved_rules );

// =====================================================================================================
// J. Tax-free sales report (Analytics gap filler)
// =====================================================================================================
// Build a tax-free NY order so the report has data in-range.
wctc_cart( $NY, array( $h => 1 ) );
$tf_order = wctc_order( $NY );
$tf_order->update_status( 'completed' );

$today  = gmdate( 'Y-m-d' );
$after  = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
$agg    = \WCTC\Admin\TaxFreeReport::aggregate( $after, $today );
tc( 'J1', 'J. Tax-free report', 'Zero-rated NY clothing shows as tax-free', 'Place a $45 hoodie order to NY, complete it, aggregate last 7 days.', true, (float) $agg['total_tax_free'] >= 45.0, 'Tax-free total: ' . number_format( $agg['total_tax_free'], 2 ) . ' across ' . (int) $agg['orders'] . ' order(s).' );
tc( 'J2', 'J. Tax-free report', 'Report buckets by tax category', 'Read the Clothing row from the aggregated report.', true, isset( $agg['by_category']['clothing'] ) && (float) $agg['by_category']['clothing']['total'] >= 45.0, 'By-category: ' . wp_json_encode( array_map( fn( $r ) => number_format( $r['total'], 2 ), $agg['by_category'] ) ) );

// Excess-only partial: build an MA order with the $200 excess coat; $175 should appear as tax-free.
wctc_cart( $MA, array( $ma_coat => 1 ) );
$tf_ma = wctc_order( $MA );
$tf_ma->update_status( 'completed' );
$agg_ma = \WCTC\Admin\TaxFreeReport::aggregate( $after, $today );
$ma_bucket = $agg_ma['by_category']['clothing']['total'] ?? 0;
tc( 'J3', 'J. Tax-free report', 'Excess rule: $175 per item counted as tax-free', 'MA $200 coat → $175 per unit exempt (reported) + $25 taxable (not in tax-free bucket).', true, $ma_bucket >= 45.0 + 175.0 - 0.01, 'Clothing bucket after MA order: ' . number_format( $ma_bucket, 2 ) . ' (expected ≥ 220.00: $45 hoodie + $175 exempt portion).' );

$tf_order->delete( true );
$tf_ma->delete( true );

// Restore state for the store.
\WCTC\Examples::load();
wctc_set( true, false );
WC()->cart->empty_cart();
WC()->cart->remove_coupons();
$current_section = '';

$passed = count( array_filter( $cases, fn( $c ) => $c['pass'] ) );
if ( 'json' === ( $_GET['format'] ?? '' ) ) { // phpcs:ignore
	header( 'Content-Type: application/json' );
	echo wp_json_encode( array( 'passed' => $passed, 'total' => count( $cases ), 'cases' => $cases, 'suite_order' => $suite_order_id, 'analytics_order' => $analytics_order_id ) );
	exit;
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Tax categories: validation suite</title>
<style>body{font:14px -apple-system,system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;width:100%}td,th{border:1px solid #dcdcde;padding:6px 10px;text-align:left;vertical-align:top}.p{color:#00a32a;font-weight:600}.f{color:#d63638;font-weight:600}h2{margin-top:28px}small{color:#50575e}</style></head><body>
<h1 id="summary"><?php echo esc_html( "$passed of " . count( $cases ) . ' test cases passed' ); ?></h1>
<?php $area = ''; foreach ( $cases as $c ) : ?>
<?php if ( $c['area'] !== $area ) : $area = $c['area']; ?>
<?php if ( '' !== $area ) : ?></table><?php endif; ?>
<h2><?php echo esc_html( $area ); ?></h2>
<table><tr><th>ID</th><th>Scenario</th><th>Steps</th><th>Expected</th><th>Actual</th><th></th><th>Notes</th></tr>
<?php endif; ?>
<tr><td><?php echo esc_html( $c['id'] ); ?></td><td><?php echo esc_html( $c['scenario'] ); ?></td><td><small><?php echo esc_html( $c['steps'] ); ?></small></td><td><?php echo esc_html( $c['expected'] ); ?></td><td><?php echo esc_html( $c['actual'] ); ?></td><td class="<?php echo $c['pass'] ? 'p' : 'f'; ?>"><?php echo $c['pass'] ? 'pass' : 'FAIL'; ?></td><td><small><?php echo esc_html( $c['note'] ); ?></small></td></tr>
<?php endforeach; ?>
</table>
<p>Suite order: <a href="<?php echo esc_url( wc_get_order( $suite_order_id )->get_edit_order_url() ); ?>">#<?php echo (int) $suite_order_id; ?></a> · Analytics order: <a href="<?php echo esc_url( wc_get_order( $analytics_order_id )->get_edit_order_url() ); ?>">#<?php echo (int) $analytics_order_id; ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-admin&path=/analytics/taxes&period=today' ) ); ?>">Analytics → Taxes</a></p>
</body></html>
