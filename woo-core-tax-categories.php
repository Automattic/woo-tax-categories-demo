<?php
/**
 * Plugin Name:       Tax Categories for WooCommerce (core prototype)
 * Description:       Prototype of the proposed core feature: tax categories on products, category rules per place, and shipping tax that follows the items. Off until you turn it on under WooCommerce → Settings → Tax.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * Author:            WooCommerce Tax prototype
 * License:           GPL-2.0-or-later
 * Text Domain:       wctc
 *
 * @package WCTC
 */

defined( 'ABSPATH' ) || exit;

define( 'WCTC_FILE', __FILE__ );

require_once __DIR__ . '/src/Engine.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Resolver.php';
require_once __DIR__ . '/src/Shipping.php';
require_once __DIR__ . '/src/Examples.php';
require_once __DIR__ . '/src/Admin/Settings.php';
require_once __DIR__ . '/src/Admin/ProductFields.php';
require_once __DIR__ . '/src/Admin/Guide.php';
require_once __DIR__ . '/src/Admin/TaxFreeReport.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\WCTC\Resolver::init();
		\WCTC\Shipping::init();
		if ( is_admin() ) {
			\WCTC\Admin\Settings::init();
			\WCTC\Admin\ProductFields::init();
			\WCTC\Admin\Guide::init();
			\WCTC\Admin\TaxFreeReport::init();
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax&section=tax_categories' ) ) . '">' . esc_html__( 'Tax categories', 'wctc' ) . '</a>' );
		return $links;
	}
);
