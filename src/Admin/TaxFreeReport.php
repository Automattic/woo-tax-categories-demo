<?php
/**
 * Analytics gap filler: a Tax-free sales report, since stock Analytics → Taxes is keyed by rate row and
 * never shows zero-rated or exempt lines. Prototype only; the proposal is to add this to core Analytics.
 *
 * @package WCTC
 */

namespace WCTC\Admin;

use WCTC\Shipping;
use WCTC\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page: WooCommerce → Tax-free sales.
 */
class TaxFreeReport {

	const PAGE = 'wctc-tax-free';

	/** Hook in. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
	}

	/** Register the submenu. */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Tax-free sales', 'wctc' ),
			__( 'Tax-free sales', 'wctc' ),
			'manage_woocommerce',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/** Admin URL for the report. */
	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** Render the page. */
	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$today        = gmdate( 'Y-m-d' );
		$default_from = gmdate( 'Y-m-d', strtotime( '-29 days' ) );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report page.
		$after  = sanitize_text_field( wp_unslash( $_GET['after'] ?? $default_from ) );
		$before = sanitize_text_field( wp_unslash( $_GET['before'] ?? $today ) );
		// phpcs:enable
		$data = self::aggregate( $after, $before );
		?>
		<div class="wrap wctc-tfr">
			<style>
				/* Keep multi-word column headers on one line even on narrow screens. */
				.wctc-tfr table.widefat thead th { white-space: nowrap; }
			</style>
			<h1><?php esc_html_e( 'Tax-free sales', 'wctc' ); ?></h1>
			<p style="max-width:720px;color:#50575e">
				<?php esc_html_e( 'Sales that paid no tax because of a tax category rule — zero-rated clothing in NY, exempt groceries, UK books, the exempt portion of items above a Massachusetts $175 or Rhode Island $250 clothing limit. Stock Analytics → Taxes is keyed by tax rate row, so these amounts never appear there. This page scans your orders directly.', 'wctc' ); ?>
			</p>
			<?php if ( ! Store::enabled() ) : ?>
				<div class="notice notice-warning inline"><p><?php echo wp_kses_post( __( 'Tax categories are <strong>off</strong>, so no new orders are being tagged. Turn them on under WooCommerce → Settings → Tax to populate this report.', 'wctc' ) ); ?></p></div>
			<?php endif; ?>
			<form method="get" style="margin:12px 0;padding:12px 16px;background:#fff;border:1px solid #c3c4c7;max-width:720px">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<label><?php esc_html_e( 'From', 'wctc' ); ?> <input type="date" name="after" value="<?php echo esc_attr( $after ); ?>"></label>
				&nbsp;<label><?php esc_html_e( 'To', 'wctc' ); ?> <input type="date" name="before" value="<?php echo esc_attr( $before ); ?>"></label>
				&nbsp;<button class="button button-primary"><?php esc_html_e( 'Update', 'wctc' ); ?></button>
				<span style="margin-left:14px;color:#50575e;font-size:13px">
					<?php foreach ( self::shortcuts() as $shortcut ) : ?>
						<a href="<?php echo esc_url( self::url( $shortcut['range'] ) ); ?>" style="margin-right:10px"><?php echo esc_html( $shortcut['label'] ); ?></a>
					<?php endforeach; ?>
				</span>
			</form>
			<div style="display:flex;gap:12px;margin:12px 0;max-width:720px;flex-wrap:wrap">
				<?php
				$cards = array(
					array( __( 'Tax-free sales', 'wctc' ), $data['total_tax_free'], sprintf( _n( '%d order', '%d orders', $data['orders'], 'wctc' ), $data['orders'] ), '#00a32a' ),
					array( __( 'Taxable category sales', 'wctc' ), $data['total_taxable'], __( 'Items with a tax category that paid tax', 'wctc' ), '#2271b1' ),
					array( __( 'Tax collected on category sales', 'wctc' ), $data['total_tax_collected'], __( 'Includes partial tax on excess-only items', 'wctc' ), '#50575e' ),
				);
				foreach ( $cards as $card ) : ?>
					<div style="flex:1 1 180px;padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-top:3px solid <?php echo esc_attr( $card[3] ); ?>">
						<div style="color:#50575e;font-size:12px;text-transform:uppercase;letter-spacing:.04em"><?php echo esc_html( $card[0] ); ?></div>
						<div style="font-size:26px;font-weight:600;margin-top:4px"><?php echo wp_kses_post( wc_price( (float) $card[1] ) ); ?></div>
						<div style="color:#50575e;font-size:12px;margin-top:4px"><?php echo esc_html( $card[2] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>
			<h2 style="margin-top:24px"><?php esc_html_e( 'By tax category', 'wctc' ); ?></h2>
			<table class="widefat striped" style="max-width:720px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tax category', 'wctc' ); ?></th>
						<th><?php esc_html_e( 'Tax-free sales', 'wctc' ); ?></th>
						<th><?php esc_html_e( 'Items', 'wctc' ); ?></th>
						<th><?php esc_html_e( 'Example rule applied', 'wctc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $data['by_category'] ) ) : ?>
						<tr><td colspan="4"><em><?php esc_html_e( 'No tax-free sales in this range yet. Place an order whose line is zero-rated by a rule to see it here.', 'wctc' ); ?></em></td></tr>
					<?php else : ?>
						<?php foreach ( $data['by_category'] as $slug => $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( Store::category_name( $slug ) ?: $slug ); ?></strong><br><code style="font-size:11px;color:#50575e"><?php echo esc_html( $slug ); ?></code></td>
								<td><?php echo wp_kses_post( wc_price( (float) $row['total'] ) ); ?></td>
								<td><?php echo (int) $row['items']; ?></td>
								<td style="color:#50575e;font-size:12px"><?php echo esc_html( $row['example_rule'] ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			<p style="color:#50575e;margin-top:14px;max-width:720px;font-size:13px">
				<?php esc_html_e( 'Includes orders in Completed, Processing or On hold statuses. Amounts are ex-tax line totals after discounts, read from each order line\'s _wctc_tax_category meta. For excess-only rules, only the exempt portion of each item\'s price is counted as tax-free.', 'wctc' ); ?>
			</p>
			<?php if ( $data['truncated'] ) : ?>
				<p style="color:#d63638"><?php echo esc_html( sprintf( __( 'Showing the first %d orders in this range. Narrow the dates for a complete count.', 'wctc' ), $data['limit'] ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Date shortcuts. */
	private static function shortcuts() {
		$today = gmdate( 'Y-m-d' );
		return array(
			array( 'label' => __( 'Last 7 days', 'wctc' ),  'range' => array( 'after' => gmdate( 'Y-m-d', strtotime( '-6 days' ) ),  'before' => $today ) ),
			array( 'label' => __( 'Last 30 days', 'wctc' ), 'range' => array( 'after' => gmdate( 'Y-m-d', strtotime( '-29 days' ) ), 'before' => $today ) ),
			array( 'label' => __( 'This month', 'wctc' ),   'range' => array( 'after' => gmdate( 'Y-m-01' ),                         'before' => $today ) ),
			array( 'label' => __( 'Year to date', 'wctc' ), 'range' => array( 'after' => gmdate( 'Y-01-01' ),                        'before' => $today ) ),
		);
	}

	/**
	 * Walk orders in the window, bucket each tagged line into tax-free or taxable.
	 *
	 * @param string $after  Y-m-d, inclusive.
	 * @param string $before Y-m-d, inclusive.
	 * @return array
	 */
	public static function aggregate( $after, $before ) {
		$limit  = 500;
		$result = array(
			'total_tax_free'      => 0.0,
			'total_taxable'       => 0.0,
			'total_tax_collected' => 0.0,
			'orders'              => 0,
			'by_category'         => array(),
			'truncated'           => false,
			'limit'               => $limit,
		);
		$after_ts  = strtotime( $after . ' 00:00:00' );
		$before_ts = strtotime( $before . ' 23:59:59' );
		if ( ! $after_ts || ! $before_ts || $after_ts > $before_ts ) {
			return $result;
		}
		$orders = wc_get_orders(
			array(
				'status'       => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
				'date_created' => $after_ts . '...' . $before_ts,
				'limit'        => $limit + 1,
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		if ( count( $orders ) > $limit ) {
			$result['truncated'] = true;
			$orders              = array_slice( $orders, 0, $limit );
		}

		$categories      = Store::categories();
		$name_to_slug    = array();
		foreach ( $categories as $slug => $info ) {
			$name_to_slug[ strtolower( $info['name'] ) ] = $slug;
		}

		$orders_with_tax_free = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $line ) {
				$note = (string) $line->get_meta( Shipping::META_CATEGORY );
				if ( '' === $note || 0 === strpos( $note, 'None' ) || 0 === strpos( $note, 'Conflict' ) ) {
					continue;
				}
				$product = $line->get_product();
				$slug    = $product ? (string) $product->get_meta( Store::META_PRODUCT, true, 'edit' ) : '';
				if ( '' === $slug ) {
					$name = strtolower( trim( explode( '(', $note )[0] ) );
					$slug = $name_to_slug[ $name ] ?? sanitize_title( $name );
				}
				$total      = (float) $line->get_total();
				$tax        = (float) $line->get_total_tax();
				$rule_note  = (string) $line->get_meta( Shipping::META_RULE );
				$order_id   = $order->get_id();
				$bucket     = &$result['by_category'][ $slug ];
				if ( ! isset( $bucket ) ) {
					$bucket = array( 'total' => 0.0, 'items' => 0, 'example_rule' => '' );
				}
				// Count any portion that paid no tax: full line when tax=0; excess-rule items also count
				// their exempt portion by comparing the computed tax to the expected full-rate tax.
				if ( $tax <= 0.0049 ) {
					// Fully tax-free.
					$result['total_tax_free'] += $total;
					$bucket['total']          += $total;
					$bucket['items']          += (int) $line->get_quantity();
					if ( '' === $bucket['example_rule'] && '' !== $rule_note ) {
						$bucket['example_rule'] = $rule_note;
					}
					$orders_with_tax_free[ $order_id ] = true;
				} else {
					// Partially taxed (excess-only rule): the item note includes "first $N per item exempt".
					if ( false !== stripos( $rule_note, 'first ' ) && false !== stripos( $rule_note, 'exempt' ) && preg_match( '/first[^0-9]*([0-9][0-9.,]*)/', $rule_note, $m ) ) {
						$qty    = max( 1, (float) $line->get_quantity() );
						$exempt = min( $total, (float) str_replace( ',', '', $m[1] ) * $qty );
						if ( $exempt > 0 ) {
							$result['total_tax_free'] += $exempt;
							$bucket['total']          += $exempt;
							$bucket['items']          += (int) $line->get_quantity();
							if ( '' === $bucket['example_rule'] && '' !== $rule_note ) {
								$bucket['example_rule'] = $rule_note;
							}
							$orders_with_tax_free[ $order_id ] = true;
						}
						$result['total_taxable']       += max( 0.0, $total - $exempt );
						$result['total_tax_collected'] += $tax;
					} else {
						$result['total_taxable']       += $total;
						$result['total_tax_collected'] += $tax;
					}
				}
				unset( $bucket );
			}
		}
		$result['orders'] = count( $orders_with_tax_free );
		uasort( $result['by_category'], fn( $a, $b ) => $b['total'] <=> $a['total'] );
		return $result;
	}
}
