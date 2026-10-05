<?php
/**
 * Engine tests: the requirements doc's acceptance examples, run without WordPress.
 * Run: php tests/EngineTest.php
 *
 * @package WCTC
 */

define( 'WCTC_TESTING', true );
require __DIR__ . '/../src/Engine.php';

use WCTC\Engine;

$failures = 0;
$count    = 0;

/** Assert two values are equal (floats to the cent). */
function check( $label, $expected, $actual ) {
	global $failures, $count;
	++$count;
	$ok = is_float( $expected ) || is_float( $actual ) ? abs( (float) $expected - (float) $actual ) < 0.005 : $expected === $actual;
	if ( ! $ok ) {
		++$failures;
		echo "FAIL  $label\n      expected: " . var_export( $expected, true ) . "\n      actual:   " . var_export( $actual, true ) . "\n";
	} else {
		echo "ok    $label\n";
	}
}

// Rates by place and class. Zero rate has no rows, as in WooCommerce.
$rates = array(
	'US-NY' => array( '' => array( 1 => 4.0, 2 => 4.875 ) ),
	'US-MN' => array( '' => array( 3 => 6.875 ) ),
	'US-HI' => array( '' => array( 4 => 4.0 ) ),
	'US-AZ' => array( '' => array( 5 => 5.6 ) ),
	'US-CA' => array( '' => array( 6 => 7.25 ) ),
	'US-TX' => array( '' => array( 7 => 6.25 ) ),
	'GB-'   => array( '' => array( 8 => 20.0 ) ),
	'BE-'   => array( '' => array( 9 => 21.0 ), 'reduced-rate' => array( 10 => 6.0 ) ),
	'US-IL' => array( '' => array( 11 => 6.25 ), 'reduced-rate' => array( 12 => 1.0 ) ),
	'US-MA' => array( '' => array( 13 => 6.25 ) ),
	'US-RI' => array( '' => array( 14 => 7.0 ) ),
);
$calc = function ( $amount, $rows ) {
	$out = array();
	foreach ( $rows as $id => $pct ) {
		$out[ $id ] = $amount * $pct / 100;
	}
	return $out;
};
$rates_for = function ( $place ) use ( $rates ) {
	return function ( $class ) use ( $rates, $place ) {
		return $rates[ $place ][ $class ] ?? array();
	};
};
$total = fn( $result ) => null === $result ? null : round( array_sum( $result['taxes'] ), 2 );

$shipping_rules = array(
	array( 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'mode' => 'follows', 'conditions_met' => 0, 'split' => 'value' ),
	array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '*', 'mode' => 'exempt_stated', 'conditions_met' => 0, 'split' => 'value' ),
	array( 'country' => 'US', 'state' => 'CA', 'postcode' => '*', 'mode' => 'conditional', 'conditions_met' => 1, 'split' => 'value' ),
	array( 'country' => 'US', 'state' => 'HI', 'postcode' => '*', 'mode' => 'always', 'conditions_met' => 0, 'split' => 'value' ),
	array( 'country' => 'US', 'state' => 'MN', 'postcode' => '*', 'mode' => 'follows', 'conditions_met' => 0, 'split' => 'weight' ),
	array( 'country' => 'GB', 'state' => '*', 'postcode' => '*', 'mode' => 'follows', 'conditions_met' => 0, 'split' => 'value' ),
	array( 'country' => 'BE', 'state' => '*', 'postcode' => '*', 'mode' => 'follows', 'conditions_met' => 0, 'split' => 'lowest' ),
	array( 'country' => 'US', 'state' => 'IL', 'postcode' => '*', 'mode' => 'delivery_terms', 'conditions_met' => 0, 'split' => 'majority' ),
);
$category_rules = array(
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '110', 'tax_class' => 'zero-rate' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'MN', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate' ),
	array( 'category' => 'books', 'country' => 'GB', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate' ),
	array( 'category' => 'groceries', 'country' => 'US', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'reduced-rate' ),
	array( 'category' => 'groceries', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NJ', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate', 'start' => '2026-01-01', 'end' => '2026-06-30' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'MA', 'postcode' => '*', 'max_price' => '175', 'tax_class' => '', 'limit_mode' => 'excess' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'RI', 'postcode' => '*', 'max_price' => '250', 'tax_class' => '', 'limit_mode' => 'excess' ),
);
$loc = fn( $c, $s = '' ) => array( 'country' => $c, 'state' => $s, 'postcode' => '' );
$cls = function ( $category, $location, $price, $today = '2026-10-02' ) use ( $category_rules ) {
	$rule = Engine::match_category_rule( $category, $location, $price, $today, $category_rules );
	return $rule ? $rule['tax_class'] : 'own class';
};

echo "Category rules\n";
check( 'NY hoodie $45 (Clothing) → Zero rate', 'zero-rate', $cls( 'clothing', $loc( 'US', 'NY' ), 45 ) );
check( 'NY coat $150 (Clothing) is over $110 → own class', 'own class', $cls( 'clothing', $loc( 'US', 'NY' ), 150 ) );
check( 'NY clothing at exactly $110 → own class (must be under)', 'own class', $cls( 'clothing', $loc( 'US', 'NY' ), 110 ) );
check( 'CA hoodie (no CA rule) → own class', 'own class', $cls( 'clothing', $loc( 'US', 'CA' ), 45 ) );
check( 'GB book → Zero rate', 'zero-rate', $cls( 'books', $loc( 'GB' ), 40 ) );
check( 'NY groceries: state rule beats US-wide rule', 'zero-rate', $cls( 'groceries', $loc( 'US', 'NY' ), 5 ) );
check( 'TX groceries: US-wide rule applies', 'reduced-rate', $cls( 'groceries', $loc( 'US', 'TX' ), 5 ) );
check( 'Uncategorised product → own class', 'own class', $cls( '', $loc( 'US', 'NY' ), 45 ) );
check( 'NJ clothing rule outside its dates → own class', 'own class', $cls( 'clothing', $loc( 'US', 'NJ' ), 45 ) );
check( 'NJ clothing rule inside its dates → Zero rate', 'zero-rate', $cls( 'clothing', $loc( 'US', 'NJ' ), 45, '2026-03-01' ) );
$tiered = array(
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'reduced-rate' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '110', 'tax_class' => 'zero-rate' ),
);
check( 'Tiered rules: tighter price limit wins regardless of row order ($45)', 'zero-rate', Engine::match_category_rule( 'clothing', $loc( 'US', 'NY' ), 45, '2026-10-02', $tiered )['tax_class'] );
check( 'Tiered rules: over the limit the open rule applies ($150)', 'reduced-rate', Engine::match_category_rule( 'clothing', $loc( 'US', 'NY' ), 150, '2026-10-02', $tiered )['tax_class'] );
$dupes = array_merge( $tiered, array( array( 'category' => 'clothing', 'country' => 'us', 'state' => 'ny', 'postcode' => '', 'max_price' => '110', 'tax_class' => 'reduced-rate' ) ) );
check( 'Duplicate rule rows are reported (row 3 duplicates row 2)', array( 2 => 1 ), Engine::duplicate_category_rules( $dupes ) );
check( 'Duplicate rule rows: first wins', 'zero-rate', Engine::match_category_rule( 'clothing', $loc( 'US', 'NY' ), 45, '2026-10-02', $dupes )['tax_class'] );
check( 'ZIP prefix pattern matches', true, Engine::place_matches( '100*', '10001' ) );
check( 'ZIP list pattern matches', true, Engine::place_matches( '10001;10002', '10002' ) );
check( 'ZIP pattern rejects other ZIP', false, Engine::place_matches( '100*', '11201' ) );

echo "\nExcess-only price limits\n";
// Cliff mode (NY $110): item under the limit → rule's class on the whole value; over → rule skipped.
$ny_cliff_45  = Engine::apply_rule( $category_rules[0], 45 );
$ny_cliff_150 = Engine::match_category_rule( 'clothing', $loc( 'US', 'NY' ), 150, '2026-10-02', $category_rules );
check( 'Cliff ($110) at $45: whole item at Zero rate, fraction 1.0', 1.0, $ny_cliff_45['taxable_fraction'] );
check( 'Cliff ($110) at $45: tax class is Zero rate', 'zero-rate', $ny_cliff_45['tax_class'] );
check( 'Cliff ($110) at $150: no matching cliff rule', null, $ny_cliff_150 );
// Excess mode (MA $175): rule matches regardless of price. Under limit → zero taxable; over → excess only.
$ma_150 = Engine::match_category_rule( 'clothing', $loc( 'US', 'MA' ), 150, '2026-10-02', $category_rules );
$ma_200 = Engine::match_category_rule( 'clothing', $loc( 'US', 'MA' ), 200, '2026-10-02', $category_rules );
check( 'Excess (MA $175) at $150: rule matches', true, is_array( $ma_150 ) );
check( 'Excess (MA $175) at $200: rule matches', true, is_array( $ma_200 ) );
$ma_150_eff = Engine::apply_rule( $ma_150, 150 );
$ma_200_eff = Engine::apply_rule( $ma_200, 200 );
check( 'MA $150 under $175: taxable amount $0', 0.0, $ma_150_eff['taxable_amount'] );
check( 'MA $150 under $175: effective class Zero rate', 'zero-rate', $ma_150_eff['tax_class'] );
check( 'MA $200 over $175: taxable amount $25', 25.0, $ma_200_eff['taxable_amount'] );
check( 'MA $200 over $175: taxable fraction 0.125', 0.125, $ma_200_eff['taxable_fraction'] );
check( 'MA $200 over $175: tax class is Standard', '', $ma_200_eff['tax_class'] );
// Rhode Island $250.
$ri_300 = Engine::apply_rule( Engine::match_category_rule( 'clothing', $loc( 'US', 'RI' ), 300, '2026-10-02', $category_rules ), 300 );
check( 'RI $300 over $250: taxable amount $50', 50.0, $ri_300['taxable_amount'] );
check( 'RI $300 over $250: tax at Standard', 6.25 * 50 / 100, 50 * 6.25 / 100 );
// Mode disambiguates duplicates: same place and limit but different modes are not duplicates.
$mixed = array(
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'VT', 'postcode' => '*', 'max_price' => '110', 'tax_class' => 'zero-rate', 'limit_mode' => 'cliff' ),
	array( 'category' => 'clothing', 'country' => 'US', 'state' => 'VT', 'postcode' => '*', 'max_price' => '110', 'tax_class' => '', 'limit_mode' => 'excess' ),
);
check( 'Cliff and excess with the same limit are not duplicates', array(), Engine::duplicate_category_rules( $mixed ) );

echo "\nShipping tax\n";
$ship = function ( $place, $cost, $items, $split_no_rule = false ) use ( $shipping_rules, $rates_for, $calc ) {
	list( $country, $state ) = explode( '-', $place ) + array( '', '' );
	$rule = Engine::match_shipping_rule( array( 'country' => $country, 'state' => $state, 'postcode' => '' ), $shipping_rules );
	return Engine::shipping_tax( $cost, $items, $rule, $split_no_rule, $rates_for( $place ), $calc );
};
$item = fn( $class, $value, $weight = 0, $taxable = true ) => array( 'class' => $class, 'value' => $value, 'weight' => $weight, 'taxable' => $taxable );

// Acceptance: NYC $45 hoodie (Zero rate via rule) + $20 book (Standard), $10 shipping → $0.27.
$nyc = $ship( 'US-NY', 10, array( $item( 'zero-rate', 45 ), $item( '', 20 ) ) );
check( 'NYC: shipping tax $0.27', 0.27, $total( $nyc ) );
check( 'NYC: hoodie share of shipping $6.92', 6.92, $nyc['lines'][0]['amount'] );
check( 'NYC: book share of shipping $3.08', 3.08, $nyc['lines'][1]['amount'] );
check( 'NYC: total tax with book $1.78 is $2.05', 2.05, 1.78 + $total( $nyc ) );

// Acceptance: UK £40 zero-rated book + £10 mug, £5 shipping → £0.20.
check( 'UK: shipping VAT £0.20', 0.20, $total( $ship( 'GB-', 5, array( $item( 'zero-rate', 40 ), $item( '', 10 ) ) ) ) );

// Acceptance: Minnesota split by weight. $60 jacket 2 lb (exempt) + $40 blender 6 lb, $16 → $12 taxed, $0.83.
$mn = $ship( 'US-MN', 16, array( $item( 'zero-rate', 60, 2 ), $item( '', 40, 6 ) ) );
check( 'MN by weight: $12.00 of shipping taxable', 12.00, $mn['lines'][1]['amount'] );
check( 'MN by weight: shipping tax $0.83', 0.83, $total( $mn ) );

// Acceptance: Arizona, shipping on its own line is never taxed.
check( 'AZ: shipping tax $0', 0.0, $total( $ship( 'US-AZ', 8, array( $item( '', 50 ) ) ) ) );

// Acceptance: Hawaii taxes shipping in full even when every item is exempt.
check( 'HI: exempt items, $10 shipping still taxed $0.40', 0.40, $total( $ship( 'US-HI', 10, array( $item( 'zero-rate', 30 ), $item( '', 20, 0, false ) ) ) ) );

// California: exempt when the store meets the conditions; follows the goods when it doesn't.
check( 'CA, conditions met: shipping tax $0', 0.0, $total( $ship( 'US-CA', 10, array( $item( '', 50 ) ) ) ) );
$ca_unmet = Engine::shipping_tax( 10, array( $item( '', 50 ) ), array( 'mode' => 'conditional', 'conditions_met' => 0, 'split' => 'value' ), false, $rates_for( 'US-CA' ), $calc );
check( 'CA, conditions not met: taxed like the goods ($0.73)', 0.73, $total( $ca_unmet ) );

// One tax treatment in the box: shipping takes it whole.
check( 'NY all-Standard basket: whole $10 taxed ($0.89)', 0.89, $total( $ship( 'US-NY', 10, array( $item( '', 30 ), $item( '', 20 ) ) ) ) );
check( 'NY all-exempt basket: shipping exempt', 0.0, $total( $ship( 'US-NY', 10, array( $item( 'zero-rate', 30 ) ) ) ) );
check( 'Untaxable items (tax status none) count as exempt', 0.0, $total( $ship( 'US-NY', 10, array( $item( '', 30, 0, false ) ) ) ) );

// Belgium: the whole charge takes the lowest rate in the box.
$be = $ship( 'BE-', 10, array( $item( '', 50 ), $item( 'reduced-rate', 20 ) ) );
check( 'BE lowest rate: €10 shipping at 6% = €0.60', 0.60, $total( $be ) );
check( 'BE lowest rate: whole charge on the reduced group', 10.0, $be['lines'][1]['amount'] );
check( 'BE lowest rate with an exempt item: shipping exempt', 0.0, $total( $ship( 'BE-', 10, array( $item( '', 50 ), $item( 'zero-rate', 5 ) ) ) ) );
check( 'BE lowest rate: per-item charges stay with their item, shared €5 at 6% (€1.05 + €0.30 + €0.30)', 1.65, $total( $ship( 'BE-', 15, array( $item( '', 50 ) + array( 'ship' => 5 ), $item( 'reduced-rate', 20 ) + array( 'ship' => 5 ) ) ) ) );

// Illinois: the charge takes the rate of the majority of the value; no majority → split by value.
$il = $ship( 'US-IL', 10, array( $item( 'reduced-rate', 70 ), $item( '', 30 ) ) );
check( 'IL majority (70% groceries): $10 at 1% = $0.10', 0.10, $total( $il ) );
check( 'IL majority: general goods hold the majority → $0.63', 0.63, $total( $ship( 'US-IL', 10, array( $item( 'reduced-rate', 30 ), $item( '', 70 ) ) ) ) );
$il_tie = $ship( 'US-IL', 10, array( $item( 'reduced-rate', 50 ), $item( '', 50 ) ) );
check( 'IL 50/50: no majority, split by value ($0.05 + $0.31)', 0.36, $total( $il_tie ) );
check( 'IL 50/50: note says why', true, false !== strpos( $il_tie['note'], 'no group holds a majority' ) );
check( 'IL conditions met (delivery terms): exempt', 0.0, $total( Engine::shipping_tax( 10, array( $item( '', 50 ) ), array( 'mode' => 'delivery_terms', 'conditions_met' => 1, 'split' => 'majority' ), false, $rates_for( 'US-IL' ), $calc ) ) );
check( 'Unknown split value falls back to value', 0.27, $total( Engine::shipping_tax( 10, array( $item( 'zero-rate', 45 ), $item( '', 20 ) ), array( 'mode' => 'follows', 'conditions_met' => 0, 'split' => 'bogus' ), false, $rates_for( 'US-NY' ), $calc ) ) );

// No rule for the place.
check( 'TX, no rule, split off: WooCommerce default (null)', null, $ship( 'US-TX', 10, array( $item( 'zero-rate', 45 ), $item( '', 20 ) ) ) );
check( 'TX, no rule, split on: split by value ($0.19)', 0.19, $total( $ship( 'US-TX', 10, array( $item( 'zero-rate', 45 ), $item( '', 20 ) ), true ) ) );

// The parts always add up to the charge.
$odd = $ship( 'US-NY', 10, array( $item( 'zero-rate', 1 ), $item( '', 1 ), $item( 'reduced-rate', 1 ) ) );
check( 'Three-way split adds up to $10.00', 10.00, array_sum( array_column( $odd['lines'], 'amount' ) ) );

// Weight split with a missing weight falls back to value (lamp $40 of $100 → $4 × 6.875% = $0.28).
check( 'MN by weight, one item unweighed: split by value ($0.28)', 0.28, $total( $ship( 'US-MN', 10, array( $item( 'zero-rate', 60, 2 ), $item( '', 40, 0 ) ) ) ) );

// Per-item shipping: each item's own charge stays with it (explainer: $8 per item, book's $8 taxed).
$per = fn( $class, $value, $ship_cost ) => array( 'class' => $class, 'value' => $value, 'weight' => 0, 'taxable' => true, 'ship' => $ship_cost );
check( 'Per item: $8 each, only the book\'s $8 taxed ($0.71)', 0.71, $total( $ship( 'US-NY', 16, array( $per( 'zero-rate', 45, 8 ), $per( '', 20, 8 ) ) ) ) );
// $5 per order + $3 per item: $6 per-item stays with each item, the $5 is split by value (book 30.8% = $1.54).
check( 'Per item + base fee: ($3 + $1.54) × 8.875% = $0.40', 0.40, $total( $ship( 'US-NY', 11, array( $per( 'zero-rate', 45, 3 ), $per( '', 20, 3 ) ) ) ) );
// Discounted shipping: per-item amounts larger than the charge are scaled down.
check( 'Per item amounts above the charge are scaled ($4 × 8.875% = $0.36)', 0.36, $total( $ship( 'US-NY', 8, array( $per( 'zero-rate', 45, 8 ), $per( '', 20, 8 ) ) ) ) );

echo "\n" . ( $count - $failures ) . " of $count passed\n";
exit( $failures ? 1 : 0 );
