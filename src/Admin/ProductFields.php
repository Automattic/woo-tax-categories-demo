<?php
/**
 * Tax category fields on product categories, products and variations, plus list columns.
 *
 * @package WCTC
 */

namespace WCTC\Admin;

use WCTC\Engine;
use WCTC\Resolver;
use WCTC\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Product-side admin fields.
 */
class ProductFields {

	/** Hook in. */
	public static function init() {
		// A small, always-on stylesheet keeps the new list columns ("Tax category") from
		// collapsing to one letter per line in narrow viewports. Must not depend on the
		// reviewer guide, which can be filtered off.
		add_action( 'admin_head-edit.php', array( __CLASS__, 'column_width_style' ) );
		add_action( 'admin_head-edit-tags.php', array( __CLASS__, 'column_width_style' ) );
		// Product categories.
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'term_add_field' ), 20 );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'term_edit_field' ), 20 );
		add_action( 'created_product_cat', array( __CLASS__, 'term_save' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'term_save' ) );
		add_filter( 'manage_edit-product_cat_columns', array( __CLASS__, 'term_columns' ) );
		add_filter( 'manage_product_cat_custom_column', array( __CLASS__, 'term_column' ), 10, 3 );

		// Products.
		add_action( 'woocommerce_product_options_tax', array( __CLASS__, 'product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'product_columns' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'product_column' ), 10, 2 );

		// Variations.
		add_action( 'woocommerce_variation_options_tax', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'variation_save' ), 10, 2 );
	}

	/**
	 * Keep the plugin's wp-list-table column headers on one line. Without this, WordPress's
	 * auto-sized columns can squeeze "Tax category" down to one letter per row when there are
	 * many columns, and a shorter neighbour like "Tags" gets pushed along with it.
	 */
	public static function column_width_style() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-product', 'edit-product_cat' ), true ) ) {
			return;
		}
		// Allow word-wrap (so "Tax" and "category" break on the space) but pin a sensible min-width
		// so WordPress's auto-table layout can't squeeze the column into a vertical letter column
		// or overlap it with the neighbouring Tags/Brands cells. "word-spacing: 100vw" would be
		// another route; min-width is simpler and matches other wp-list-table columns.
		echo "<style>" .
			"th.manage-column.column-wctc{min-width:8em;width:8em;word-wrap:normal;overflow-wrap:break-word;}" .
			"td.column-wctc{min-width:7em;word-wrap:break-word;}" .
			"th.manage-column.column-wctc .wctc-badge{display:inline-block;margin-top:2px;}" .
			"</style>\n";
	}

	/** Category choices with a leading "none" option. */
	private static function options( $none_label ) {
		$out = array( '' => $none_label );
		foreach ( Store::categories() as $slug => $cat ) {
			$out[ $slug ] = $cat['name'];
		}
		return $out;
	}

	/** Select markup. */
	private static function select( $name, $id, $options, $selected ) {
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Add-category form field. */
	public static function term_add_field() {
		?>
		<div class="form-field term-wctc-wrap">
			<label for="wctc_tax_category"><?php esc_html_e( 'Tax category', 'wctc' ); ?></label>
			<?php echo self::select( 'wctc_tax_category', 'wctc_tax_category', self::options( __( 'None (use each product\'s tax class)', 'wctc' ) ), '' ); // phpcs:ignore ?>
			<p class="description"><?php esc_html_e( 'Products in this category, and in its child categories, use this tax category unless a product sets its own.', 'wctc' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Edit-category form field.
	 *
	 * @param \WP_Term $term Term.
	 */
	public static function term_edit_field( $term ) {
		$value = get_term_meta( $term->term_id, Store::META_TERM, true );
		$inherited = '' === $value && $term->parent ? Resolver::category_for_term( (int) $term->parent ) : null;
		?>
		<tr class="form-field term-wctc-wrap">
			<th scope="row"><label for="wctc_tax_category"><?php esc_html_e( 'Tax category', 'wctc' ); ?></label></th>
			<td>
				<?php echo self::select( 'wctc_tax_category', 'wctc_tax_category', self::options( __( 'None (use each product\'s tax class)', 'wctc' ) ), $value ); // phpcs:ignore ?>
				<p class="description">
					<?php esc_html_e( 'Products in this category, and in its child categories, use this tax category unless a product sets its own.', 'wctc' ); ?>
					<?php if ( $inherited && '' !== $inherited['slug'] ) : ?>
						<br><?php echo esc_html( sprintf( __( 'Currently inherits %1$s from %2$s.', 'wctc' ), Store::category_name( $inherited['slug'] ), $inherited['source'] ) ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save a category's tax category.
	 *
	 * @param int $term_id Term.
	 */
	public static function term_save( $term_id ) {
		if ( ! isset( $_POST['wctc_tax_category'] ) || ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore
			return;
		}
		$slug = sanitize_title( wp_unslash( $_POST['wctc_tax_category'] ) ); // phpcs:ignore
		if ( '' === $slug ) {
			delete_term_meta( $term_id, Store::META_TERM );
		} else {
			update_term_meta( $term_id, Store::META_TERM, $slug );
		}
		Store::bump();
	}

	/** Add the list column. */
	public static function term_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'posts' === $key ) {
				$out['wctc'] = __( 'Tax category', 'wctc' );
			}
			$out[ $key ] = $label;
		}
		return $out;
	}

	/** Render the list column. */
	public static function term_column( $content, $column, $term_id ) {
		if ( 'wctc' !== $column ) {
			return $content;
		}
		$own = get_term_meta( $term_id, Store::META_TERM, true );
		if ( $own ) {
			return '<strong>' . esc_html( Store::category_name( $own ) ) . '</strong>';
		}
		$found = Resolver::category_for_term( (int) $term_id );
		return '' === $found['slug'] ? '—' : '<em>' . esc_html( Store::category_name( $found['slug'] ) ) . ' (inherited)</em>';
	}

	/** Product edit → General: field plus effective tax class preview. */
	public static function product_field() {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product ) {
			return;
		}
		$own      = (string) $product_object->get_meta( Store::META_PRODUCT, true, 'edit' );
		$from_cat = '';
		$found    = array();
		foreach ( $product_object->get_category_ids( 'edit' ) as $term_id ) {
			$hit = Resolver::category_for_term( (int) $term_id );
			if ( '' !== $hit['slug'] ) {
				$found[ $hit['slug'] ] = true;
			}
		}
		$conflict = count( $found ) > 1;
		if ( 1 === count( $found ) ) {
			$from_cat = (string) array_key_first( $found );
		}
		if ( $conflict ) {
			$inherit = sprintf( __( 'Choose one: product categories disagree (%s)', 'wctc' ), implode( ', ', array_map( array( Store::class, 'category_name' ), array_keys( $found ) ) ) );
		} else {
			$inherit = '' === $from_cat ? __( 'None (use the tax class above)', 'wctc' ) : sprintf( __( 'Inherit from product category (%s)', 'wctc' ), Store::category_name( $from_cat ) );
		}
		echo '<div class="options_group show_if_simple show_if_variable show_if_external">';
		woocommerce_wp_select(
			array(
				'id'          => 'wctc_tax_category',
				'label'       => __( 'Tax category', 'wctc' ),
				'value'       => $own,
				'options'     => self::options( $inherit ),
				'desc_tip'    => true,
				'description' => __( 'What this product is for tax purposes. Rules for the ship-to place use it to pick the tax class.', 'wctc' ),
			)
		);
		if ( $conflict && '' === $own ) {
			echo '<p class="form-field" style="background:#fcf0f1;border-left:4px solid #d63638;padding:8px 12px;margin:0 12px 12px;">' . esc_html__( 'This product\'s categories point at different tax categories, so it uses its own tax class until you pick one above.', 'wctc' ) . '</p>';
		}
		$effective = '' !== $own ? $own : $from_cat;
		if ( '' !== $effective ) {
			$rows = array();
			foreach ( Store::rules() as $rule ) {
				if ( $rule['category'] !== $effective ) {
					continue;
				}
				$place  = ( '*' === $rule['country'] ? __( 'Anywhere', 'wctc' ) : $rule['country'] ) . ( '*' !== $rule['state'] ? ', ' . $rule['state'] : '' ) . ( '*' !== $rule['postcode'] ? ' ' . $rule['postcode'] : '' );
				$mode   = ( $rule['limit_mode'] ?? Engine::LIMIT_CLIFF ) === Engine::LIMIT_EXCESS ? Engine::LIMIT_EXCESS : Engine::LIMIT_CLIFF;
				$price  = '' !== (string) $rule['max_price'] ? wp_strip_all_tags( wc_price( $rule['max_price'] ) ) : '';
				$limit  = '';
				if ( '' !== $price ) {
					$limit = Engine::LIMIT_EXCESS === $mode ? sprintf( ', first %s per item exempt', $price ) : sprintf( ', items under %s', $price );
				}
				$where  = array( 'country' => '*' === $rule['country'] ? '' : $rule['country'], 'state' => '*' === $rule['state'] ? '' : explode( ';', str_replace( '*', '', $rule['state'] ) )[0], 'postcode' => '*' === $rule['postcode'] ? '' : explode( ';', str_replace( '*', '', $rule['postcode'] ) )[0] );
				$us_wide = 'US' === $where['country'] && '' === $where['state'];
				$skip   = ! $us_wide && '' !== $where['country'] && ! Resolver::class_has_rates( (string) $rule['tax_class'], $where );
				$rows[] = sprintf( '%s: <strong>%s</strong> · %s rule%s%s', esc_html( $place ), esc_html( Store::class_name( $rule['tax_class'] ) ), esc_html( Store::category_name( $effective ) ), esc_html( $limit ), $skip ? ' <span style="color:#d63638">' . esc_html__( '— no rates for this class there, so the rule is skipped and the product keeps its own tax class', 'wctc' ) . '</span>' : '' );
			}
			$rows[] = sprintf( 'Everywhere else: <strong>%s</strong> · product\'s own tax class', esc_html( Store::class_name( $product_object->get_tax_class( 'edit' ) ) ) );
			echo '<p class="form-field wctc-preview" style="background:#f6f7f7;border-left:4px solid #7a4fc9;padding:8px 12px;margin:0 12px 12px;"><strong>' . esc_html__( 'Effective tax class', 'wctc' ) . '</strong> (preview' . ( Store::enabled() ? '' : ', tax categories are off' ) . ')<br>' . wp_kses_post( implode( '<br>', $rows ) ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Save the product's tax category.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function product_save( $product ) {
		if ( ! isset( $_POST['wctc_tax_category'] ) ) { // phpcs:ignore
			return;
		}
		$product->update_meta_data( Store::META_PRODUCT, sanitize_title( wp_unslash( $_POST['wctc_tax_category'] ) ) ); // phpcs:ignore
		Store::bump();
	}

	/** Products list column. */
	public static function product_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'product_cat' === $key ) {
				$out['wctc'] = __( 'Tax category', 'wctc' );
			}
		}
		return $out;
	}

	/** Render the products list column. */
	public static function product_column( $column, $post_id ) {
		if ( 'wctc' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		$found   = Resolver::category_for( $product );
		if ( 'conflict' === $found['source'] ) {
			echo '<span style="color:#d63638">' . esc_html__( 'Conflict', 'wctc' ) . '</span><br><small>' . esc_html( implode( ' vs ', array_map( array( Store::class, 'category_name' ), $found['conflict'] ) ) . ' → set one on the product' ) . '</small>';
			return;
		}
		if ( '' === $found['slug'] ) {
			echo '— <br><small>' . esc_html__( 'Own tax class', 'wctc' ) . '</small>';
			return;
		}
		echo '<strong>' . esc_html( Store::category_name( $found['slug'] ) ) . '</strong><br><small>' . esc_html( 'product' === $found['source'] ? 'Set on product' : 'From ' . str_replace( 'product category: ', '', $found['source'] ) ) . '</small>';
	}

	/**
	 * Variation field.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function variation_field( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );
		$value   = $product ? (string) $product->get_meta( Store::META_PRODUCT, true, 'edit' ) : '';
		$parent  = $product ? wc_get_product( $product->get_parent_id() ) : null;
		$from    = $parent ? Resolver::category_for( $parent ) : array( 'slug' => '' );
		$label   = '' === $from['slug'] ? __( 'Same as parent (none)', 'wctc' ) : sprintf( __( 'Same as parent (%s)', 'wctc' ), Store::category_name( $from['slug'] ) );
		woocommerce_wp_select(
			array(
				'id'            => "wctc_variation_tax_category{$loop}",
				'name'          => "wctc_variation_tax_category[{$loop}]",
				'label'         => __( 'Tax category', 'wctc' ),
				'value'         => $value,
				'options'       => self::options( $label ),
				'wrapper_class' => 'form-row form-row-full',
				'desc_tip'      => true,
				'description'   => __( 'Override only when this variation is taxed differently, for example a digital version of a physical product.', 'wctc' ),
			)
		);
	}

	/**
	 * Save a variation's tax category.
	 *
	 * @param int $variation_id Variation.
	 * @param int $i            Index.
	 */
	public static function variation_save( $variation_id, $i ) {
		if ( ! isset( $_POST['wctc_variation_tax_category'][ $i ] ) ) { // phpcs:ignore
			return;
		}
		$variation = wc_get_product( $variation_id );
		if ( $variation ) {
			$variation->update_meta_data( Store::META_PRODUCT, sanitize_title( wp_unslash( $_POST['wctc_variation_tax_category'][ $i ] ) ) ); // phpcs:ignore
			$variation->save_meta_data();
			Store::bump();
		}
	}
}
