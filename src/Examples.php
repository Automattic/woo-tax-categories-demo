<?php
/**
 * Example data matching the requirements doc's acceptance examples, so the prototype is quick to try.
 *
 * @package WCTC
 */

namespace WCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Load and clear example data.
 */
class Examples {

	/** Example categories, rules and shipping rules. Existing entries with other slugs are kept. */
	public static function load() {
		$categories = Store::categories() + array(
			'clothing'      => array( 'name' => 'Clothing', 'provider_code' => 'txcd_30011000' ),
			'groceries'     => array( 'name' => 'Groceries', 'provider_code' => 'txcd_40060003' ),
			'books'         => array( 'name' => 'Books', 'provider_code' => 'txcd_35010000' ),
			'digital-books' => array( 'name' => 'Digital books', 'provider_code' => 'txcd_10302000' ),
		);
		Store::save_categories( $categories );

		Store::save_rules(
			array(
				array( 'category' => 'clothing', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '110', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'clothing', 'country' => 'US', 'state' => 'MN', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'groceries', 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'books', 'country' => 'GB', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'digital-books', 'country' => 'GB', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'zero-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'groceries', 'country' => 'US', 'state' => 'IL', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'reduced-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'books', 'country' => 'BE', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'limit_mode' => Engine::LIMIT_CLIFF, 'tax_class' => 'reduced-rate', 'start' => '', 'end' => '' ),
				array( 'category' => 'clothing', 'country' => 'US', 'state' => 'MA', 'postcode' => '*', 'max_price' => '175', 'limit_mode' => Engine::LIMIT_EXCESS, 'tax_class' => '', 'start' => '', 'end' => '' ),
				array( 'category' => 'clothing', 'country' => 'US', 'state' => 'RI', 'postcode' => '*', 'max_price' => '250', 'limit_mode' => Engine::LIMIT_EXCESS, 'tax_class' => '', 'start' => '', 'end' => '' ),
			)
		);

		Store::save_shipping_rules(
			array(
				array( 'country' => 'US', 'state' => 'NY', 'postcode' => '*', 'mode' => Engine::MODE_FOLLOWS, 'conditions_met' => 0, 'split' => 'value' ),
				array( 'country' => 'US', 'state' => 'AZ', 'postcode' => '*', 'mode' => Engine::MODE_EXEMPT_STATED, 'conditions_met' => 0, 'split' => 'value' ),
				array( 'country' => 'US', 'state' => 'CA', 'postcode' => '*', 'mode' => Engine::MODE_CONDITIONAL, 'conditions_met' => 1, 'split' => 'value' ),
				array( 'country' => 'US', 'state' => 'HI', 'postcode' => '*', 'mode' => Engine::MODE_ALWAYS, 'conditions_met' => 0, 'split' => 'value' ),
				array( 'country' => 'US', 'state' => 'MN', 'postcode' => '*', 'mode' => Engine::MODE_FOLLOWS, 'conditions_met' => 0, 'split' => 'weight' ),
				array( 'country' => 'GB', 'state' => '*', 'postcode' => '*', 'mode' => Engine::MODE_FOLLOWS, 'conditions_met' => 0, 'split' => 'value' ),
				array( 'country' => 'US', 'state' => 'IL', 'postcode' => '*', 'mode' => Engine::MODE_DELIVERY_TERMS, 'conditions_met' => 0, 'split' => Engine::SPLIT_MAJORITY ),
				array( 'country' => 'BE', 'state' => '*', 'postcode' => '*', 'mode' => Engine::MODE_FOLLOWS, 'conditions_met' => 0, 'split' => Engine::SPLIT_LOWEST ),
			)
		);
	}

	/**
	 * Standard-class rate rows for the example places. Zero rate needs no rows (no rows = 0%).
	 * Rates are for illustration; NYC is the combined 8.875% split into state and city rows.
	 */
	public static function add_rates() {
		self::remove_rates();
		$rows = array(
			array( 'US', 'NY', '', '', '4.0000', 'NY State', 1 ),
			array( 'US', 'NY', '', 'NEW YORK', '4.8750', 'NYC + MCTD', 2 ),
			array( 'US', 'AZ', '', '', '5.6000', 'AZ State', 1 ),
			array( 'US', 'CA', '', '', '7.2500', 'CA State', 1 ),
			array( 'US', 'HI', '', '', '4.0000', 'HI GET', 1 ),
			array( 'US', 'MN', '', '', '6.8750', 'MN State', 1 ),
			array( 'GB', '', '', '', '20.0000', 'VAT', 1 ),
			array( 'US', 'IL', '', '', '6.2500', 'IL State', 1 ),
			array( 'US', 'IL', 'reduced-rate', '', '1.0000', 'IL food and drugs', 1 ),
			array( 'BE', '', '', '', '21.0000', 'BE VAT', 1 ),
			array( 'BE', '', 'reduced-rate', '', '6.0000', 'BE VAT reduced', 1 ),
			array( 'US', 'MA', '', '', '6.2500', 'MA State', 1 ),
			array( 'US', 'RI', '', '', '7.0000', 'RI State', 1 ),
		);
		foreach ( $rows as $row ) {
			$id = \WC_Tax::_insert_tax_rate(
				array(
					'tax_rate_country'  => $row[0],
					'tax_rate_state'    => $row[1],
					'tax_rate'          => $row[4],
					'tax_rate_name'     => Store::EXAMPLE_PREFIX . ' ' . $row[5],
					'tax_rate_priority' => $row[6],
					'tax_rate_compound' => 0,
					'tax_rate_shipping' => 'AZ' === $row[1] ? 0 : 1,
					'tax_rate_order'    => 0,
					'tax_rate_class'    => $row[2],
				)
			);
			if ( '' !== $row[3] ) {
				\WC_Tax::_update_tax_rate_cities( $id, $row[3] );
			}
		}
	}

	/** Remove example rate rows only. */
	private static function remove_rates() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name LIKE %s", $wpdb->esc_like( Store::EXAMPLE_PREFIX ) . '%' ) ); // phpcs:ignore
		foreach ( $ids as $id ) {
			\WC_Tax::_delete_tax_rate( (int) $id );
		}
	}

	/** Remove example rates and the example categories, rules and shipping rules. */
	public static function clear() {
		self::remove_rates();
		$slugs      = array( 'clothing', 'groceries', 'books', 'digital-books' );
		$categories = Store::categories();
		foreach ( $slugs as $slug ) {
			unset( $categories[ $slug ] );
		}
		Store::save_categories( $categories );
		Store::save_rules( array_filter( Store::rules(), fn( $rule ) => ! in_array( $rule['category'], $slugs, true ) ) );
		$places = array( 'US|NY', 'US|AZ', 'US|CA', 'US|HI', 'US|MN', 'US|IL', 'US|MA', 'US|RI', 'GB|*', 'BE|*' );
		Store::save_shipping_rules( array_filter( Store::shipping_rules(), fn( $rule ) => ! in_array( $rule['country'] . '|' . $rule['state'], $places, true ) ) );
	}
}
