<?php
/**
 * Reviewer guide for the prototype: marks every new field (dashed outline + "New" badge with a tooltip),
 * adds a "What's new on this screen" banner with how-to steps, and a guided tour of the new fields.
 * Turn it all off with: add_filter( 'wctc_show_guide', '__return_false' );
 *
 * @package WCTC
 */

namespace WCTC\Admin;

use WCTC\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Per-screen guide content and asset loading.
 */
class Guide {

	/** Hook in. */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Format up to four loaded tax-category names for inline guide text. Returns an empty string when
	 * no categories exist yet (banner steps then read without the parenthetical). Keeps the guide
	 * copy data-driven across different example sets (US: Clothing/Groceries/Books; EU: Books/
	 * Children's clothing/Food/Digital books).
	 *
	 * @param string $template sprintf template with one %s; prepended with a leading space when non-empty.
	 * @return string
	 */
	private static function example_category_list( $template = ' (%s)' ) {
		$cats = array_slice( array_column( Store::categories(), 'name' ), 0, 4 );
		if ( ! $cats ) {
			return '';
		}
		return sprintf( $template, implode( ', ', $cats ) );
	}

	/**
	 * Which guide applies to the current admin screen.
	 *
	 * @return string|null
	 */
	private static function current_key() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$tab     = sanitize_key( $_GET['tab'] ?? '' );
		$section = sanitize_key( $_GET['section'] ?? '' );
		// phpcs:enable
		if ( 'woocommerce_page_wc-settings' === $screen->id && 'tax' === $tab ) {
			if ( '' === $section ) {
				return 'tax_options';
			}
			if ( Settings::SECTION === $section ) {
				return 'tax_categories';
			}
			return null;
		}
		$map = array(
			'edit-product_cat'           => 'product_cat',
			'edit-product'               => 'products',
			'product'                    => 'product',
			'woocommerce_page_wc-orders' => 'order',
			'shop_order'                 => 'order',
		);
		if ( 'woocommerce_page_wc-orders' === $screen->id && empty( $_GET['action'] ) ) { // phpcs:ignore
			return null; // Orders list, not an order.
		}
		return $map[ $screen->id ] ?? null;
	}

	/**
	 * Guide content for every screen. Each field: where to put the badge (label), what to outline,
	 * the tooltip, and the tour text.
	 *
	 * @return array
	 */
	public static function guides() {
		return array(
			'tax_options'    => array(
				'title'  => 'New on this screen: two tax categories settings',
				'intro'  => 'These switch the tax categories prototype on. Both are off after an upgrade, so nothing changes until you tick one.',
				'steps'  => array(
					'Tick <strong>Tax categories</strong> and save. Shipping now follows the goods everywhere, split by value.',
					'Go to <strong>Tax categories</strong> (in the links above) to create categories, rules and shipping rules.',
					'Assign tax categories to your product categories under <strong>Products → Categories</strong>.',
					'Leave <strong>Shipping tax split by tax class</strong> off. Tax categories already split shipping by tax class; this setting is for stores that don\'t use them.',
				),
				'fields' => array(
					array(
						'label'   => '.form-table tr:has(#wctc_enabled) > th',
						'outline' => '.form-table tr:has(#wctc_enabled)',
						'title'   => 'Tax categories',
						'tip'     => 'Turns tax categories on. Rules for each place then pick the tax class for each product, and shipping tax follows the items in the box.',
					),
					array(
						'label'   => '.form-table tr:has(#wctc_shipping_split) > th',
						'outline' => '.form-table tr:has(#wctc_shipping_split)',
						'title'   => 'Shipping tax split by tax class',
						'tip'     => 'For stores not using tax categories: splits shipping tax across the cart\'s tax classes by value instead of taxing it all at one class.',
					),
				),
			),
			'tax_categories' => array(
				'title'  => 'New screen: Tax categories',
				'intro'  => 'Everything on this page is new. It holds the three things the feature adds: tax categories, category rules and shipping rules.',
				'steps'  => array(
					'Create your <strong>tax categories</strong>, or click <strong>Load example data</strong>' . self::example_category_list( ' to start from %s' ) . '.',
					'Add a <strong>category rule</strong> wherever a category is taxed differently: pick the category, the place and the tax class. Check <strong>Rate there</strong> to confirm the class has rates in that place.',
					'Add a <strong>shipping rule</strong> only for places that exempt shipping, always tax it, or treat a mixed basket differently (by weight, at the lowest rate, or at the majority\'s rate). Everywhere else, shipping follows the goods.',
					'Assign the categories to product categories under <strong>Products → Categories</strong>, then try a checkout.',
				),
				'fields' => array(
					array(
						'label'   => '#wctc-examples',
						'outline' => '#wctc-examples',
						'title'   => 'Example data',
						'tip'     => 'Loads the categories, rules and rates from the scope doc\'s examples so you can try a checkout straight away. Remove them with the third button.',
						'badge'   => false,
					),
					array(
						'label'   => '#wctc-h-categories',
						'outline' => '#wctc-t-categories',
						'title'   => 'Tax categories',
						'tip'     => 'What a product is for tax purposes' . self::example_category_list( ' (for example %s)' ) . '. A category never holds a rate. The provider code is optional and lets a tax service such as Stripe Tax use the same category.',
					),
					array(
						'label'   => '#wctc-h-rules',
						'outline' => '#wctc-t-rules',
						'title'   => 'Category rules',
						'tip'     => 'Each rule sends a tax category to one of your tax classes in a place, optionally under a price limit or between dates. The most specific matching rule wins. "Rate there" warns when the class has no rates in that place.',
					),
					array(
						'label'   => '#wctc-h-shipping',
						'outline' => '#wctc-t-shipping',
						'title'   => 'Shipping rules',
						'tip'     => 'Exceptions to "shipping follows the goods": places that exempt shipping shown on its own line, exempt it only under conditions, always tax it, or split a mixed basket by weight, at the lowest rate (Belgium) or at the majority\'s rate (Illinois).',
					),
				),
			),
			'product_cat'    => array(
				'title'  => 'New on this screen: Tax category',
				'intro'  => 'Product categories can now carry a tax category. It\'s the fastest way to categorize products, because every product in the category, and in its child categories, inherits it.',
				'steps'  => array(
					'Edit a product category and pick its <strong>Tax category</strong>' . self::example_category_list( ' (for example %s)' ) . '.',
					'Child categories inherit it automatically; the column shows "(inherited)".',
					'If a product sits in two categories with different tax categories, it shows as a conflict in the products list. Set the tax category on that product to settle it.',
				),
				'fields' => array(
					array(
						'label'   => '.term-wctc-wrap label',
						'outline' => '.term-wctc-wrap',
						'title'   => 'Tax category',
						'tip'     => 'What products in this category are for tax purposes. Child categories inherit it unless they set their own.',
					),
					array(
						'label'   => 'thead th.column-wctc',
						'outline' => 'thead th.column-wctc',
						'title'   => 'Tax category column',
						'tip'     => 'The tax category each product category uses. "(inherited)" means it comes from a parent category.',
					),
				),
			),
			'products'       => array(
				'title'  => 'New on this screen: Tax category column',
				'intro'  => 'See each product\'s tax category and where it comes from, without opening the product.',
				'steps'  => array(
					'Scan the <strong>Tax category</strong> column. "From &lt;name&gt;" means it\'s inherited from a product category.',
					'"Own tax class" means no tax category applies; the product is taxed exactly as before.',
					'Fix any <strong>Conflict</strong> by opening the product and picking a tax category.',
				),
				'fields' => array(
					array(
						'label'   => 'thead th.column-wctc',
						'outline' => 'thead th.column-wctc',
						'title'   => 'Tax category column',
						'tip'     => 'The tax category each product uses and where it comes from: set on the product, inherited from a product category, a conflict, or none.',
					),
				),
			),
			'product'        => array(
				'title'  => 'New on this screen: Tax category and preview',
				'intro'  => 'Products inherit their tax category from their product category. You only change it here when this product is taxed differently from its category.',
				'steps'  => array(
					'In <strong>Product data → General</strong>, leave <strong>Tax category</strong> on Inherit unless this product is different.',
					'Check the <strong>Effective tax class</strong> preview to see which class it gets in each place with a rule.',
					'For variable products, open <strong>Variations</strong>: each variation can override the tax category, for example an e-book version.',
				),
				'fields' => array(
					array(
						'label'   => '.wctc_tax_category_field > label',
						'outline' => '.wctc_tax_category_field',
						'title'   => 'Tax category',
						'tip'     => 'What this product is for tax purposes. "Inherit" uses its product category. The existing Tax class above is unchanged and still applies where no rule does.',
					),
					array(
						'label'   => '.wctc-preview > strong:first-child',
						'outline' => '.wctc-preview',
						'title'   => 'Effective tax class preview',
						'tip'     => 'The tax class this product gets in each place with a rule, and everywhere else. Lets you check a rule before an order comes in.',
					),
					array(
						'label'   => '[class*="wctc_variation_tax_category"] > label',
						'outline' => '[class*="wctc_variation_tax_category"]',
						'title'   => 'Variation tax category',
						'tip'     => 'Overrides the tax category for this variation only, for example a digital version of a physical product. "Same as parent" uses the product\'s.',
						'tour'    => false,
					),
				),
			),
			'order'          => array(
				'title'  => 'New on this screen: tax notes',
				'intro'  => 'Each line now records which tax category and rule applied, and the shipping line shows how its tax was split. Customers never see these notes.',
				'steps'  => array(
					'Read the notes under each line to see why it was taxed the way it was.',
					'Check the shipping line to see the split, for example "Zero rate 69.2% → $6.92".',
					'<strong>Recalculate</strong> keeps the split, and uses the order\'s address for any items you add.',
				),
				'fields' => array(
					array(
						'label'   => '.wctc-item-notes',
						'outline' => '.wctc-item-notes',
						'title'   => 'Tax notes',
						'tip'     => 'Which tax category and rule applied to this line, or how shipping tax was split. Admin only.',
						'badge'   => 'prepend',
					),
				),
			),
		);
	}

	/** Load the guide on screens that have one. */
	public static function enqueue() {
		if ( ! apply_filters( 'wctc_show_guide', true ) ) {
			return;
		}
		$key = self::current_key();
		if ( ! $key ) {
			return;
		}
		$guides = self::guides();
		$base   = plugins_url( 'assets/', WCTC_FILE );
		$ver    = filemtime( dirname( WCTC_FILE ) . '/assets/guide.js' );
		wp_enqueue_style( 'wp-pointer' );
		wp_enqueue_script( 'wp-pointer' );
		wp_enqueue_style( 'wctc-guide', $base . 'guide.css', array( 'wp-pointer' ), $ver );
		wp_enqueue_script( 'wctc-guide', $base . 'guide.js', array( 'jquery', 'wp-pointer' ), $ver, true );
		wp_localize_script(
			'wctc-guide',
			'wctcGuide',
			array(
				'key'   => $key,
				'guide' => $guides[ $key ],
				'all'   => array_map( fn( $g ) => $g['title'], $guides ),
			)
		);
	}
}
