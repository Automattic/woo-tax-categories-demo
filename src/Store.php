<?php
/**
 * Settings and data storage. Everything lives in new options and meta keys; nothing existing is rewritten.
 *
 * @package WCTC
 */

namespace WCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the prototype's data.
 */
class Store {

	const OPT_ENABLED        = 'wctc_enabled';
	const OPT_SHIPPING_SPLIT = 'wctc_shipping_split';
	const OPT_CATEGORIES     = 'wctc_categories';
	const OPT_RULES          = 'wctc_category_rules';
	const OPT_SHIPPING_RULES = 'wctc_shipping_rules';
	const OPT_VERSION        = 'wctc_data_version';

	const META_TERM      = 'wctc_tax_category';
	const META_PRODUCT   = '_wctc_tax_category';
	const EXAMPLE_PREFIX = 'WCTC example';

	/** Whether tax categories are switched on. */
	public static function enabled() {
		return 'yes' === get_option( self::OPT_ENABLED, 'no' );
	}

	/** Whether shipping is split by value in places that have no shipping rule. */
	public static function shipping_split() {
		return 'yes' === get_option( self::OPT_SHIPPING_SPLIT, 'no' );
	}

	/** Whether shipping rules are applied at all (either setting on). */
	public static function shipping_active() {
		return self::enabled() || self::shipping_split();
	}

	/**
	 * Tax categories, keyed by slug.
	 *
	 * @return array<string,array{name:string,provider_code:string}>
	 */
	public static function categories() {
		$value = get_option( self::OPT_CATEGORIES, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Category rule rows. */
	public static function rules() {
		$value = get_option( self::OPT_RULES, array() );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/** Shipping rule rows. */
	public static function shipping_rules() {
		$value = get_option( self::OPT_SHIPPING_RULES, array() );
		return is_array( $value ) ? array_values( $value ) : array();
	}

	/** Save categories. */
	public static function save_categories( $value ) {
		update_option( self::OPT_CATEGORIES, $value, false );
		self::bump();
	}

	/** Save category rules. */
	public static function save_rules( $value ) {
		update_option( self::OPT_RULES, array_values( $value ), false );
		self::bump();
	}

	/** Save shipping rules. */
	public static function save_shipping_rules( $value ) {
		update_option( self::OPT_SHIPPING_RULES, array_values( $value ), false );
		self::bump();
	}

	/** A number that changes whenever data changes, so cached shipping rates are recalculated. */
	public static function version() {
		return (int) get_option( self::OPT_VERSION, 1 );
	}

	/** Change the data version. */
	public static function bump() {
		update_option( self::OPT_VERSION, self::version() + 1, false );
	}

	/** Display name for a category slug. */
	public static function category_name( $slug ) {
		$categories = self::categories();
		return isset( $categories[ $slug ] ) ? $categories[ $slug ]['name'] : $slug;
	}

	/**
	 * Tax class choices, slug => name, with '' for Standard.
	 *
	 * @return array<string,string>
	 */
	public static function tax_classes() {
		$classes = array( '' => __( 'Standard', 'woocommerce' ) );
		foreach ( \WC_Tax::get_tax_classes() as $name ) {
			$classes[ sanitize_title( $name ) ] = $name;
		}
		return $classes;
	}

	/** Display name for a tax class slug. */
	public static function class_name( $slug ) {
		$classes = self::tax_classes();
		return $classes[ $slug ] ?? $slug;
	}
}
