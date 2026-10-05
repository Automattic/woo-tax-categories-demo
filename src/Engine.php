<?php
/**
 * Pure tax-category logic, with no WordPress or WooCommerce calls, so it can be unit tested.
 *
 * @package WCTC
 */

namespace WCTC;

defined( 'ABSPATH' ) || defined( 'WCTC_TESTING' ) || exit;

/**
 * Rule matching and the shipping-tax decision flow.
 */
class Engine {

	/** Shipping rule modes, matching the five rules in "How Shipping Tax Works at Checkout". */
	const MODE_FOLLOWS        = 'follows';         // 1. Follows the goods.
	const MODE_EXEMPT_STATED  = 'exempt_stated';   // 2. Exempt if shown as its own line.
	const MODE_CONDITIONAL    = 'conditional';     // 3. Exempt only under conditions.
	const MODE_ALWAYS         = 'always';          // 4. Always taxed.
	const MODE_DELIVERY_TERMS = 'delivery_terms';  // 5. Depends on delivery terms.

	/** Mixed-basket treatments when shipping follows goods taxed at different rates. */
	const SPLIT_VALUE    = 'value';     // Apportion by line value (default).
	const SPLIT_WEIGHT   = 'weight';    // Apportion by weight (Minnesota).
	const SPLIT_LOWEST   = 'lowest';    // Whole charge at the lowest rate in the box (Belgium).
	const SPLIT_MAJORITY = 'majority';  // Whole charge at the rate of the majority of the value (Illinois).

	/** How a category rule's price limit is interpreted. */
	const LIMIT_CLIFF  = 'cliff';   // The whole item is taxed at the rule's class only when price is under the limit (default; New York).
	const LIMIT_EXCESS = 'excess';  // Only the part of each item's price above the limit is taxed (Massachusetts $175, Rhode Island $250).

	/**
	 * Labels for the price-limit modes.
	 *
	 * @return array<string,string>
	 */
	public static function limit_modes() {
		return array(
			self::LIMIT_CLIFF  => 'Whole item exempt below the limit (cliff)',
			self::LIMIT_EXCESS => 'Only the amount above the limit is taxed (excess)',
		);
	}

	/**
	 * Labels for the mixed-basket treatments.
	 *
	 * @return array<string,string>
	 */
	public static function splits() {
		return array(
			self::SPLIT_VALUE    => 'Split by value',
			self::SPLIT_WEIGHT   => 'Split by weight',
			self::SPLIT_LOWEST   => 'Whole charge at the lowest rate in the box',
			self::SPLIT_MAJORITY => 'Whole charge at the majority\'s rate (by value)',
		);
	}

	/**
	 * Labels for the shipping rule modes.
	 *
	 * @return array<string,string>
	 */
	public static function modes() {
		return array(
			self::MODE_FOLLOWS        => 'Follows the goods',
			self::MODE_EXEMPT_STATED  => 'Exempt if shown as its own line',
			self::MODE_CONDITIONAL    => 'Exempt only under conditions',
			self::MODE_ALWAYS         => 'Always taxed',
			self::MODE_DELIVERY_TERMS => 'Depends on delivery terms',
		);
	}

	/**
	 * How specific a location pattern is. Higher wins.
	 *
	 * @param array $row Rule row with country, state, postcode.
	 * @return int
	 */
	private static function specificity( $row ) {
		$score = 0;
		foreach ( array( 'country' => 1, 'state' => 2, 'postcode' => 4 ) as $key => $weight ) {
			if ( ! self::is_wild( $row[ $key ] ?? '' ) ) {
				$score += $weight;
			}
		}
		return $score;
	}

	/**
	 * Whether a pattern matches everything.
	 *
	 * @param string $value Pattern.
	 * @return bool
	 */
	private static function is_wild( $value ) {
		$value = trim( (string) $value );
		return '' === $value || '*' === $value;
	}

	/**
	 * Whether a place pattern matches a value. Supports "*", exact values, "a;b" lists and "100*" prefixes.
	 *
	 * @param string $pattern Pattern from the rule.
	 * @param string $value   Value from the address.
	 * @return bool
	 */
	public static function place_matches( $pattern, $value ) {
		if ( self::is_wild( $pattern ) ) {
			return true;
		}
		$value = strtoupper( trim( (string) $value ) );
		foreach ( explode( ';', (string) $pattern ) as $part ) {
			$part = strtoupper( trim( $part ) );
			if ( '' === $part ) {
				continue;
			}
			if ( '*' === substr( $part, -1 ) ) {
				if ( 0 === strpos( $value, substr( $part, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $part === $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a rule row's place matches a location.
	 *
	 * @param array $row      Rule row.
	 * @param array $location country, state, postcode.
	 * @return bool
	 */
	private static function location_matches( $row, $location ) {
		return self::place_matches( $row['country'] ?? '', $location['country'] ?? '' )
			&& self::place_matches( $row['state'] ?? '', $location['state'] ?? '' )
			&& self::place_matches( $row['postcode'] ?? '', $location['postcode'] ?? '' );
	}

	/**
	 * Find the category rule that applies to a line, if any.
	 *
	 * @param string $category Tax category slug.
	 * @param array  $location country, state, postcode.
	 * @param float  $price    Price of one item, before tax.
	 * @param string $today    Date as Y-m-d.
	 * @param array  $rules    Category rule rows.
	 * @return array|null The matching rule row, or null.
	 */
	public static function match_category_rule( $category, $location, $price, $today, $rules ) {
		$best       = null;
		$best_score = -1;
		foreach ( $rules as $rule ) {
			if ( ( $rule['category'] ?? '' ) !== $category ) {
				continue;
			}
			if ( ! self::location_matches( $rule, $location ) ) {
				continue;
			}
			$max  = trim( (string) ( $rule['max_price'] ?? '' ) );
			$mode = ( $rule['limit_mode'] ?? self::LIMIT_CLIFF ) === self::LIMIT_EXCESS ? self::LIMIT_EXCESS : self::LIMIT_CLIFF;
			// Cliff rules only match below the limit; excess rules always match (the limit decides the taxable
			// base, not whether the rule fires), so an item under the limit is fully exempt and over it has
			// only the excess taxed.
			if ( self::LIMIT_CLIFF === $mode && '' !== $max && ! ( (float) $price < (float) $max ) ) {
				continue;
			}
			if ( ! empty( $rule['start'] ) && $today < $rule['start'] ) {
				continue;
			}
			if ( ! empty( $rule['end'] ) && $today > $rule['end'] ) {
				continue;
			}
			// Place specificity first; among equals, the tighter price limit wins for cliff rules (a tiered
			// "under $110 → Zero, otherwise Reduced" pair works in either order). Excess rules get a flat
			// score within their specificity: the limit is the taxable threshold, not a tiebreaker.
			$score = self::specificity( $rule ) * 1000;
			if ( self::LIMIT_CLIFF === $mode && '' !== $max ) {
				$score += max( 1, 999 - (int) min( 998, (float) $max ) );
			}
			if ( $score > $best_score ) {
				$best       = $rule;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * How a matched rule changes a line: the tax class to tax at and the fraction of the line's value that
	 * is taxable. Cliff rules return the rule's class on the whole value; excess rules return the rule's
	 * class on (price - limit) / price and treat the rest as exempt.
	 *
	 * @param array $rule  Rule row.
	 * @param float $price Price of one item, before tax.
	 * @return array{tax_class:string,taxable_fraction:float,taxable_amount:float,mode:string}
	 */
	public static function apply_rule( $rule, $price ) {
		$price = max( 0.0, (float) $price );
		$mode  = ( $rule['limit_mode'] ?? self::LIMIT_CLIFF ) === self::LIMIT_EXCESS ? self::LIMIT_EXCESS : self::LIMIT_CLIFF;
		$max   = trim( (string) ( $rule['max_price'] ?? '' ) );
		$class = (string) ( $rule['tax_class'] ?? '' );
		if ( self::LIMIT_EXCESS === $mode && '' !== $max ) {
			$limit  = (float) $max;
			$amount = max( 0.0, $price - $limit );
			if ( $amount <= 0 ) {
				// Fully under the limit: the whole item is exempt.
				return array( 'tax_class' => 'zero-rate', 'taxable_fraction' => 0.0, 'taxable_amount' => 0.0, 'mode' => self::LIMIT_EXCESS );
			}
			return array(
				'tax_class'        => $class,
				'taxable_fraction' => $price > 0 ? $amount / $price : 1.0,
				'taxable_amount'   => $amount,
				'mode'             => self::LIMIT_EXCESS,
			);
		}
		return array( 'tax_class' => $class, 'taxable_fraction' => 1.0, 'taxable_amount' => $price, 'mode' => self::LIMIT_CLIFF );
	}

	/**
	 * Rows in a category rule table that duplicate an earlier row (same category, place and price limit),
	 * so only the first of them can ever match. Keyed by row index → index of the row it duplicates.
	 *
	 * @param array $rules Category rule rows.
	 * @return array<int,int>
	 */
	public static function duplicate_category_rules( $rules ) {
		$seen = array();
		$dups = array();
		foreach ( array_values( $rules ) as $i => $rule ) {
			$place = fn( $v ) => self::is_wild( $v ) ? '*' : strtoupper( trim( (string) $v ) );
			$mode  = ( $rule['limit_mode'] ?? self::LIMIT_CLIFF ) === self::LIMIT_EXCESS ? self::LIMIT_EXCESS : self::LIMIT_CLIFF;
			$key   = implode( '|', array( $rule['category'] ?? '', $place( $rule['country'] ?? '' ), $place( $rule['state'] ?? '' ), $place( $rule['postcode'] ?? '' ), trim( (string) ( $rule['max_price'] ?? '' ) ), $mode, (string) ( $rule['start'] ?? '' ), (string) ( $rule['end'] ?? '' ) ) );
			if ( isset( $seen[ $key ] ) ) {
				$dups[ $i ] = $seen[ $key ];
			} else {
				$seen[ $key ] = $i;
			}
		}
		return $dups;
	}

	/**
	 * Find the shipping rule for a location, if any. A state rule beats a country-wide one.
	 *
	 * @param array $location country, state, postcode.
	 * @param array $rules    Shipping rule rows.
	 * @return array|null
	 */
	public static function match_shipping_rule( $location, $rules ) {
		$best       = null;
		$best_score = -1;
		foreach ( $rules as $rule ) {
			if ( ! self::location_matches( $rule, $location ) ) {
				continue;
			}
			$score = self::specificity( $rule );
			if ( $score > $best_score ) {
				$best       = $rule;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * Work out the tax on one shipping charge, following the explainer's decision flow.
	 *
	 * @param float         $cost         Shipping cost before tax.
	 * @param array         $items        Each: class (string), taxable (bool), value (float), weight (float), label (string).
	 * @param array|null    $rule         Shipping rule row for the destination, or null.
	 * @param bool          $split_no_rule Whether to split by value where no rule exists.
	 * @param callable      $rates_for    fn( string $class, bool $has_rule ): array of rates for that class.
	 * @param callable      $calc         fn( float $amount, array $rates ): array rate_id => tax.
	 * @return array|null null = leave WooCommerce's own calculation alone. Otherwise taxes, lines (breakdown) and note.
	 */
	public static function shipping_tax( $cost, $items, $rule, $split_no_rule, $rates_for, $calc ) {
		$cost = (float) $cost;
		if ( null === $rule && ! $split_no_rule ) {
			return null;
		}
		$has_rule = null !== $rule;
		$mode     = $has_rule ? ( $rule['mode'] ?? self::MODE_FOLLOWS ) : self::MODE_FOLLOWS;
		$met      = $has_rule && ! empty( $rule['conditions_met'] );

		if ( self::MODE_EXEMPT_STATED === $mode ) {
			return self::result( array(), array(), 'Exempt: shipping is shown as its own line' );
		}
		if ( ( self::MODE_CONDITIONAL === $mode || self::MODE_DELIVERY_TERMS === $mode ) && $met ) {
			return self::result( array(), array(), 'Exempt: the store meets this place\'s conditions' );
		}
		if ( self::MODE_ALWAYS === $mode ) {
			$taxes = $calc( $cost, $rates_for( '', $has_rule ) );
			return self::result( $taxes, array( array( 'class' => '', 'share' => 1.0, 'amount' => $cost ) ), 'Always taxed: full charge at the Standard class' );
		}

		// Shipping follows the goods. Group the basket by tax class; untaxable items form one exempt group.
		// An item's 'ship' is the part of the charge priced for that item (per-item shipping); it stays with
		// that item. Only the rest of the charge is split.
		$groups = array();
		foreach ( $items as $item ) {
			$key = ! empty( $item['taxable'] ) ? (string) $item['class'] : '__exempt';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'value' => 0.0, 'weight' => 0.0, 'ship' => 0.0 );
			}
			$groups[ $key ]['value']  += (float) $item['value'];
			$groups[ $key ]['weight'] += (float) $item['weight'];
			$groups[ $key ]['ship']   += max( 0.0, (float) ( $item['ship'] ?? 0 ) );
		}
		if ( ! $groups || $cost <= 0 ) {
			return self::result( array(), array(), 'No shipping charge to tax' );
		}

		$per_item = min( $cost, array_sum( array_column( $groups, 'ship' ) ) );
		if ( $per_item < array_sum( array_column( $groups, 'ship' ) ) ) {
			// Per-item amounts add up to more than the charge (e.g. a discount on shipping): scale them down.
			$scale = $per_item / array_sum( array_column( $groups, 'ship' ) );
			foreach ( $groups as $key => $group ) {
				$groups[ $key ]['ship'] = round( $group['ship'] * $scale, 2 );
			}
		}
		$shared = round( $cost - $per_item, 2 );

		$basis = $has_rule ? (string) ( $rule['split'] ?? self::SPLIT_VALUE ) : self::SPLIT_VALUE;
		if ( ! isset( self::splits()[ $basis ] ) ) {
			$basis = self::SPLIT_VALUE;
		}
		$why = '';
		if ( self::SPLIT_WEIGHT === $basis ) {
			// A weight split needs every item weighed. If any item has no weight, split by value instead
			// of silently treating that item as weightless.
			foreach ( $items as $item ) {
				if ( (float) ( $item['weight'] ?? 0 ) <= 0 ) {
					$basis = self::SPLIT_VALUE;
					$why   = 'an item has no weight';
					break;
				}
			}
		}

		// Decide each group's share of the shared part of the charge.
		$count  = count( $groups );
		$shares = array();
		if ( 1 === $count ) {
			$shares[ array_key_first( $groups ) ] = 1.0;
		} elseif ( self::SPLIT_LOWEST === $basis ) {
			// Belgium's shortcut: the whole charge is taxed at the lowest rate present in the box.
			$lowest = null;
			$low    = null;
			foreach ( $groups as $key => $group ) {
				$pct = '__exempt' === $key ? 0.0 : (float) array_sum( $calc( 100, $rates_for( (string) $key, $has_rule ) ) );
				if ( null === $low || $pct < $low ) {
					$low    = $pct;
					$lowest = $key;
				}
			}
			foreach ( $groups as $key => $group ) {
				$shares[ $key ] = $key === $lowest ? 1.0 : 0.0;
			}
		} elseif ( self::SPLIT_MAJORITY === $basis ) {
			// Illinois's test: the charge takes the rate of the group holding the majority of the value.
			$total = array_sum( array_column( $groups, 'value' ) );
			$top   = null;
			foreach ( $groups as $key => $group ) {
				if ( $total > 0 && $group['value'] / $total > 0.5 ) {
					$top = $key;
				}
			}
			if ( null !== $top ) {
				foreach ( $groups as $key => $group ) {
					$shares[ $key ] = $key === $top ? 1.0 : 0.0;
				}
			} else {
				$basis = self::SPLIT_VALUE;
				$why   = 'no group holds a majority of the value';
			}
		}
		if ( ! $shares ) {
			$total = array_sum( array_column( $groups, $basis ) );
			if ( $total <= 0 && self::SPLIT_WEIGHT === $basis ) {
				$basis = self::SPLIT_VALUE;
				$total = array_sum( array_column( $groups, 'value' ) );
			}
			foreach ( $groups as $key => $group ) {
				$shares[ $key ] = $total > 0 ? $group[ $basis ] / $total : 1.0 / $count;
			}
		}

		$taxes = array();
		$lines = array();
		$left  = $shared;
		$i     = 0;
		foreach ( $groups as $class => $group ) {
			++$i;
			// Give the last group the remainder so the parts add up to the charge exactly.
			$part    = $i === $count ? $left : round( $shared * $shares[ $class ], 2 );
			$left   -= $part;
			$amount  = round( $part + $group['ship'], 2 );
			$lines[] = array( 'class' => $class, 'share' => $cost > 0 ? $amount / $cost : 0, 'amount' => $amount );
			if ( '__exempt' === $class || $amount <= 0 ) {
				continue;
			}
			foreach ( $calc( $amount, $rates_for( (string) $class, $has_rule ) ) as $rate_id => $tax ) {
				$taxes[ $rate_id ] = ( $taxes[ $rate_id ] ?? 0 ) + $tax;
			}
		}

		$how = array(
			self::SPLIT_VALUE    => 'split by value',
			self::SPLIT_WEIGHT   => 'split by weight',
			self::SPLIT_LOWEST   => 'whole charge at the lowest rate in the box',
			self::SPLIT_MAJORITY => 'whole charge at the rate of the majority by value',
		)[ $basis ];
		if ( '' !== $why ) {
			$how .= ' (' . $why . ')';
		}
		if ( 1 === $count ) {
			$note = 'Follows the goods: one tax treatment in the box';
		} elseif ( $per_item > 0 && $shared <= 0 ) {
			$note = 'Follows the goods: each item\'s shipping taxed like the item';
		} elseif ( $per_item > 0 ) {
			$note = 'Follows the goods: per-item charges stay with their item, the rest ' . $how;
		} else {
			$note = 'Follows the goods: ' . $how;
		}
		return self::result( $taxes, $lines, $note );
	}

	/**
	 * Build a shipping result.
	 *
	 * @param array  $taxes rate_id => tax.
	 * @param array  $lines Breakdown lines.
	 * @param string $note  Short explanation.
	 * @return array
	 */
	private static function result( $taxes, $lines, $note ) {
		return array(
			'taxes' => $taxes,
			'lines' => $lines,
			'note'  => $note,
		);
	}
}
