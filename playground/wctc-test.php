<?php
/**
 * Playground test runner: builds real carts and an order with WooCommerce, and checks the totals
 * against the requirements doc's acceptance examples. Open /wctc-test.php in the Playground site.
 *
 * @package WCTC
 */

require_once __DIR__ . '/wp-load.php';

if ( ! current_user_can( 'manage_woocommerce' ) ) {
	wp_die( 'Log in as an admin to run the tests.' );
}

wc_load_cart();
$ids     = get_option( 'wctc_playground_products' );
$results = array();
$money   = fn( $v ) => round( (float) $v, 2 );

/** Record a check. */
function wctc_check( $label, $expected, $actual ) {
	global $results;
	$pass      = is_numeric( $expected ) ? abs( (float) $expected - (float) $actual ) < 0.005 : $expected === $actual;
	$results[] = array( $label, $expected, $actual, $pass );
}

/** Set options for a scenario. */
function wctc_settings( $enabled, $split ) {
	update_option( 'wctc_enabled', $enabled ? 'yes' : 'no' );
	update_option( 'wctc_shipping_split', $split ? 'yes' : 'no' );
	wp_cache_flush();
}

/**
 * Build a cart and calculate it.
 *
 * @param array $dest  country, state, postcode, city.
 * @param array $items product key => quantity.
 * @return array
 */
function wctc_cart( $dest, $items ) {
	global $ids;
	WC()->cart->empty_cart();
	WC()->session->set( 'shipping_for_package_0', null );
	$c = WC()->customer;
	$c->set_shipping_location( $dest[0], $dest[1], $dest[2], $dest[3] );
	$c->set_billing_location( $dest[0], $dest[1], $dest[2], $dest[3] );
	$c->set_calculated_shipping( true );
	foreach ( $items as $key => $qty ) {
		WC()->cart->add_to_cart( $ids[ $key ], $qty );
	}
	WC()->cart->calculate_totals();
	$lines = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		$lines[ $item['data']->get_name() ] = array_sum( $item['line_tax_data']['total'] ?? array() );
	}
	$note     = '';
	$packages = WC()->shipping()->get_packages();
	if ( ! empty( $packages[0]['rates'] ) ) {
		$rate = reset( $packages[0]['rates'] );
		$meta = $rate->get_meta_data();
		$note = $meta['_wctc_shipping_tax'] ?? '';
	}
	return array(
		'lines'    => $lines,
		'shipping' => (float) WC()->cart->get_shipping_tax(),
		'total'    => (float) WC()->cart->get_total_tax(),
		'note'     => $note,
	);
}

$ny = array( 'US', 'NY', '10001', 'New York' );
$scenarios = array();

// 1. Feature off: identical to stock WooCommerce (one class for shipping, Standard wins).
wctc_settings( false, false );
$r = wctc_cart( $ny, array( 'hoodie' => 1, 'book' => 1 ) );
$scenarios[] = array( 'Off: NYC hoodie + book', $r );
wctc_check( 'Off: hoodie taxed as Standard ($3.99)', 3.99, $money( $r['lines']['Hoodie'] ) );
wctc_check( 'Off: shipping taxed in full ($0.89)', 0.89, $money( $r['shipping'] ) );
wctc_check( 'Off: no shipping split note', '', $r['note'] );

// 2. On: NYC acceptance example.
wctc_settings( true, false );
$r = wctc_cart( $ny, array( 'hoodie' => 1, 'book' => 1 ) );
$scenarios[] = array( 'On: NYC hoodie + book', $r );
wctc_check( 'NYC: hoodie exempt (Clothing in NY under $110)', 0, $money( $r['lines']['Hoodie'] ) );
wctc_check( 'NYC: book $1.78', 1.78, $money( $r['lines']['The Penderwicks at Last'] ) );
wctc_check( 'NYC: shipping tax $0.27', 0.27, $money( $r['shipping'] ) );
wctc_check( 'NYC: total tax $2.05', 2.05, $money( $r['total'] ) );

// 3. Price limit: a $150 coat isn't under $110, so it keeps Standard and shipping is all Standard.
$r = wctc_cart( $ny, array( 'coat' => 1, 'book' => 1 ) );
$scenarios[] = array( 'On: NYC coat + book', $r );
wctc_check( 'NYC: $150 coat taxed ($13.31)', 13.31, $money( $r['lines']['Winter coat'] ) );
wctc_check( 'NYC: shipping fully taxed ($0.89)', 0.89, $money( $r['shipping'] ) );

// 4. UK: book zero-rated via Books, mug standard; shipping follows the goods by value (mug is 1/3).
$r = wctc_cart( array( 'GB', '', 'SW1A 1AA', 'London' ), array( 'book' => 1, 'mug' => 1 ) );
$scenarios[] = array( 'On: UK book + mug', $r );
wctc_check( 'UK: book zero-rated', 0, $money( $r['lines']['The Penderwicks at Last'] ) );
wctc_check( 'UK: mug VAT $2.00', 2.00, $money( $r['lines']['Mug'] ) );
wctc_check( 'UK: shipping VAT on the mug\'s third ($0.67)', 0.67, $money( $r['shipping'] ) );

// 5. Arizona: shipping on its own line is exempt.
$r = wctc_cart( array( 'US', 'AZ', '85001', 'Phoenix' ), array( 'blender' => 1 ) );
$scenarios[] = array( 'On: Arizona blender', $r );
wctc_check( 'AZ: blender taxed ($2.24)', 2.24, $money( $r['lines']['Blender'] ) );
wctc_check( 'AZ: shipping exempt', 0, $money( $r['shipping'] ) );

// 6. Hawaii: shipping always taxed in full at Standard.
$r = wctc_cart( array( 'US', 'HI', '96813', 'Honolulu' ), array( 'hoodie' => 1 ) );
$scenarios[] = array( 'On: Hawaii hoodie', $r );
wctc_check( 'HI: shipping taxed in full ($0.40)', 0.40, $money( $r['shipping'] ) );

// 7. California: exempt when the store meets the conditions; follows the goods when it doesn't.
$r = wctc_cart( array( 'US', 'CA', '94105', 'San Francisco' ), array( 'mug' => 1 ) );
$scenarios[] = array( 'On: California mug, conditions met', $r );
wctc_check( 'CA (conditions met): shipping exempt', 0, $money( $r['shipping'] ) );
$rules = \WCTC\Store::shipping_rules();
foreach ( $rules as $i => $rule ) {
	if ( 'CA' === $rule['state'] ) {
		$rules[ $i ]['conditions_met'] = 0;
	}
}
\WCTC\Store::save_shipping_rules( $rules );
$r = wctc_cart( array( 'US', 'CA', '94105', 'San Francisco' ), array( 'mug' => 1 ) );
$scenarios[] = array( 'On: California mug, conditions not met', $r );
wctc_check( 'CA (conditions not met): shipping taxed like the mug ($0.73)', 0.73, $money( $r['shipping'] ) );
\WCTC\Examples::load(); // Restore the example rules.

// 8. Minnesota: clothing exempt, shipping split by weight (blender is 6 of 8 lb).
$r = wctc_cart( array( 'US', 'MN', '55401', 'Minneapolis' ), array( 'jacket' => 1, 'blender' => 1 ) );
$scenarios[] = array( 'On: Minnesota jacket + blender', $r );
wctc_check( 'MN: jacket exempt', 0, $money( $r['lines']['Jacket'] ) );
wctc_check( 'MN: shipping tax on $7.50 by weight ($0.52)', 0.52, $money( $r['shipping'] ) );

// 9. Order: place it through WooCommerce's own checkout code, check line meta, then Recalculate keeps the split.
$r       = wctc_cart( $ny, array( 'hoodie' => 1, 'book' => 1 ) );
$address = array( 'first_name' => 'Test', 'last_name' => 'Buyer', 'address_1' => '1 Test St', 'city' => 'New York', 'state' => 'NY', 'postcode' => '10001', 'country' => 'US' );
$posted  = array( 'payment_method' => 'cod', 'billing_email' => 'test@example.com', 'billing_phone' => '5555555555', 'ship_to_different_address' => 0 );
foreach ( $address as $k => $v ) {
	$posted[ 'billing_' . $k ]  = $v;
	$posted[ 'shipping_' . $k ] = $v;
}
$packages = WC()->shipping()->get_packages();
WC()->session->set( 'chosen_shipping_methods', array( array_key_first( $packages[0]['rates'] ) ) );
$order_id = WC()->checkout()->create_order( $posted );
if ( is_wp_error( $order_id ) ) {
	wp_die( esc_html( $order_id->get_error_message() ) );
}
$order = wc_get_order( $order_id );
$hoodie_item = null;
foreach ( $order->get_items() as $item ) {
	if ( 'Hoodie' === $item->get_name() ) {
		$hoodie_item = $item;
	}
}
wctc_check( 'Order: hoodie line records its tax category', 'Clothing (product category: Clothing)', $hoodie_item ? $hoodie_item->get_meta( '_wctc_tax_category' ) : '' );
wctc_check( 'Order: hoodie line records its rule', 'US NY, under 110 → Zero rate', $hoodie_item ? $hoodie_item->get_meta( '_wctc_tax_rule' ) : '' );
$shown = array();
foreach ( $order->get_items() as $item ) {
	foreach ( $item->get_formatted_meta_data() as $m ) {
		$shown[] = $m->display_key;
	}
}
wctc_check( 'Order: tax notes are hidden from customers', 'none', array_intersect( $shown, array( 'Tax category', 'Tax rule', 'Shipping tax' ) ) ? 'shown' : 'none' );
wctc_check( 'Order: shipping tax $0.27 before Recalculate', 0.27, $money( $order->get_shipping_tax() ) );
$order->calculate_totals( true ); // What the Recalculate button does.
$order->save();
$order = wc_get_order( $order_id );
wctc_check( 'Order: shipping tax still $0.27 after Recalculate', 0.27, $money( $order->get_shipping_tax() ) );
wctc_check( 'Order: total tax $2.05 after Recalculate', 2.05, $money( $order->get_total_tax() ) );

WC()->cart->empty_cart();
wctc_settings( true, false );

$passed = count( array_filter( $results, fn( $r ) => $r[3] ) );
header( 'Content-Type: text/html; charset=utf-8' );
?>
<!doctype html><html><head><meta charset="utf-8"><title>Tax categories prototype tests</title>
<style>body{font:14px -apple-system,system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;margin:12px 0}td,th{border:1px solid #dcdcde;padding:6px 10px;text-align:left}.p{color:#00a32a;font-weight:600}.f{color:#d63638;font-weight:600}code{font-size:12px}</style></head><body>
<h1 id="summary"><?php echo esc_html( "$passed of " . count( $results ) . ' checks passed' ); ?></h1>
<table><tr><th>Check</th><th>Expected</th><th>Actual</th><th></th></tr>
<?php foreach ( $results as $row ) : ?>
<tr><td><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( var_export( $row[1], true ) ); ?></td><td><?php echo esc_html( var_export( $row[2], true ) ); ?></td><td class="<?php echo $row[3] ? 'p' : 'f'; ?>"><?php echo $row[3] ? 'pass' : 'FAIL'; ?></td></tr>
<?php endforeach; ?>
</table>
<h2>Carts</h2>
<table><tr><th>Scenario</th><th>Line tax</th><th>Shipping tax</th><th>Total tax</th><th>Shipping tax note</th></tr>
<?php foreach ( $scenarios as $s ) : ?>
<tr><td><?php echo esc_html( $s[0] ); ?></td><td><?php foreach ( $s[1]['lines'] as $n => $t ) { echo esc_html( "$n: " . wc_format_decimal( $t, 2 ) ) . '<br>'; } ?></td><td><?php echo esc_html( wc_format_decimal( $s[1]['shipping'], 2 ) ); ?></td><td><?php echo esc_html( wc_format_decimal( $s[1]['total'], 2 ) ); ?></td><td><code><?php echo esc_html( $s[1]['note'] ); ?></code></td></tr>
<?php endforeach; ?>
</table>
<p>Test order: <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo (int) $order_id; ?></a> (also under WooCommerce → Orders).</p>
</body></html>
