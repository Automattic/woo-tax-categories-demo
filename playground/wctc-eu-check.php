<?php
/**
 * EU/UK/AU demo check: eight cases from the brief. Each builds a real cart, calculates totals, and
 * checks line taxes and shipping tax against the expected numbers. Mirrors wctc-test.php so the EU
 * store is verified the same way as the US one. Open /wctc-eu-check.php in the Playground site.
 *
 * @package WCTC
 */

require_once __DIR__ . '/wp-load.php';

if ( ! current_user_can( 'manage_woocommerce' ) ) {
	wp_die( 'Log in as an admin to run the checks.' );
}

wc_load_cart();
$ids     = get_option( 'wctc_playground_products' );
$results = array();
$money   = fn( $v ) => round( (float) $v, 2 );

function wctc_eu_check( $label, $expected, $actual ) {
	global $results;
	$pass      = is_numeric( $expected ) ? abs( (float) $expected - (float) $actual ) < 0.005 : $expected === $actual;
	$results[] = array( $label, $expected, $actual, $pass );
}

function wctc_eu_settings( $enabled, $split = false ) {
	update_option( 'wctc_enabled', $enabled ? 'yes' : 'no' );
	update_option( 'wctc_shipping_split', $split ? 'yes' : 'no' );
	\WCTC\Store::bump();
	wp_cache_flush();
}

/**
 * Build and total a cart.
 *
 * @param array $dest  country, state, postcode, city.
 * @param array $items product key => quantity.
 * @return array
 */
function wctc_eu_cart( $dest, $items ) {
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

$berlin    = array( 'DE', '', '10115', 'Berlin' );
$dublin    = array( 'IE', '', 'D01 F5P2', 'Dublin' );
$paris     = array( 'FR', '', '75001', 'Paris' );
$brussels  = array( 'BE', '', '1000', 'Brussels' );
$london    = array( 'GB', '', 'SW1A 1AA', 'London' );
$amsterdam = array( 'NL', '', '1012', 'Amsterdam' );
$sydney    = array( 'AU', 'NSW', '2000', 'Sydney' );
$scenarios = array();

// Default: plugin on so the carts exercise the EU rules.
wctc_eu_settings( true );

// 1. Berlin: paperback (Books → DE Reduced 7%) + mug (own, DE Std 19%). Shipping follows the goods,
//    2/3 of €4.90 at 7% + 1/3 at 19% = €0.54. Stock WC would tax the whole €4.90 at 19% = €0.93.
$r = wctc_eu_cart( $berlin, array( 'paperback' => 1, 'mug' => 1 ) );
$scenarios[] = array( '1. Berlin: paperback + mug', $r );
wctc_eu_check( 'Berlin: paperback €1.40 (Books → Reduced 7%)', 1.40, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( 'Berlin: mug €1.90 (Standard 19%)', 1.90, $money( $r['lines']['Mug'] ) );
wctc_eu_check( 'Berlin: shipping VAT €0.54 (⅔ at 7% + ⅓ at 19%)', 0.54, $money( $r['shipping'] ) );

// 2. Dublin: jumper (Kids → Children's clothing → IE Zero) + paperback (Books → IE Zero) + mug (own, IE Std 23%).
//    Shipping follows the goods; only the mug's sixth is taxed: €4.90/6 × 23% = €0.19.
$r = wctc_eu_cart( $dublin, array( 'jumper' => 1, 'paperback' => 1, 'mug' => 1 ) );
$scenarios[] = array( '2. Dublin: jumper + paperback + mug', $r );
wctc_eu_check( "Dublin: jumper €0 (Children's clothing in IE → Zero)", 0, $money( $r['lines']["Children's jumper"] ) );
wctc_eu_check( 'Dublin: paperback €0 (Books in IE → Zero)', 0, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( 'Dublin: mug €2.30 (Standard 23%)', 2.30, $money( $r['lines']['Mug'] ) );
wctc_eu_check( "Dublin: shipping VAT €0.19 (mug's sixth)", 0.19, $money( $r['shipping'] ) );

// 3. Paris: paperback (Books → FR Reduced 5.5%) + jumper (Kids → Children's clothing has no FR rule
//    → own class Std 20%). Shipping €0.70 (paperback share at 5.5% + jumper share at 20%).
$r = wctc_eu_cart( $paris, array( 'paperback' => 1, 'jumper' => 1 ) );
$scenarios[] = array( '3. Paris: paperback + jumper', $r );
wctc_eu_check( 'Paris: paperback €1.10 (Books → Reduced 5.5%)', 1.10, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( "Paris: jumper €6.00 (no FR Children's clothing rule → Standard 20%)", 6.00, $money( $r['lines']["Children's jumper"] ) );
wctc_eu_check( 'Paris: shipping VAT €0.70 (0.11 + 0.59)', 0.70, $money( $r['shipping'] ) );

// 4. Brussels: paperback (Books → BE Reduced 6%) + mug (own, BE Std 21%). BE rule says "lowest rate
//    in the box": whole €4.90 at 6% = €0.29. A plain value split would give €0.54.
$r = wctc_eu_cart( $brussels, array( 'paperback' => 1, 'mug' => 1 ) );
$scenarios[] = array( '4. Brussels: paperback + mug (lowest rate)', $r );
wctc_eu_check( 'Brussels: paperback €1.20 (Books → Reduced 6%)', 1.20, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( 'Brussels: mug €2.10 (Standard 21%)', 2.10, $money( $r['lines']['Mug'] ) );
wctc_eu_check( 'Brussels: shipping VAT €0.29 (whole charge at 6%, the BE shortcut)', 0.29, $money( $r['shipping'] ) );

// 5. London: paperback (Books → GB Zero) + mug (own, GB Std 20%). Only the mug's third is taxed.
$r = wctc_eu_cart( $london, array( 'paperback' => 1, 'mug' => 1 ) );
$scenarios[] = array( '5. London: paperback + mug', $r );
wctc_eu_check( 'London: paperback £0 (Books in GB → Zero)', 0, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( 'London: mug £2.00 (Standard 20%)', 2.00, $money( $r['lines']['Mug'] ) );
wctc_eu_check( "London: shipping VAT £0.33 (mug's third)", 0.33, $money( $r['shipping'] ) );

// 6. Amsterdam: e-book (virtual, Digital books → NL Reduced 9%) + mug (own, NL Std 21%). Virtuals do
//    not participate in the shipping value split, so the mug carries all €4.90 at 21% = €1.03.
//    The compelling demo point here is the product-level override, not shipping behaviour.
$r = wctc_eu_cart( $amsterdam, array( 'ebook' => 1, 'mug' => 1 ) );
$scenarios[] = array( '6. Amsterdam: e-book + mug', $r );
wctc_eu_check( 'Amsterdam: e-book €0.90 (Digital books → NL Reduced 9%)', 0.90, $money( $r['lines']['E-book: The Tin Drum'] ) );
wctc_eu_check( 'Amsterdam: mug €2.10 (Standard 21%)', 2.10, $money( $r['lines']['Mug'] ) );
wctc_eu_check( 'Amsterdam: shipping VAT €1.03 (virtual item is not shipped → mug carries all of it)', 1.03, $money( $r['shipping'] ) );

// 7. Sydney: coffee (Food → AU Zero) + mug (own, AU GST 10%). Mug's share is 10/22 of €4.90 = €2.227
//    at 10% = €0.22. "Today" (one class) would tax the whole €4.90 at 10% = €0.49.
$r = wctc_eu_cart( $sydney, array( 'coffee' => 1, 'mug' => 1 ) );
$scenarios[] = array( '7. Sydney: coffee + mug', $r );
wctc_eu_check( 'Sydney: coffee $0 (Food in AU → Zero / GST-free)', 0, $money( $r['lines']['Coffee beans 1 kg'] ) );
wctc_eu_check( 'Sydney: mug $1.00 (GST 10%)', 1.00, $money( $r['lines']['Mug'] ) );
wctc_eu_check( "Sydney: shipping GST $0.22 (only the mug's 10/22 share)", 0.22, $money( $r['shipping'] ) );

// 8. Opt-out parity: with both plugin settings off, cart 1 should match stock WC exactly (all €4.90
//    of shipping at DE Std 19% = €0.93, paperback and mug both at Standard 19%).
wctc_eu_settings( false, false );
$r = wctc_eu_cart( $berlin, array( 'paperback' => 1, 'mug' => 1 ) );
$scenarios[] = array( '8. Berlin, feature off: parity with stock WC', $r );
wctc_eu_check( 'Opt-out: paperback €3.80 (Standard 19%, no reduced rate applied)', 3.80, $money( $r['lines']['Paperback: The Tin Drum'] ) );
wctc_eu_check( 'Opt-out: mug €1.90 (Standard 19%)', 1.90, $money( $r['lines']['Mug'] ) );
wctc_eu_check( 'Opt-out: shipping VAT €0.93 (whole €4.90 at 19%)', 0.93, $money( $r['shipping'] ) );

// Restore plugin on for the store.
wctc_eu_settings( true );
WC()->cart->empty_cart();

$passed = count( array_filter( $results, fn( $r ) => $r[3] ) );
$total  = count( $results );
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>EU demo check: <?php echo esc_html( "$passed of $total" ); ?></title>
<style>body{font:14px -apple-system,system-ui,sans-serif;margin:24px;color:#1d2327}table{border-collapse:collapse;width:100%;margin-bottom:16px}td,th{border:1px solid #dcdcde;padding:6px 10px;text-align:left}.p{color:#00a32a;font-weight:600}.f{color:#d63638;font-weight:600}h2{margin-top:28px;font-size:16px}small{color:#50575e}</style>
</head>
<body>
<h1><?php echo esc_html( "$passed of $total checks passed" ); ?></h1>
<p>EU/UK/AU demo store — eight carts from <code>docs/eu-demo-store-brief.md</code>. One €4.90 flat-rate for the whole zone. All prices entered ex-VAT.</p>

<h2>Checks</h2>
<table>
<tr><th>Check</th><th>Expected</th><th>Actual</th><th></th></tr>
<?php foreach ( $results as $row ) : list( $label, $exp, $act, $pass ) = $row; ?>
<tr>
	<td><?php echo esc_html( $label ); ?></td>
	<td><?php echo esc_html( is_numeric( $exp ) ? number_format( (float) $exp, 2 ) : (string) $exp ); ?></td>
	<td><?php echo esc_html( is_numeric( $act ) ? number_format( (float) $act, 2 ) : (string) $act ); ?></td>
	<td class="<?php echo $pass ? 'p' : 'f'; ?>"><?php echo $pass ? 'pass' : 'FAIL'; ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>Carts rendered</h2>
<?php foreach ( $scenarios as $sc ) : list( $title, $r ) = $sc; ?>
<p><strong><?php echo esc_html( $title ); ?></strong><br>
<small>Line taxes: <?php echo esc_html( implode( ', ', array_map( fn( $k, $v ) => $k . ' ' . number_format( (float) $v, 2 ), array_keys( $r['lines'] ), $r['lines'] ) ) ); ?><br>
Shipping tax: <?php echo esc_html( number_format( $r['shipping'], 2 ) ); ?> · Total tax: <?php echo esc_html( number_format( $r['total'], 2 ) ); ?><?php if ( $r['note'] ) : ?><br>Shipping note: <?php echo esc_html( $r['note'] ); endif; ?></small></p>
<?php endforeach; ?>
</body>
</html>
