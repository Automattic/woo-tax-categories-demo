<?php
/**
 * Edge-case scenarios: where the prototype holds up and where it doesn't. Each row compares the plugin's
 * result with what the rules in "How Shipping Tax Works at Checkout" say should happen.
 * Open /wctc-stress.php in the Playground site (after /wctc-test.php has run once, or straight after boot).
 *
 * @package WCTC
 */

require_once __DIR__ . '/wp-load.php';
if ( ! current_user_can( 'manage_woocommerce' ) ) {
	wp_die( 'Log in as an admin.' );
}
wc_load_cart();
update_option( 'wctc_enabled', 'yes' );
update_option( 'wctc_shipping_split', 'no' );
\WCTC\Examples::load();

$ids  = get_option( 'wctc_playground_products' );
$rows = array();

/** Record a scenario. */
function wctc_row( $area, $scenario, $expected, $actual, $why ) {
	global $rows;
	$ok     = abs( (float) $expected - (float) $actual ) < 0.005;
	$rows[] = compact( 'area', 'scenario', 'expected', 'actual', 'why', 'ok' );
}

/** Find or create a product once. */
function wctc_make( $key, $callback ) {
	$made = get_option( 'wctc_stress_products', array() );
	if ( empty( $made[ $key ] ) || ! wc_get_product( $made[ $key ] ) ) {
		$made[ $key ] = $callback();
		update_option( 'wctc_stress_products', $made );
	}
	return $made[ $key ];
}
function wctc_term( $name ) {
	$t = get_term_by( 'name', $name, 'product_cat' );
	return $t ? (int) $t->term_id : 0;
}

// Extra products for edge cases.
$ebook = wctc_make(
	'ebook',
	function () {
		$p = new WC_Product_Simple();
		$p->set_name( 'E-book' );
		$p->set_regular_price( '15' );
		$p->set_virtual( true );
		$p->update_meta_data( '_wctc_tax_category', 'digital-books' );
		return $p->save();
	}
);
$both = wctc_make(
	'both',
	function () {
		$p = new WC_Product_Simple();
		$p->set_name( 'Book tote (Clothing + Books)' );
		$p->set_regular_price( '45' );
		$p->set_weight( '1' );
		$p->set_category_ids( array( wctc_term( 'Books' ), wctc_term( 'Clothing' ) ) );
		return $p->save();
	}
);
$formula = wctc_make(
	'formula',
	function () {
		$p = new WC_Product_Simple();
		$p->set_name( 'Baby formula (own class Zero rate)' );
		$p->set_regular_price( '30' );
		$p->set_weight( '1' );
		$p->set_tax_class( 'zero-rate' );
		return $p->save();
	}
);
$cookbook = wctc_make(
	'cookbook',
	function () {
		$attr = new WC_Product_Attribute();
		$attr->set_name( 'Format' );
		$attr->set_options( array( 'Paperback', 'eBook' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$p = new WC_Product_Variable();
		$p->set_name( 'Cookbook' );
		$p->set_category_ids( array( wctc_term( 'Books' ) ) );
		$p->set_attributes( array( $attr ) );
		$id = $p->save();
		foreach ( array( 'Paperback' => array( '25', false, '' ), 'eBook' => array( '12', true, 'digital-books' ) ) as $fmt => $cfg ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_attributes( array( 'format' => $fmt ) );
			$v->set_regular_price( $cfg[0] );
			$v->set_virtual( $cfg[1] );
			$v->set_weight( '1' );
			if ( $cfg[2] ) {
				$v->update_meta_data( '_wctc_tax_category', $cfg[2] );
			}
			$v->save();
		}
		return $id;
	}
);
$cook_ebook = 0;
foreach ( wc_get_product( $cookbook )->get_children() as $child ) {
	$v = wc_get_product( $child );
	if ( 'eBook' === $v->get_attribute( 'format' ) ) {
		$cook_ebook = $child;
	}
}

// Texas rates (no shipping rule for TX on purpose) and a Reduced rate class with no rows anywhere.
global $wpdb;
if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = 'WCTC example TX State'" ) ) { // phpcs:ignore
	WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'US', 'tax_rate_state' => 'TX', 'tax_rate' => '6.2500', 'tax_rate_name' => 'WCTC example TX State', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '' ) );
}

// Shipping zone helpers.
$zone_id = 0;
foreach ( WC_Shipping_Zones::get_zones() as $z ) {
	if ( 'US and UK' === $z['zone_name'] ) {
		$zone_id = $z['id'];
	}
}
$zone      = new WC_Shipping_Zone( $zone_id );
$flat_id   = 0;
$pickup_id = 0;
foreach ( $zone->get_shipping_methods() as $m ) {
	if ( 'flat_rate' === $m->id ) {
		$flat_id = $m->get_instance_id();
	}
	if ( 'local_pickup' === $m->id ) {
		$pickup_id = $m->get_instance_id();
	}
}
function wctc_flat_cost( $cost ) {
	global $flat_id;
	$opt         = get_option( 'woocommerce_flat_rate_' . $flat_id . '_settings' );
	$opt['cost'] = $cost;
	update_option( 'woocommerce_flat_rate_' . $flat_id . '_settings', $opt );
	\WCTC\Store::bump();
}

/**
 * Build a cart. $items = [ product_id => qty ]. Returns line taxes, shipping tax, total tax.
 */
function wctc_run( $ship, $items, $opts = array() ) {
	WC()->cart->empty_cart();
	WC()->cart->remove_coupons();
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
	WC()->session->set( 'chosen_shipping_methods', $opts['method'] ?? null );
	WC()->cart->calculate_totals();
	if ( ! empty( $opts['method'] ) ) {
		WC()->session->set( 'chosen_shipping_methods', $opts['method'] );
		WC()->cart->calculate_totals();
	}
	$lines = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$lines[ $item['data']->get_name() ] = array_sum( $item['line_tax_data']['total'] ?? array() );
	}
	$c->set_is_vat_exempt( false );
	return array(
		'lines'    => $lines,
		'shipping' => (float) WC()->cart->get_shipping_tax(),
		'total'    => (float) WC()->cart->get_total_tax(),
	);
}

$ny = array( 'US', 'NY', '10001', 'New York' );
$h  = $ids['hoodie'];
$b  = $ids['book'];
$m  = $ids['mug'];

// Coupons.
foreach ( array( 'book20' => array( 'fixed_product', 20, array( $b ) ), 'all100' => array( 'fixed_cart', 65, array() ) ) as $code => $cfg ) {
	if ( ! wc_get_coupon_id_by_code( $code ) ) {
		$cp = new WC_Coupon();
		$cp->set_code( $code );
		$cp->set_discount_type( $cfg[0] );
		$cp->set_amount( $cfg[1] );
		$cp->set_product_ids( $cfg[2] );
		$cp->save();
	}
}

// ---- Discounts --------------------------------------------------------------------------------
$r = wctc_run( $ny, array( $h => 1, $b => 1 ), array( 'coupons' => array( 'book20' ) ) );
wctc_row( 'Discounts', 'NYC hoodie + book, $20 coupon on the book only (book now $0)', 0.00, $r['shipping'], 'Only the hoodie (exempt) has value left, so shipping should follow it and be exempt.' );

// ---- Per-item shipping ------------------------------------------------------------------------
wctc_flat_cost( '8 * [qty]' );
$r = wctc_run( $ny, array( $h => 1, $b => 1 ) );
wctc_row( 'Per-item shipping', 'NYC hoodie + book, flat rate $8 per item ($16)', 0.71, $r['shipping'], 'Explainer: per-item charges take their item\'s treatment. The book\'s $8 is taxed (8 × 8.875% = $0.71); the hoodie\'s $8 is exempt.' );
wctc_flat_cost( '10' );

// ---- Quantities and price limits --------------------------------------------------------------
$r = wctc_run( $ny, array( $h => 3 ) );
wctc_row( 'Price limit', 'NYC 3 hoodies at $45 ($135 line)', 0.00, $r['total'], 'NY\'s $110 limit is per item, not per line; each hoodie is under $110.' );

// ---- Virtual items ---------------------------------------------------------------------------
$r = wctc_run( array( 'GB', '', 'SW1A 1AA', 'London' ), array( $ebook => 1, $m => 1 ) );
wctc_row( 'Virtual items', 'UK e-book (virtual) + mug, £10 shipping', 2.00, $r['shipping'], 'The e-book isn\'t in the box, so all shipping follows the mug (20%).' );

// ---- Variations ------------------------------------------------------------------------------
$r = wctc_run( array( 'GB', '', 'SW1A 1AA', 'London' ), array( $cook_ebook => 1 ) );
wctc_row( 'Variations', 'UK Cookbook eBook variation (overrides parent Books → Digital books)', 0.00, $r['total'], 'Variation override picks Digital books; the GB rule zero-rates it.' );

// ---- Product in two categories ----------------------------------------------------------------
$r = wctc_run( $ny, array( $both => 1 ) );
wctc_row( 'Multiple categories', 'NYC $45 product in both Books and Clothing (no choice made)', 4.88, $r['total'], 'Categories disagree, so it keeps its own class (Standard: $3.99 + $0.89 shipping) and is flagged as a conflict in the products list, instead of depending on term order.' );
$p = wc_get_product( $both );
$p->update_meta_data( '_wctc_tax_category', 'clothing' );
$p->save();
$r = wctc_run( $ny, array( $both => 1 ) );
wctc_row( 'Multiple categories', 'Same product after the merchant sets Clothing on the product', 0.00, $r['total'], 'The product\'s own choice settles it: Clothing in NY under $110 → Zero rate, and shipping follows.' );
$p->update_meta_data( '_wctc_tax_category', '' );
$p->save();

// ---- VAT-exempt customer ----------------------------------------------------------------------
$r = wctc_run( array( 'US', 'HI', '96813', 'Honolulu' ), array( $m => 1 ), array( 'vat_exempt' => true ) );
wctc_row( 'Tax-exempt customer', 'Hawaii order for a tax-exempt customer (shipping rule: always taxed)', 0.00, $r['total'], 'An exempt customer pays no tax on items or shipping.' );

// ---- Places without a shipping rule -----------------------------------------------------------
$r = wctc_run( array( 'US', 'TX', '73301', 'Austin' ), array( $formula => 1, $m => 1 ) );
wctc_row( 'Place without a rule', 'Texas: zero-rated formula $30 + mug $10, no TX shipping rule, split setting off', 0.16, $r['shipping'], 'Texas taxes shipping like the goods; the mug is 25% → $2.50 × 6.25% = $0.16. With tax categories on, shipping follows the goods by default, no rule needed.' );

update_option( 'wctc_shipping_split', 'yes' );
$r = wctc_run( array( 'US', 'TX', '73301', 'Austin' ), array( $formula => 1, $m => 1 ) );
wctc_row( 'Place without a rule', 'Same Texas order with the split setting on', 0.16, $r['shipping'], 'The store-wide split covers places without a rule.' );
update_option( 'wctc_shipping_split', 'no' );

// ---- Tax based on billing address -------------------------------------------------------------
update_option( 'woocommerce_tax_based_on', 'billing' );
$r = wctc_run( array( 'US', 'AZ', '85001', 'Phoenix' ), array( $h => 1, $b => 1 ), array( 'billing' => $ny ) );
wctc_row( 'Tax based on billing', 'Store taxes by billing address: billed to NYC, shipped to Arizona', 0.27, $r['shipping'], 'Items are taxed for NY (billing), so shipping should use NY\'s rule and rates too: $0.27.' );
update_option( 'woocommerce_tax_based_on', 'shipping' );

// ---- Local pickup -----------------------------------------------------------------------------
if ( ! $pickup_id ) {
	$pickup_id = $zone->add_shipping_method( 'local_pickup' );
	update_option( 'woocommerce_local_pickup_' . $pickup_id . '_settings', array( 'title' => 'Local pickup', 'tax_status' => 'taxable', 'cost' => '5' ) );
	\WCTC\Store::bump();
}
$r = wctc_run( array( 'US', 'AZ', '85001', 'Phoenix' ), array( $h => 1, $b => 1 ), array( 'method' => array( 'local_pickup:' . $pickup_id ) ) );
wctc_row( 'Local pickup', 'Arizona customer picks up at the NY store, $5 pickup fee', 0.14, $r['shipping'], 'Pickup is taxed at the store (NY): the fee follows the goods. Book share 30.8% of $5 = $1.54 × 8.875% = $0.14.' );

// ---- Misconfigured rule -----------------------------------------------------------------------
$saved = \WCTC\Store::rules();
\WCTC\Store::save_rules( array_merge( $saved, array( array( 'category' => 'clothing', 'country' => 'US', 'state' => 'TX', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'reduced-rate', 'start' => '', 'end' => '' ) ) ) );
$r = wctc_run( array( 'US', 'TX', '73301', 'Austin' ), array( $h => 1 ) );
wctc_row( 'Setup mistakes', 'Rule sends Clothing in TX to Reduced rate, but Reduced rate has no TX rows', 2.81, $r['lines']['Hoodie'] ?? 0, 'The class has no TX rates, so the rule is skipped (and flagged in settings) instead of quietly charging 0%: the hoodie keeps Standard, $45 × 6.25% = $2.81.' );
\WCTC\Store::save_rules( $saved );

// ---- Admin-created order -----------------------------------------------------------------------
WC()->customer->set_billing_location( 'US', 'CA', '94105', 'San Francisco' );
WC()->customer->set_shipping_location( 'US', 'CA', '94105', 'San Francisco' );
$order = wc_create_order();
$order->set_address( array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York' ), 'billing' );
$order->set_address( array( 'country' => 'US', 'state' => 'NY', 'postcode' => '10001', 'city' => 'New York' ), 'shipping' );
$order->add_product( wc_get_product( $h ), 1 );
$order->calculate_totals( true );
wctc_row( 'Admin-created orders', 'Admin adds a hoodie to a manual order shipping to NYC (admin\'s own session is in CA)', 0.00, $order->get_total_tax(), 'The rule should use the order\'s address (NY, exempt), not whoever is logged in.' );
$order->delete( true );

// ---- Free shipping ----------------------------------------------------------------------------
wctc_flat_cost( '0' );
$r = wctc_run( $ny, array( $h => 1, $b => 1 ) );
wctc_row( 'Free shipping', 'NYC hoodie + book with $0 shipping', 0.00, $r['shipping'], 'Nothing to split.' );
wctc_flat_cost( '10' );

// ---- 100% discount ----------------------------------------------------------------------------
$r = wctc_run( $ny, array( $h => 1, $b => 1 ), array( 'coupons' => array( 'all100' ) ) );
wctc_row( 'Discounts', 'NYC hoodie + book, coupon makes both items $0, shipping still $10', 0.27, $r['shipping'], 'With no value left, a value split has nothing to go on; falling back to list prices keeps the NYC answer ($0.27).' );

WC()->cart->empty_cart();
WC()->cart->remove_coupons();
$fails = count( array_filter( $rows, fn( $r ) => ! $r['ok'] ) );
?>
<!doctype html><html><head><meta charset="utf-8"><title>Edge cases</title>
<style>body{font:14px -apple-system,system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse}td,th{border:1px solid #dcdcde;padding:6px 10px;text-align:left;vertical-align:top}.p{color:#00a32a;font-weight:600}.f{color:#d63638;font-weight:600}</style></head><body>
<h1 id="summary"><?php echo esc_html( ( count( $rows ) - $fails ) . ' of ' . count( $rows ) . ' edge cases handled correctly' ); ?></h1>
<table><tr><th>Area</th><th>Scenario</th><th>Should be</th><th>Plugin gives</th><th></th><th>Why</th></tr>
<?php foreach ( $rows as $r ) : ?>
<tr><td><?php echo esc_html( $r['area'] ); ?></td><td><?php echo esc_html( $r['scenario'] ); ?></td><td><?php echo esc_html( wc_format_decimal( $r['expected'], 2 ) ); ?></td><td><?php echo esc_html( wc_format_decimal( $r['actual'], 2 ) ); ?></td><td class="<?php echo $r['ok'] ? 'p' : 'f'; ?>"><?php echo $r['ok'] ? 'ok' : 'WRONG'; ?></td><td><?php echo esc_html( $r['why'] ); ?></td></tr>
<?php endforeach; ?>
</table></body></html>
