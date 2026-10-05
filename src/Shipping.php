<?php
/**
 * Shipping tax that follows the items: applies each place's shipping rule to every shipping rate.
 *
 * @package WCTC
 */

namespace WCTC;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the single-class shipping tax with the decision flow from the explainer.
 */
class Shipping {

	/** Hidden order item meta keys. Shown on the admin order screen only, never to customers. */
	const META_CATEGORY = '_wctc_tax_category';
	const META_RULE     = '_wctc_tax_rule';
	const META_SHIPPING = '_wctc_shipping_tax';

	/** Hook in. */
	public static function init() {
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'tag_packages' ) );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'retax_rates' ), 50, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'note_line_item' ), 10, 4 );
		add_action( 'woocommerce_order_before_calculate_taxes', array( __CLASS__, 'resolve_order_items' ), 10, 2 );
		add_action( 'woocommerce_order_item_shipping_after_calculate_taxes', array( __CLASS__, 'retax_order_shipping' ), 10, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'show_admin_meta' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_raw_meta' ) );
	}

	/**
	 * Whether places without a shipping rule split shipping by value. On whenever tax categories are on
	 * (shipping follows the goods by default); the separate setting covers stores without categories.
	 */
	public static function split_without_rule() {
		return Store::enabled() || Store::shipping_split();
	}

	/**
	 * Add what the shipping tax depends on to each package, so cached rates refresh when it changes:
	 * our settings and rules, and the billing address for stores that tax by billing address.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public static function tag_packages( $packages ) {
		$tag = Store::version() . '|' . get_option( Store::OPT_ENABLED ) . get_option( Store::OPT_SHIPPING_SPLIT ) . '|' . get_option( 'woocommerce_tax_based_on' );
		if ( 'billing' === get_option( 'woocommerce_tax_based_on' ) && WC()->customer ) {
			$tag .= '|' . implode( ',', array( WC()->customer->get_billing_country(), WC()->customer->get_billing_state(), WC()->customer->get_billing_postcode(), WC()->customer->get_billing_city() ) );
		}
		foreach ( $packages as $i => $package ) {
			$packages[ $i ]['wctc'] = $tag;
		}
		return $packages;
	}

	/**
	 * Look up rates for a class at a location. Places with a shipping rule ignore the rate row's
	 * Shipping checkbox (the rule decides); places without one respect it, as today.
	 *
	 * @param array  $location Location.
	 * @param string $class    Tax class slug.
	 * @param bool   $has_rule Whether the place has a shipping rule.
	 * @return array
	 */
	private static function rates( $location, $class, $has_rule ) {
		$args = array(
			'country'   => $location['country'],
			'state'     => $location['state'],
			'postcode'  => $location['postcode'],
			'city'      => $location['city'],
			'tax_class' => $class,
		);
		return $has_rule ? \WC_Tax::find_rates( $args ) : \WC_Tax::find_shipping_rates( $args );
	}

	/**
	 * Run the decision flow for one charge.
	 *
	 * @param float $cost     Shipping cost.
	 * @param array $items    Engine items.
	 * @param array $location Where the charge is taxed.
	 * @return array|null
	 */
	public static function calculate( $cost, $items, $location ) {
		$rule = Store::enabled() ? Engine::match_shipping_rule( $location, Store::shipping_rules() ) : null;
		return Engine::shipping_tax(
			$cost,
			$items,
			$rule,
			self::split_without_rule(),
			function ( $class, $has_rule ) use ( $location ) {
				return self::rates( $location, $class, $has_rule );
			},
			function ( $amount, $rates ) {
				return \WC_Tax::calc_tax( $amount, $rates, false );
			}
		);
	}

	/**
	 * Describe a result for the order screen.
	 *
	 * @param array $result Engine result.
	 * @return string
	 */
	public static function describe( $result ) {
		$parts = array( $result['note'] );
		if ( count( $result['lines'] ) > 1 ) {
			foreach ( $result['lines'] as $line ) {
				$label   = '__exempt' === $line['class'] ? 'Not taxable' : Store::class_name( $line['class'] );
				$parts[] = sprintf( '%s %s%% → %s', $label, wc_format_decimal( $line['share'] * 100, 1 ), wp_strip_all_tags( wc_price( $line['amount'] ) ) );
			}
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Where to resolve rules for items in a package, before we know which rate/method will be chosen.
	 * Matches "Calculate tax based on": shipping address (default), billing address or store base.
	 *
	 * @param array $package Package.
	 * @return array
	 */
	public static function location_for_rate_destination( $package ) {
		$based_on = get_option( 'woocommerce_tax_based_on' );
		if ( 'base' === $based_on ) {
			return self::base_location();
		}
		if ( 'billing' === $based_on && WC()->customer ) {
			$c = WC()->customer;
			return array(
				'country'  => $c->get_billing_country(),
				'state'    => $c->get_billing_state(),
				'postcode' => $c->get_billing_postcode(),
				'city'     => $c->get_billing_city(),
			);
		}
		$destination = $package['destination'] ?? array();
		return array(
			'country'  => $destination['country'] ?? '',
			'state'    => $destination['state'] ?? '',
			'postcode' => $destination['postcode'] ?? '',
			'city'     => $destination['city'] ?? '',
		);
	}

	/** Store base address as a location. */
	private static function base_location() {
		return array(
			'country'  => WC()->countries->get_base_country(),
			'state'    => WC()->countries->get_base_state(),
			'postcode' => WC()->countries->get_base_postcode(),
			'city'     => WC()->countries->get_base_city(),
		);
	}

	/** Shipping method IDs that mean "collected from the store". */
	private static function pickup_method_ids() {
		return array_merge( (array) apply_filters( 'woocommerce_local_pickup_methods', array( 'legacy_local_pickup', 'local_pickup' ) ), array( 'pickup_location' ) );
	}

	/**
	 * Where a rate's tax applies, matching how WooCommerce taxes the items: pickup at the store, and the
	 * "Calculate tax based on" setting (shipping address, billing address or store base) otherwise.
	 *
	 * @param \WC_Shipping_Rate $rate    Rate.
	 * @param array             $package Package.
	 * @return array
	 */
	private static function location_for_rate( $rate, $package ) {
		if ( in_array( $rate->get_method_id(), self::pickup_method_ids(), true ) && apply_filters( 'woocommerce_apply_base_tax_for_local_pickup', true ) ) {
			return self::base_location();
		}
		$based_on = get_option( 'woocommerce_tax_based_on' );
		if ( 'base' === $based_on ) {
			return self::base_location();
		}
		if ( 'billing' === $based_on && WC()->customer ) {
			$c = WC()->customer;
			return array(
				'country'  => $c->get_billing_country(),
				'state'    => $c->get_billing_state(),
				'postcode' => $c->get_billing_postcode(),
				'city'     => $c->get_billing_city(),
			);
		}
		$destination = $package['destination'] ?? array();
		return array(
			'country'  => $destination['country'] ?? '',
			'state'    => $destination['state'] ?? '',
			'postcode' => $destination['postcode'] ?? '',
			'city'     => $destination['city'] ?? '',
		);
	}

	/**
	 * The part of a flat rate charge priced per item, by cart item key. Flat rate costs like "8 * [qty]" or
	 * "5 + 2 * [qty]" price each item; the fixed part is shared. Other methods can supply their own
	 * per-item amounts through the `wctc_shipping_item_costs` filter (Table Rate, per-product shipping).
	 *
	 * @param \WC_Shipping_Rate $rate    Rate.
	 * @param array             $package Package.
	 * @return array<string,float>
	 */
	private static function per_item_costs( $rate, $package ) {
		$costs = array();
		if ( 'flat_rate' === $rate->get_method_id() ) {
			$method = \WC_Shipping_Zones::get_shipping_method( $rate->get_instance_id() );
			if ( $method instanceof \WC_Shipping_Flat_Rate ) {
				$formula = (string) $method->get_option( 'cost' );
				if ( false !== strpos( $formula, '[qty]' ) || false !== strpos( $formula, '[cost]' ) || false !== strpos( $formula, '[fee' ) ) {
					try {
						$evaluate = new \ReflectionMethod( $method, 'evaluate_cost' );
						$evaluate->setAccessible( true );
						$fixed = (float) $evaluate->invoke( $method, $formula, array( 'qty' => 0, 'cost' => 0 ) );
						foreach ( $package['contents'] ?? array() as $key => $values ) {
							$cost  = (float) $evaluate->invoke( $method, $formula, array( 'qty' => (float) $values['quantity'], 'cost' => (float) ( $values['line_total'] ?? 0 ) ) );
							$costs[ $key ] = max( 0.0, $cost - $fixed );
						}
					} catch ( \Throwable $e ) {
						$costs = array();
					}
				}
			}
		}
		return (array) apply_filters( 'wctc_shipping_item_costs', $costs, $rate, $package );
	}

	/**
	 * Re-tax each shipping rate for its package.
	 *
	 * @param \WC_Shipping_Rate[] $rates   Rates.
	 * @param array               $package Package.
	 * @return array
	 */
	public static function retax_rates( $rates, $package ) {
		if ( ! Store::shipping_active() || ! wc_tax_enabled() ) {
			return $rates;
		}

		// Item values for the split: line totals after discounts. Fall back to list prices only when
		// discounts leave the whole basket at zero, so there's still something to split by.
		// Excess-only lines split their value: the taxable portion into the rule's class group, the
		// exempt portion into an __exempt sibling, so shipping-follows-goods treats each part correctly.
		$items  = array();
		$extras = array();
		foreach ( $package['contents'] ?? array() as $key => $values ) {
			$product = $values['data'] ?? null;
			if ( ! $product instanceof \WC_Product || ! $product->needs_shipping() ) {
				continue;
			}
			$qty    = (float) $values['quantity'];
			$value  = isset( $values['line_total'] ) ? (float) $values['line_total'] : (float) $product->get_price( 'edit' ) * $qty;
			$list   = (float) $product->get_price( 'edit' ) * $qty;
			$weight = (float) $product->get_weight() * $qty;
			$class  = $product->get_tax_class();
			$taxable = in_array( $product->get_tax_status(), array( 'taxable', 'shipping' ), true );
			$items[ $key ] = compact( 'class', 'taxable', 'value', 'list', 'weight' );
			$split = $taxable ? Resolver::excess_split_for( $product, self::location_for_rate_destination( $package ), $qty > 0 ? $value / $qty : $value ) : null;
			if ( $split ) {
				// Shrink the main entry to the taxable portion, add an exempt sibling for the rest.
				$fraction = (float) $split['taxable_fraction'];
				$items[ $key ]['class'] = (string) $split['tax_class'];
				$items[ $key ]['value'] = $value * $fraction;
				$items[ $key ]['list']  = $list * $fraction;
				$items[ $key ]['weight'] = $weight * $fraction;
				$extras[ '__wctc_exempt_' . $key ] = array(
					'class'   => 'zero-rate',
					'taxable' => true,
					'value'   => $value * ( 1 - $fraction ),
					'list'    => $list * ( 1 - $fraction ),
					'weight'  => $weight * ( 1 - $fraction ),
				);
			}
		}
		$items = $items + $extras;
		if ( $items && array_sum( array_column( $items, 'value' ) ) <= 0 ) {
			foreach ( $items as $key => $item ) {
				$items[ $key ]['value'] = $item['list'];
			}
		}

		foreach ( $rates as $rate ) {
			$method = \WC_Shipping_Zones::get_shipping_method( $rate->get_instance_id() );
			if ( $method && ! $method->is_taxable() ) {
				continue;
			}
			$rate_items = $items;
			foreach ( self::per_item_costs( $rate, $package ) as $key => $amount ) {
				if ( isset( $rate_items[ $key ] ) ) {
					$rate_items[ $key ]['ship'] = (float) $amount;
				}
			}
			$result = self::calculate( (float) $rate->get_cost(), array_values( $rate_items ), self::location_for_rate( $rate, $package ) );
			if ( null === $result ) {
				continue;
			}
			$rate->set_taxes( array_map( 'wc_round_tax_total', $result['taxes'] ) );
			$rate->add_meta_data( self::META_SHIPPING, self::describe( $result ) );
		}
		return $rates;
	}

	/**
	 * Describe the applied category and rule for an order line.
	 *
	 * @param array $applied Resolver::rule_for() result.
	 * @return array{0:string,1:string}
	 */
	private static function describe_line( $applied ) {
		if ( '' === $applied['category'] ) {
			if ( 'conflict' === $applied['source'] ) {
				return array( 'Conflict between product categories → own tax class', '' );
			}
			return array( 'None (own tax class)', '' );
		}
		$category = Store::category_name( $applied['category'] ) . ' (' . $applied['source'] . ')';
		$rule     = $applied['rule'] ?? $applied['skipped'];
		if ( ! $rule ) {
			return array( $category, 'No rule for this place → own class' );
		}
		$state = trim( (string) ( $rule['state'] ?? '' ) );
		$where = $rule['country'] . ( ( '' === $state || '*' === $state ) ? '' : ' ' . $state );
		$mode  = ( $rule['limit_mode'] ?? Engine::LIMIT_CLIFF ) === Engine::LIMIT_EXCESS ? Engine::LIMIT_EXCESS : Engine::LIMIT_CLIFF;
		$price = '' !== (string) ( $rule['max_price'] ?? '' ) ? wp_strip_all_tags( wc_price( $rule['max_price'] ) ) : '';
		$limit = '';
		if ( '' !== $price ) {
			$limit = Engine::LIMIT_EXCESS === $mode ? ', first ' . $price . ' exempt' : ', under ' . $price;
		}
		if ( $applied['skipped'] ) {
			return array( $category, $where . $limit . ' → ' . Store::class_name( $rule['tax_class'] ) . ' has no rates here, so the rule was skipped → own class' );
		}
		return array( $category, $where . $limit . ' → ' . Store::class_name( $rule['tax_class'] ) );
	}

	/**
	 * Record each line's tax category and rule on the order (hidden meta, shown in admin only).
	 *
	 * @param \WC_Order_Item_Product $item          Item.
	 * @param string                 $cart_item_key Key.
	 * @param array                  $values        Cart values.
	 * @param \WC_Order              $order         Order.
	 */
	public static function note_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! Store::enabled() ) {
			return;
		}
		$product = $values['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		list( $category, $rule ) = self::describe_line( Resolver::rule_for( $product, Resolver::customer_location() ) );
		$item->update_meta_data( self::META_CATEGORY, $category );
		$item->update_meta_data( self::META_RULE, $rule );
	}

	/**
	 * Before an order's taxes are (re)calculated, resolve each line's tax class against the ORDER's
	 * address, not whoever is logged in. Covers admin-created orders, items added in admin and Recalculate.
	 *
	 * @param array     $args  Location args (country, state, postcode, city).
	 * @param \WC_Order $order Order.
	 */
	public static function resolve_order_items( $args, $order ) {
		if ( ! Store::enabled() || ! $order instanceof \WC_Abstract_Order ) {
			return;
		}
		// The same location WooCommerce is about to tax the order at (args can be empty).
		$tax_for  = method_exists( $order, 'get_taxable_location' ) ? $order->get_taxable_location( (array) $args ) : (array) $args;
		$location = array(
			'country'  => $tax_for['country'] ?? '',
			'state'    => $tax_for['state'] ?? '',
			'postcode' => $tax_for['postcode'] ?? '',
			'city'     => $tax_for['city'] ?? '',
		);
		if ( '' === $location['country'] ) {
			return;
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$qty   = max( 1, (float) $item->get_quantity() );
			$price = (float) $item->get_subtotal() / $qty;
			$item->set_tax_class( Resolver::class_for( $product, $location, $price ) );
			list( $category, $rule ) = self::describe_line( Resolver::rule_for( $product, $location, $price ) );
			$item->update_meta_data( self::META_CATEGORY, $category );
			$item->update_meta_data( self::META_RULE, $rule );
		}
	}

	/**
	 * Keep the split when an admin clicks Recalculate on an order.
	 *
	 * @param \WC_Order_Item $item              Item being recalculated.
	 * @param array          $calculate_tax_for Location.
	 */
	public static function retax_order_shipping( $item, $calculate_tax_for ) {
		if ( ! $item instanceof \WC_Order_Item_Shipping || ! Store::shipping_active() || ! wc_tax_enabled() ) {
			return;
		}
		$order = $item->get_order();
		if ( ! $order ) {
			return;
		}
		if ( 'taxable' !== $item->get_tax_status() ) {
			return;
		}
		$items    = array();
		$location = array(
			'country'  => $calculate_tax_for['country'] ?? '',
			'state'    => $calculate_tax_for['state'] ?? '',
			'postcode' => $calculate_tax_for['postcode'] ?? '',
			'city'     => $calculate_tax_for['city'] ?? '',
		);
		foreach ( $order->get_items( 'line_item' ) as $line ) {
			$product = $line->get_product();
			if ( $product && ! $product->needs_shipping() ) {
				continue;
			}
			$qty    = max( 1, (float) $line->get_quantity() );
			$value  = (float) $line->get_total();
			$list   = (float) $line->get_subtotal();
			$weight = $product ? (float) $product->get_weight() * $qty : 0;
			$class  = $line->get_tax_class();
			$taxable = in_array( $line->get_tax_status(), array( 'taxable', 'shipping' ), true );
			$items[] = compact( 'class', 'taxable', 'value', 'list', 'weight' );
			$split = $product && $taxable ? Resolver::excess_split_for( $product, $location, $list / $qty ) : null;
			if ( $split ) {
				$fraction = (float) $split['taxable_fraction'];
				$i                       = count( $items ) - 1;
				$items[ $i ]['class']    = (string) $split['tax_class'];
				$items[ $i ]['value']   *= $fraction;
				$items[ $i ]['list']    *= $fraction;
				$items[ $i ]['weight']  *= $fraction;
				$items[] = array(
					'class'   => 'zero-rate',
					'taxable' => true,
					'value'   => $value * ( 1 - $fraction ),
					'list'    => $list * ( 1 - $fraction ),
					'weight'  => $weight * ( 1 - $fraction ),
				);
			}
		}
		if ( $items && array_sum( array_column( $items, 'value' ) ) <= 0 ) {
			foreach ( $items as $i => $line ) {
				$items[ $i ]['value'] = $line['list'];
			}
		}
		if ( in_array( $item->get_method_id(), self::pickup_method_ids(), true ) && apply_filters( 'woocommerce_apply_base_tax_for_local_pickup', true ) ) {
			$location = self::base_location();
		}
		$result = self::calculate( (float) $item->get_total(), $items, $location );
		if ( null === $result ) {
			return;
		}
		$item->set_taxes( array( 'total' => array_map( 'wc_round_tax_total', $result['taxes'] ) ) );
		$item->update_meta_data( self::META_SHIPPING, self::describe( $result ) );
	}

	/**
	 * Keep the raw meta keys out of the admin item meta list; show_admin_meta() displays them instead.
	 *
	 * @param array $keys Hidden meta keys.
	 * @return array
	 */
	public static function hide_raw_meta( $keys ) {
		return array_merge( (array) $keys, array( self::META_CATEGORY, self::META_RULE, self::META_SHIPPING ) );
	}

	/**
	 * Show the hidden notes on the admin order screen.
	 *
	 * @param int            $item_id Item ID.
	 * @param \WC_Order_Item $item    Item.
	 */
	public static function show_admin_meta( $item_id, $item ) {
		if ( ! is_admin() || ! $item instanceof \WC_Order_Item ) {
			return;
		}
		$rows = array(
			'Tax category' => $item->get_meta( self::META_CATEGORY ),
			'Tax rule'     => $item->get_meta( self::META_RULE ),
			'Shipping tax' => $item->get_meta( self::META_SHIPPING ),
		);
		$rows = array_filter( $rows );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="wctc-item-notes" style="margin-top:6px;padding:6px 8px;border:1px dashed #8a63d2;border-radius:4px;font-size:12px;color:#50575e">';
		foreach ( $rows as $label => $value ) {
			echo '<div><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</div>';
		}
		echo '</div>';
	}
}
