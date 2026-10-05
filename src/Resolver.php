<?php
/**
 * Works out each product's tax category and the tax class a rule gives it at the customer's address.
 *
 * @package WCTC
 */

namespace WCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Category resolution: variation, then product, then product category (walking up parents).
 */
class Resolver {

	/** Guard against recursion while we read product data inside a getter filter. */
	private static $busy = false;

	/**
	 * For excess-only rules, the next calc_tax calls for this item must scale the computed tax by
	 * (price - limit) / price. WooCommerce calls calc_tax twice per line (subtotal + total), so the
	 * fraction stays set until two calls have been consumed or until cart totals end.
	 *
	 * @var float|null
	 */
	private static $pending_scale = null;

	/** @var int */
	private static $pending_shots = 0;

	/**
	 * The tax category for a product, and where it came from.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{slug:string,source:string} slug '' when none.
	 */
	public static function category_for( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return array( 'slug' => '', 'source' => '' );
		}
		$categories = Store::categories();

		if ( $product->is_type( 'variation' ) ) {
			$own = (string) $product->get_meta( Store::META_PRODUCT, true, 'edit' );
			if ( '' !== $own && isset( $categories[ $own ] ) ) {
				return array( 'slug' => $own, 'source' => 'variation' );
			}
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return array( 'slug' => '', 'source' => '' );
			}
			$product = $parent;
		}

		$own = (string) $product->get_meta( Store::META_PRODUCT, true, 'edit' );
		if ( '' !== $own && isset( $categories[ $own ] ) ) {
			return array( 'slug' => $own, 'source' => 'product' );
		}

		// Product categories. If they point at different tax categories, don't guess: the product keeps its
		// own tax class until the merchant sets a tax category on the product itself.
		$found_by_slug = array();
		foreach ( $product->get_category_ids( 'edit' ) as $term_id ) {
			$found = self::category_for_term( (int) $term_id );
			if ( '' !== $found['slug'] && ! isset( $found_by_slug[ $found['slug'] ] ) ) {
				$found_by_slug[ $found['slug'] ] = $found;
			}
		}
		if ( 1 === count( $found_by_slug ) ) {
			return reset( $found_by_slug );
		}
		if ( count( $found_by_slug ) > 1 ) {
			return array( 'slug' => '', 'source' => 'conflict', 'conflict' => array_keys( $found_by_slug ) );
		}
		return array( 'slug' => '', 'source' => '' );
	}

	/**
	 * The tax category set on a product category or the nearest parent.
	 *
	 * @param int $term_id product_cat term ID.
	 * @return array{slug:string,source:string,term_id?:int}
	 */
	public static function category_for_term( $term_id ) {
		$categories = Store::categories();
		$seen       = array();
		while ( $term_id && ! isset( $seen[ $term_id ] ) ) {
			$seen[ $term_id ] = true;
			$slug             = (string) get_term_meta( $term_id, Store::META_TERM, true );
			if ( '' !== $slug && isset( $categories[ $slug ] ) ) {
				$term = get_term( $term_id, 'product_cat' );
				return array(
					'slug'    => $slug,
					'source'  => 'product category: ' . ( $term && ! is_wp_error( $term ) ? $term->name : $term_id ),
					'term_id' => $term_id,
				);
			}
			$term    = get_term( $term_id, 'product_cat' );
			$term_id = ( $term && ! is_wp_error( $term ) ) ? (int) $term->parent : 0;
		}
		return array( 'slug' => '', 'source' => '' );
	}

	/**
	 * The customer's taxable address, or the store's base address.
	 *
	 * @return array{country:string,state:string,postcode:string,city:string}
	 */
	public static function customer_location() {
		if ( function_exists( 'WC' ) && WC()->customer ) {
			list( $country, $state, $postcode, $city ) = WC()->customer->get_taxable_address();
		} else {
			$country  = WC()->countries->get_base_country();
			$state    = WC()->countries->get_base_state();
			$postcode = WC()->countries->get_base_postcode();
			$city     = WC()->countries->get_base_city();
		}
		return compact( 'country', 'state', 'postcode', 'city' );
	}

	/**
	 * The rule that applies to a product at a location, if any.
	 *
	 * @param \WC_Product $product  Product.
	 * @param array       $location Location.
	 * @return array{category:string,rule:?array,source:string,skipped:?array,effect:?array,price:float}
	 */
	public static function rule_for( $product, $location, $price = null ) {
		$found = self::category_for( $product );
		if ( '' === $found['slug'] ) {
			return array( 'category' => '', 'rule' => null, 'source' => $found['source'], 'skipped' => null, 'effect' => null, 'price' => 0.0 );
		}
		$price = null === $price ? (float) $product->get_price( 'edit' ) : (float) $price;
		$rule  = Engine::match_category_rule( $found['slug'], $location, $price, gmdate( 'Y-m-d' ), Store::rules() );
		$skipped = null;
		if ( $rule ) {
			$effect = Engine::apply_rule( $rule, $price );
			if ( ! self::class_has_rates( (string) $effect['tax_class'], $location ) ) {
				// The rule points at a class with no rates here; applying it would quietly charge 0%.
				$skipped = $rule;
				$rule    = null;
				$effect  = null;
			}
		} else {
			$effect = null;
		}
		return array( 'category' => $found['slug'], 'rule' => $rule, 'source' => $found['source'], 'skipped' => $skipped, 'effect' => $effect, 'price' => $price );
	}

	/**
	 * Whether a tax class has rates at a location. The built-in Zero rate class is meant to have none.
	 *
	 * @param string $class    Tax class slug.
	 * @param array  $location Location.
	 * @return bool
	 */
	public static function class_has_rates( $class, $location ) {
		if ( 'zero-rate' === $class ) {
			return true;
		}
		$rates = \WC_Tax::find_rates(
			array(
				'country'   => $location['country'] ?? '',
				'state'     => $location['state'] ?? '',
				'postcode'  => $location['postcode'] ?? '',
				'city'      => $location['city'] ?? '',
				'tax_class' => $class,
			)
		);
		return ! empty( $rates );
	}

	/**
	 * The tax class a product should have at a location: the rule's effective class, or the product's own.
	 *
	 * @param \WC_Product $product  Product.
	 * @param array       $location Location.
	 * @param float|null  $price    Price of one item.
	 * @return string
	 */
	public static function class_for( $product, $location, $price = null ) {
		self::$busy = true;
		try {
			$applied = self::rule_for( $product, $location, $price );
			$own     = $product->get_tax_class( 'edit' );
			if ( 'parent' === $own && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$own    = $parent ? $parent->get_tax_class( 'edit' ) : '';
			}
		} finally {
			self::$busy = false;
		}
		return $applied['effect'] ? (string) $applied['effect']['tax_class'] : (string) $own;
	}

	/**
	 * The excess split for a product at a location, if any. Returns the taxable fraction (0..1) of the
	 * item's price so shipping-follows-goods can split the item's value between a taxable and an exempt
	 * group. Returns null when the product has no excess rule, or the item is fully exempt, or fully taxed.
	 *
	 * @param \WC_Product $product  Product.
	 * @param array       $location Location.
	 * @param float|null  $price    Price of one item.
	 * @return array{taxable_fraction:float,tax_class:string}|null
	 */
	public static function excess_split_for( $product, $location, $price = null ) {
		self::$busy = true;
		try {
			$applied = self::rule_for( $product, $location, $price );
		} finally {
			self::$busy = false;
		}
		if ( ! $applied['effect'] || Engine::LIMIT_EXCESS !== ( $applied['effect']['mode'] ?? '' ) ) {
			return null;
		}
		$fraction = (float) $applied['effect']['taxable_fraction'];
		if ( $fraction <= 0 || $fraction >= 1 ) {
			return null;
		}
		return array( 'taxable_fraction' => $fraction, 'tax_class' => (string) $applied['effect']['tax_class'] );
	}

	/** Hook the tax class getters and the calc_tax scale needed for excess-only rules. */
	public static function init() {
		add_filter( 'woocommerce_product_get_tax_class', array( __CLASS__, 'filter_tax_class' ), 20, 2 );
		add_filter( 'woocommerce_product_variation_get_tax_class', array( __CLASS__, 'filter_tax_class' ), 20, 2 );
		add_filter( 'woocommerce_calc_tax', array( __CLASS__, 'filter_calc_tax' ), 10, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reset_pending' ) );
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'reset_pending' ) );
		add_action( 'woocommerce_order_before_calculate_taxes', array( __CLASS__, 'reset_pending' ) );
		add_action( 'woocommerce_order_after_calculate_taxes', array( __CLASS__, 'reset_pending' ) );
	}

	/** Clear any pending excess scale so it never leaks past a cart totals pass. */
	public static function reset_pending() {
		self::$pending_scale = null;
		self::$pending_shots = 0;
	}

	/**
	 * Swap in the rule's effective tax class at checkout. For excess rules with partial taxability,
	 * also arm the pending scale so the next calc_tax calls for this line tax only the excess portion.
	 *
	 * @param string      $tax_class Product's own class.
	 * @param \WC_Product $product   Product.
	 * @return string
	 */
	public static function filter_tax_class( $tax_class, $product ) {
		if ( self::$busy || ! Store::enabled() ) {
			return $tax_class;
		}
		// Only at the storefront and in cart/checkout requests, where there's a customer address.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $tax_class;
		}
		self::$busy = true;
		try {
			$applied = self::rule_for( $product, self::customer_location() );
		} finally {
			self::$busy = false;
		}
		// A new line is being resolved: clear any scale left from the previous line (even if no rule here).
		self::$pending_scale = null;
		self::$pending_shots = 0;
		if ( ! $applied['effect'] ) {
			return $tax_class;
		}
		$fraction = (float) $applied['effect']['taxable_fraction'];
		if ( $fraction < 1.0 ) {
			self::$pending_scale = $fraction;
			// WC_Cart_Totals calls calc_tax twice per line (subtotal + total); allow both.
			self::$pending_shots = 2;
		}
		return (string) $applied['effect']['tax_class'];
	}

	/**
	 * Scale tax by the pending excess fraction. Only applies for the two calc_tax calls that follow the
	 * get_tax_class call for an excess-rule line, so unrelated calc_tax calls pass through unchanged.
	 *
	 * @param array $taxes               Taxes keyed by rate_id.
	 * @param float $price               Amount passed to calc_tax.
	 * @param array $rates               Rates WooCommerce resolved.
	 * @param bool  $price_includes_tax  Whether $price already includes tax.
	 * @return array
	 */
	public static function filter_calc_tax( $taxes, $price, $rates, $price_includes_tax ) {
		if ( null === self::$pending_scale || self::$pending_shots <= 0 ) {
			return $taxes;
		}
		$scale = self::$pending_scale;
		--self::$pending_shots;
		if ( self::$pending_shots <= 0 ) {
			self::$pending_scale = null;
		}
		if ( $scale >= 1.0 ) {
			return $taxes;
		}
		foreach ( $taxes as $id => $tax ) {
			$taxes[ $id ] = (float) $tax * $scale;
		}
		return $taxes;
	}
}
