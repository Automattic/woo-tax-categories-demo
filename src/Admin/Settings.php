<?php
/**
 * Settings → Tax: two opt-in settings, and a new "Tax categories" section with three tables.
 *
 * @package WCTC
 */

namespace WCTC\Admin;

use WCTC\Engine;
use WCTC\Examples;
use WCTC\Store;
use WCTC\Admin\TaxFreeReport;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings UI.
 */
class Settings {

	const SECTION = 'tax_categories';

	/** Hook in. */
	public static function init() {
		add_filter( 'woocommerce_tax_settings', array( __CLASS__, 'tax_options' ) );
		add_filter( 'woocommerce_get_sections_tax', array( __CLASS__, 'sections' ) );
		add_filter( 'woocommerce_get_settings_tax', array( __CLASS__, 'section_settings' ), 10, 2 );
		add_action( 'woocommerce_admin_field_wctc_tables', array( __CLASS__, 'render_tables' ) );
		add_action( 'woocommerce_settings_save_tax', array( __CLASS__, 'save_tables' ) );
		add_action( 'admin_post_wctc_examples', array( __CLASS__, 'handle_examples' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'wp_ajax_wctc_rate_there', array( __CLASS__, 'ajax_rate_there' ) );
	}

	/**
	 * Add the two opt-in settings to Tax options, after "Calculate tax based on".
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function tax_options( $settings ) {
		$ours = array(
			array(
				'title'   => __( 'Tax categories', 'wctc' ),
				'desc'    => __( 'Use tax categories to choose the tax class for each product in each place', 'wctc' ),
				'desc_tip' => false,
				'id'      => Store::OPT_ENABLED,
				'default' => 'no',
				'type'    => 'checkbox',
			),
			array(
				'title'   => __( 'Shipping tax split by tax class', 'wctc' ),
				'desc'    => __( 'When an order has items in different tax classes, tax each class\'s share of shipping (by item value) at that class\'s rate, instead of taxing all shipping at one class. For stores not using tax categories; with tax categories on, this already happens.', 'wctc' ),
				'id'      => Store::OPT_SHIPPING_SPLIT,
				'default' => 'no',
				'type'    => 'checkbox',
			),
		);
		$out = array();
		foreach ( $settings as $setting ) {
			$out[] = $setting;
			if ( isset( $setting['id'] ) && 'woocommerce_tax_based_on' === $setting['id'] ) {
				$out = array_merge( $out, $ours );
				$ours = array();
			}
		}
		if ( $ours ) {
			// Fallback: insert before the section end.
			array_splice( $out, max( 0, count( $out ) - 1 ), 0, $ours );
		}
		return $out;
	}

	/**
	 * Add the "Tax categories" section link.
	 *
	 * @param array $sections Sections.
	 * @return array
	 */
	public static function sections( $sections ) {
		$sections[ self::SECTION ] = __( 'Tax categories', 'wctc' );
		return $sections;
	}

	/**
	 * Settings for our section: one custom field that renders the tables.
	 *
	 * @param array  $settings Settings.
	 * @param string $section  Current section.
	 * @return array
	 */
	public static function section_settings( $settings, $section = '' ) {
		if ( self::SECTION !== $section ) {
			return $settings;
		}
		return array( array( 'type' => 'wctc_tables', 'id' => 'wctc_tables' ) );
	}

	/** Country picker: "Any country" plus the store's country list, with the saved code selected. */
	private static function country_select( $name, $selected ) {
		$options = array( '*' => __( 'Any country', 'wctc' ) ) + WC()->countries->get_countries();
		if ( '' === (string) $selected ) {
			$options = array( '' => __( 'Pick a country', 'wctc' ) ) + $options;
		}
		return self::select( $name, $options, $selected );
	}

	/** State field: a text box with a datalist of that country's states, so codes and names both work. */
	private static function state_input( $name, $value ) {
		return '<input type="text" class="small wctc-state" list="wctc-states" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" placeholder="*" autocomplete="off">';
	}

	/** All states by country, for the datalist. */
	private static function states_json() {
		$out = array();
		foreach ( WC()->countries->get_states() as $country => $states ) {
			if ( is_array( $states ) && $states ) {
				$out[ $country ] = array_map( 'wp_strip_all_tags', $states );
			}
		}
		return wp_json_encode( $out );
	}

	/** Small helper for a select. */
	private static function select( $name, $options, $selected ) {
		$html = '<select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Render the three tables. */
	public static function render_tables() {
		$categories = Store::categories();
		$rules      = Store::rules();
		$shipping   = Store::shipping_rules();
		$classes    = Store::tax_classes();
		$cat_opts   = array( '' => '—' );
		foreach ( $categories as $slug => $cat ) {
			$cat_opts[ $slug ] = $cat['name'];
		}
		$counts = self::assignment_counts();
		?>
		<style>
			.wctc table.widefat td, .wctc table.widefat th { vertical-align: middle; }
			/* 10 columns of multi-word headers; keep each header on one line, let rows wrap. */
			.wctc table.widefat thead th { white-space: nowrap; }
			.wctc input.small { width: 90px; } .wctc input.mid { width: 140px; }
			.wctc h2 { margin-top: 28px; } .wctc .desc { color: #50575e; margin: 4px 0 10px; max-width: 900px; }
			.wctc .wctc-muted { color: #8c8f94; } .wctc .wctc-rate-there.is-loading { opacity: .45; }
			.wctc input.wctc-bad { border-color: #d63638; box-shadow: 0 0 0 1px #d63638; } .wctc tr.wctc-dup td { background: #fcf0f1; }
			.wctc select[name$="[country]"] { max-width: 170px; }
			.wctc .status { padding: 8px 12px; background: #fff; border-left: 4px solid <?php echo Store::enabled() ? '#00a32a' : '#dba617'; ?>; margin: 12px 0; max-width: 900px; }
		</style>
		<div class="wctc">
			<div class="status">
				<?php
				echo Store::enabled()
					? esc_html__( 'Tax categories are on. Rules below change tax at checkout.', 'wctc' )
					: wp_kses_post( __( 'Tax categories are <strong>off</strong>, so nothing below affects checkout yet. Turn them on under Tax options.', 'wctc' ) );
				?>
				<?php if ( Store::enabled() ) : ?>
					<br><?php esc_html_e( 'Shipping follows the goods everywhere by default (split by value). Add shipping rules only for places that treat shipping differently.', 'wctc' ); ?>
				<?php endif; ?>
				<?php $broken = self::rules_without_rates( $rules ); ?>
				<?php if ( $broken ) : ?>
					<br><strong style="color:#d63638"><?php echo esc_html( sprintf( _n( '%d category rule points at a tax class with no rates in its place, so it is skipped. See "Rate there" below.', '%d category rules point at tax classes with no rates in their place, so they are skipped. See "Rate there" below.', count( $broken ), 'wctc' ), count( $broken ) ) ); ?></strong>
				<?php endif; ?>
			</div>
			<p id="wctc-examples">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wctc_examples&do=load' ), 'wctc_examples' ) ); ?>"><?php esc_html_e( 'Load example data', 'wctc' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wctc_examples&do=rates' ), 'wctc_examples' ) ); ?>" title="<?php esc_attr_e( 'Writes rows for the places the loaded example rules cover (US states or EU/UK/AU, depending on which set you loaded) into the Standard and Reduced rate tables. Named "WCTC example …" so Remove finds them.', 'wctc' ); ?>"><?php esc_html_e( 'Add example tax rates', 'wctc' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wctc_examples&do=clear' ), 'wctc_examples' ) ); ?>"><?php esc_html_e( 'Remove example data and rates', 'wctc' ); ?></a>
				<a class="button" href="<?php echo esc_url( TaxFreeReport::url() ); ?>"><?php esc_html_e( 'View tax-free sales report →', 'wctc' ); ?></a>
			</p>

			<h2 id="wctc-h-categories"><?php esc_html_e( 'Tax categories', 'wctc' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'What a product is for tax purposes. Assign categories to product categories, products or variations. A category never holds a rate; the rules below pick one of your existing tax classes.', 'wctc' ); ?></p>
			<table class="widefat striped" id="wctc-t-categories">
				<thead><tr><th><?php esc_html_e( 'Name', 'wctc' ); ?></th><th><?php esc_html_e( 'Slug', 'wctc' ); ?></th><th><?php esc_html_e( 'Provider code', 'wctc' ); ?></th><th><?php esc_html_e( 'Assigned to', 'wctc' ); ?></th><th><?php esc_html_e( 'Rules', 'wctc' ); ?></th><th><?php esc_html_e( 'Remove', 'wctc' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $categories as $slug => $cat ) : ?>
					<tr>
						<td><input type="text" class="mid" name="wctc_cat[<?php echo esc_attr( $slug ); ?>][name]" value="<?php echo esc_attr( $cat['name'] ); ?>"></td>
						<td><code><?php echo esc_html( $slug ); ?></code></td>
						<td><input type="text" class="mid" name="wctc_cat[<?php echo esc_attr( $slug ); ?>][provider_code]" value="<?php echo esc_attr( $cat['provider_code'] ?? '' ); ?>" placeholder="txcd_…"></td>
						<td><?php echo esc_html( $counts[ $slug ] ?? '—' ); ?></td>
						<td><?php echo (int) count( array_filter( $rules, fn( $r ) => $r['category'] === $slug ) ); ?></td>
						<td><input type="checkbox" class="wctc-remove-cat" data-name="<?php echo esc_attr( $cat['name'] ); ?>" data-rules="<?php echo (int) count( array_filter( $rules, fn( $r ) => $r['category'] === $slug ) ); ?>" name="wctc_cat[<?php echo esc_attr( $slug ); ?>][remove]" value="1"> <span class="wctc-remove-note" style="color:#d63638"></span></td>
					</tr>
				<?php endforeach; ?>
					<tr>
						<td><input type="text" class="mid" name="wctc_cat_new[name]" placeholder="<?php esc_attr_e( 'New category', 'wctc' ); ?>"></td>
						<td><em><?php esc_html_e( 'auto', 'wctc' ); ?></em></td>
						<td><input type="text" class="mid" name="wctc_cat_new[provider_code]" placeholder="txcd_…"></td>
						<td colspan="3"></td>
					</tr>
				</tbody>
			</table>

			<h2 id="wctc-h-rules"><?php esc_html_e( 'Category rules', 'wctc' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'Each rule sends a tax category to one of your tax classes in a place. Rates still come from your tax rate tables. Products with no category, or shipped somewhere with no rule, keep their own tax class. The most specific matching rule wins. Leave price and dates empty to always apply.', 'wctc' ); ?></p>
			<table class="widefat striped" id="wctc-t-rules">
				<thead><tr><th><?php esc_html_e( 'Tax category', 'wctc' ); ?></th><th><?php esc_html_e( 'Country', 'wctc' ); ?></th><th><?php esc_html_e( 'State', 'wctc' ); ?></th><th><?php esc_html_e( 'Postcode / ZIP', 'wctc' ); ?></th><th><?php echo esc_html( sprintf( __( 'Price per item (%s)', 'wctc' ), get_woocommerce_currency() ) ); ?> <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'A per-item price limit. Enter an amount and a follow-up question appears below so you can say how the limit works: "only applies under the limit" (NY: Clothing under $110 → Zero), or "exempt the first N of every item" (MA: Clothing, first $175 exempt). Leave blank to apply at any price.', 'wctc' ); ?>"></span></th><th><?php esc_html_e( 'Uses tax class', 'wctc' ); ?></th><th><?php esc_html_e( 'Rate there', 'wctc' ); ?></th><th><?php esc_html_e( 'From', 'wctc' ); ?></th><th><?php esc_html_e( 'Until', 'wctc' ); ?></th><th><?php esc_html_e( 'Remove', 'wctc' ); ?></th></tr></thead>
				<tbody>
				<?php $dups = Engine::duplicate_category_rules( $rules ); ?>
				<?php $symbol = get_woocommerce_currency_symbol(); ?>
				<?php foreach ( array_merge( $rules, array( null ) ) as $i => $rule ) : ?>
					<?php $rule = $rule ?? array( 'category' => '', 'country' => '', 'state' => '*', 'postcode' => '*', 'max_price' => '', 'tax_class' => 'zero-rate', 'limit_mode' => Engine::LIMIT_CLIFF, 'start' => '', 'end' => '' ); ?>
					<?php $mode  = ( $rule['limit_mode'] ?? Engine::LIMIT_CLIFF ) === Engine::LIMIT_EXCESS ? Engine::LIMIT_EXCESS : Engine::LIMIT_CLIFF; ?>
					<?php $price = (string) $rule['max_price']; ?>
					<?php $has_price = '' !== trim( $price ); ?>
					<?php $price_display = $has_price ? ( $symbol . $price ) : esc_html__( 'the limit', 'wctc' ); ?>
					<tr<?php echo isset( $dups[ $i ] ) ? ' class="wctc-dup"' : ''; ?>>
						<td><?php echo self::select( "wctc_rule[$i][category]", $cat_opts, $rule['category'] ); // phpcs:ignore ?></td>
						<td><?php echo self::country_select( "wctc_rule[$i][country]", $rule['country'] ); // phpcs:ignore ?></td>
						<td><?php echo self::state_input( "wctc_rule[$i][state]", $rule['state'] ); // phpcs:ignore ?></td>
						<td><input type="text" class="small" name="wctc_rule[<?php echo (int) $i; ?>][postcode]" value="<?php echo esc_attr( $rule['postcode'] ); ?>" placeholder="*"></td>
						<td class="wctc-price-cell">
							<span class="wctc-muted"><?php echo esc_html( $symbol ); ?></span>
							<input type="text" class="small wctc-price" name="wctc_rule[<?php echo (int) $i; ?>][max_price]" value="<?php echo esc_attr( $price ); ?>" placeholder="<?php esc_attr_e( 'any', 'wctc' ); ?>" inputmode="decimal">
							<?php // Progressive disclosure: only ask HOW the limit works once there is a limit. The two radios save to the same limit_mode field. ?>
							<div class="wctc-limit-mode"<?php echo $has_price ? '' : ' style="display:none"'; ?>>
								<p class="wctc-cond-q"><?php esc_html_e( 'How does this limit work?', 'wctc' ); ?></p>
								<label><input type="radio" name="wctc_rule[<?php echo (int) $i; ?>][limit_mode]" value="<?php echo esc_attr( Engine::LIMIT_CLIFF ); ?>"<?php echo Engine::LIMIT_CLIFF === $mode ? ' checked' : ''; ?>> <span class="wctc-limit-text" data-template="<?php esc_attr_e( 'The rule only applies to items under %s (otherwise skip to the next matching rule). Example: NY Clothing under $110 → Zero rate.', 'wctc' ); ?>"><?php echo esc_html( sprintf( __( 'The rule only applies to items under %s (otherwise skip to the next matching rule). Example: NY Clothing under $110 → Zero rate.', 'wctc' ), $price_display ) ); ?></span></label>
								<label><input type="radio" name="wctc_rule[<?php echo (int) $i; ?>][limit_mode]" value="<?php echo esc_attr( Engine::LIMIT_EXCESS ); ?>"<?php echo Engine::LIMIT_EXCESS === $mode ? ' checked' : ''; ?>> <span class="wctc-limit-text" data-template="<?php esc_attr_e( 'Exempt the first %s of every item; tax the rest at the rule\'s class. Example: MA Clothing, first $175 exempt.', 'wctc' ); ?>"><?php echo esc_html( sprintf( __( 'Exempt the first %s of every item; tax the rest at the rule\'s class. Example: MA Clothing, first $175 exempt.', 'wctc' ), $price_display ) ); ?></span></label>
							</div>
							<?php // When there is no price, carry the mode via a hidden input so a non-limited row doesn't lose it on save. ?>
							<?php if ( ! $has_price ) : ?>
								<input type="hidden" class="wctc-limit-fallback" name="wctc_rule[<?php echo (int) $i; ?>][limit_mode]" value="<?php echo esc_attr( $mode ); ?>">
							<?php endif; ?>
						</td>
						<td><?php echo self::select( "wctc_rule[$i][tax_class]", $classes, $rule['tax_class'] ); // phpcs:ignore ?></td>
						<td class="wctc-rate-there" aria-live="polite"><?php echo ( $i < count( $rules ) || '' !== trim( (string) $rule['country'] ) ) ? wp_kses_post( self::rate_there( $rule ) ) : '<span class="wctc-muted">' . esc_html__( 'Pick a place', 'wctc' ) . '</span>'; ?><?php if ( isset( $dups[ $i ] ) ) : ?><br><span class="wctc-dup-note" style="color:#d63638"><?php echo esc_html( sprintf( __( 'Same as row %d, which wins. Remove one.', 'wctc' ), $dups[ $i ] + 1 ) ); ?></span><?php endif; ?></td>
						<td><input type="date" name="wctc_rule[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr( $rule['start'] ); ?>"></td>
						<td><input type="date" name="wctc_rule[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr( $rule['end'] ); ?>"></td>
						<td><?php if ( $i < count( $rules ) ) : ?><input type="checkbox" name="wctc_rule[<?php echo (int) $i; ?>][remove]" value="1"><?php else : ?><em><?php esc_html_e( 'new', 'wctc' ); ?></em><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<style>
				.wctc .wctc-price-cell .wctc-limit-mode { margin-top: 8px; padding: 8px 10px; background: #f6f7f7; border-left: 3px solid #7a4fc9; border-radius: 4px; font-size: 12.5px; color: #1d2327; max-width: 420px; }
				.wctc .wctc-price-cell .wctc-cond-q { margin: 0 0 6px; font-weight: 600; }
				.wctc .wctc-price-cell .wctc-limit-mode label { display: block; margin: 4px 0; cursor: pointer; }
				.wctc .wctc-price-cell .wctc-limit-mode label input { margin-right: 6px; vertical-align: top; margin-top: 3px; }
			</style>
			<script>
			( function ( $ ) {
				// Progressive disclosure for the per-rule price limit. The two radios ("only under the limit"
				// vs "first N exempt") are kept out of sight until the merchant types a price. The amount in
				// the label updates live as they type, so the question reads with the actual number.
				var SYMBOL = <?php echo wp_json_encode( get_woocommerce_currency_symbol() ); ?>;
				var ANY    = <?php echo wp_json_encode( __( 'the limit', 'wctc' ) ); ?>;
				$( '#wctc-t-rules' ).on( 'input', '.wctc-price', function () {
					var $cell  = $( this ).closest( 'td.wctc-price-cell' );
					var value  = $( this ).val().trim();
					var $block = $cell.find( '.wctc-limit-mode' );
					if ( value ) {
						$block.show();
						$cell.find( '.wctc-limit-fallback' ).remove();
						// Update the amount in the labels (template has one %s placeholder).
						var display = SYMBOL + value;
						$block.find( '.wctc-limit-text' ).each( function () {
							$( this ).text( $( this ).data( 'template' ).replace( '%s', display ) );
						} );
					} else {
						$block.hide();
						// Carry the chosen mode in a hidden input so save still gets a value.
						if ( ! $cell.find( '.wctc-limit-fallback' ).length ) {
							var chosen   = $cell.find( '.wctc-limit-mode input[type="radio"]:checked' ).val() || '<?php echo esc_js( Engine::LIMIT_CLIFF ); ?>';
							var nameAttr = $cell.find( '.wctc-limit-mode input[type="radio"]' ).first().attr( 'name' );
							if ( nameAttr ) {
								$cell.append( '<input type="hidden" class="wctc-limit-fallback" name="' + nameAttr + '" value="' + chosen + '">' );
							}
						}
					}
				} );
			}( jQuery ) );
			</script>
			<script>
			( function ( $ ) {
				var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wctc_rate_there' ) ); ?>;
				var timers = {};
				function refresh( $row ) {
					var name = $row.find( 'select[name$="[tax_class]"]' ).attr( 'name' ) || '';
					var key = name.replace( /\[tax_class\]$/, '' );
					var val = function ( field ) { return $row.find( '[name="' + key + '[' + field + ']"]' ).val() || ''; };
					var $cell = $row.find( '.wctc-rate-there' );
					if ( ! val( 'country' ).trim() ) {
						$cell.html( '<span class="wctc-muted">Pick a place</span>' );
						return;
					}
					$cell.addClass( 'is-loading' );
					$.post( ajaxurl, {
						action: 'wctc_rate_there',
						_wpnonce: nonce,
						tax_class: val( 'tax_class' ),
						country: val( 'country' ),
						state: val( 'state' ),
						postcode: val( 'postcode' )
					} ).done( function ( res ) {
						if ( res && res.success ) {
							$cell.html( res.data.html );
						}
					} ).always( function () {
						$cell.removeClass( 'is-loading' );
					} );
				}
				var states = <?php echo self::states_json(); // phpcs:ignore ?>;
				function fillStates( $row ) {
					var country = $row.find( '[name$="[country]"]' ).val() || '';
					var $list = $( '#wctc-states' ).empty();
					$.each( states[ country ] || {}, function ( code, name ) {
						$list.append( $( '<option>' ).attr( 'value', code ).text( name ) );
					} );
				}
				$( 'body' ).append( '<datalist id="wctc-states"></datalist>' );
				$( '.wctc' ).on( 'focus', '.wctc-state', function () { fillStates( $( this ).closest( 'tr' ) ); } );
				$( '.wctc' ).on( 'change', '.wctc-remove-cat', function () {
					var rules = parseInt( $( this ).data( 'rules' ), 10 ) || 0;
					$( this ).siblings( '.wctc-remove-note' ).text( this.checked && rules ? ( rules === 1 ? 'Also removes its 1 rule' : 'Also removes its ' + rules + ' rules' ) : '' );
				} );
				$( '.wctc' ).closest( 'form' ).on( 'submit', function () {
					var msgs = [];
					$( '.wctc-remove-cat:checked' ).each( function () {
						var rules = parseInt( $( this ).data( 'rules' ), 10 ) || 0;
						if ( rules ) { msgs.push( '"' + $( this ).data( 'name' ) + '" and its ' + rules + ' rule' + ( rules === 1 ? '' : 's' ) ); }
					} );
					if ( msgs.length && ! window.confirm( 'Remove ' + msgs.join( ', ' ) + '? Products using them go back to their own tax class.' ) ) {
						return false;
					}
				} );
				$( '#wctc-t-rules' ).on( 'input', '.wctc-price', function () {
					var v = $( this ).val().trim();
					$( this ).toggleClass( 'wctc-bad', '' !== v && ! /^\d*([.,]\d*)?$/.test( v ) );
				} );
				$( '#wctc-t-rules' ).on( 'change input', 'select[name$="[tax_class]"], select[name$="[country]"], input[name$="[state]"], input[name$="[postcode]"]', function ( e ) {
					var $row = $( this ).closest( 'tr' );
					var id = $row.index();
					window.clearTimeout( timers[ id ] );
					timers[ id ] = window.setTimeout( function () { refresh( $row ); }, 'change' === e.type ? 0 : 400 );
				} );
			}( jQuery ) );
			</script>

			<h2 id="wctc-h-shipping"><?php esc_html_e( 'Shipping rules', 'wctc' ); ?></h2>
			<p class="desc"><?php esc_html_e( 'Exceptions to "shipping follows the goods". With tax categories on, every place without a rule here taxes shipping like the items in the box, split by value. Add a rule for places that exempt shipping, always tax it, or treat a mixed basket differently. The most specific place wins (postcode over state over country). For places whose answer depends on how you ship (California, South Carolina, Illinois), a follow-up question appears under the choice.', 'wctc' ); ?></p>
			<table class="widefat striped" id="wctc-t-shipping">
				<thead><tr><th><?php esc_html_e( 'Country', 'wctc' ); ?></th><th><?php esc_html_e( 'State', 'wctc' ); ?></th><th><?php esc_html_e( 'Postcode / ZIP', 'wctc' ); ?></th><th><?php esc_html_e( 'Shipping is taxed', 'wctc' ); ?></th><th><?php esc_html_e( 'Mixed basket', 'wctc' ); ?> <span class="woocommerce-help-tip" data-tip="<?php esc_attr_e( 'When the box holds goods taxed at different rates. Split by value (most places) or by weight (Minnesota) apportions the charge. Belgium lets you tax the whole charge at the lowest rate in the box. Illinois taxes it at the rate of whichever group holds most of the value, and apportions when none does.', 'wctc' ); ?>"></span></th><th><?php esc_html_e( 'Remove', 'wctc' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_merge( $shipping, array( null ) ) as $i => $rule ) : ?>
					<?php $rule = $rule ?? array( 'country' => '', 'state' => '*', 'postcode' => '*', 'mode' => Engine::MODE_FOLLOWS, 'conditions_met' => 0, 'split' => 'value' ); ?>
					<?php $met = ! empty( $rule['conditions_met'] ); ?>
					<tr>
						<td><?php echo self::country_select( "wctc_ship[$i][country]", $rule['country'] ); // phpcs:ignore ?></td>
						<td><?php echo self::state_input( "wctc_ship[$i][state]", $rule['state'] ); // phpcs:ignore ?></td>
						<td><input type="text" class="small" name="wctc_ship[<?php echo (int) $i; ?>][postcode]" value="<?php echo esc_attr( $rule['postcode'] ?? '*' ); ?>" placeholder="*"></td>
						<td class="wctc-ship-mode">
							<?php echo self::select( "wctc_ship[$i][mode]", Engine::modes(), $rule['mode'] ); // phpcs:ignore ?>
							<?php // Conditional modes get an inline follow-up. The question text matches the mode; both answers save to the same conditions_met flag. ?>
							<div class="wctc-cond" data-for="<?php echo esc_attr( Engine::MODE_CONDITIONAL ); ?>"<?php echo Engine::MODE_CONDITIONAL === $rule['mode'] ? '' : ' style="display:none"'; ?>>
								<p class="wctc-cond-q"><?php esc_html_e( 'Does your store meet the state\'s exemption test?', 'wctc' ); ?></p>
								<label><input type="radio" name="wctc_ship[<?php echo (int) $i; ?>][conditions_met]" value="1"<?php echo ( Engine::MODE_CONDITIONAL === $rule['mode'] && $met ) ? ' checked' : ''; ?>> <?php esc_html_e( 'Yes — I ship via common carrier or USPS and charge no more than actual cost (shipping is exempt)', 'wctc' ); ?></label>
								<label><input type="radio" name="wctc_ship[<?php echo (int) $i; ?>][conditions_met]" value="0"<?php echo ( Engine::MODE_CONDITIONAL === $rule['mode'] && ! $met ) ? ' checked' : ''; ?>> <?php esc_html_e( 'No — I use my own vehicle or add a markup (shipping follows the goods)', 'wctc' ); ?></label>
							</div>
							<div class="wctc-cond" data-for="<?php echo esc_attr( Engine::MODE_DELIVERY_TERMS ); ?>"<?php echo Engine::MODE_DELIVERY_TERMS === $rule['mode'] ? '' : ' style="display:none"'; ?>>
								<p class="wctc-cond-q"><?php esc_html_e( 'Can the customer separate shipping from the sale?', 'wctc' ); ?></p>
								<label><input type="radio" name="wctc_ship[<?php echo (int) $i; ?>][conditions_met]" value="1"<?php echo ( Engine::MODE_DELIVERY_TERMS === $rule['mode'] && $met ) ? ' checked' : ''; ?>> <?php esc_html_e( 'Yes — customer could pick up or arrange their own carrier (shipping is exempt)', 'wctc' ); ?></label>
								<label><input type="radio" name="wctc_ship[<?php echo (int) $i; ?>][conditions_met]" value="0"<?php echo ( Engine::MODE_DELIVERY_TERMS === $rule['mode'] && ! $met ) ? ' checked' : ''; ?>> <?php esc_html_e( 'No — delivery is mandatory (shipping follows the goods)', 'wctc' ); ?></label>
							</div>
							<?php // Carry the current value for modes that don't ask, so saving a non-conditional row doesn't lose it. ?>
							<?php if ( ! in_array( $rule['mode'], array( Engine::MODE_CONDITIONAL, Engine::MODE_DELIVERY_TERMS ), true ) ) : ?>
								<input type="hidden" class="wctc-cond-fallback" name="wctc_ship[<?php echo (int) $i; ?>][conditions_met]" value="<?php echo $met ? '1' : '0'; ?>">
							<?php endif; ?>
						</td>
						<td><?php echo self::select( "wctc_ship[$i][split]", Engine::splits(), $rule['split'] ); // phpcs:ignore ?></td>
						<td><?php if ( $i < count( $shipping ) ) : ?><input type="checkbox" name="wctc_ship[<?php echo (int) $i; ?>][remove]" value="1"><?php else : ?><em><?php esc_html_e( 'new', 'wctc' ); ?></em><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<style>
				.wctc .wctc-ship-mode .wctc-cond { margin-top: 8px; padding: 8px 10px; background: #f6f7f7; border-left: 3px solid #7a4fc9; border-radius: 4px; font-size: 12.5px; color: #1d2327; }
				.wctc .wctc-ship-mode .wctc-cond-q { margin: 0 0 6px; font-weight: 600; }
				.wctc .wctc-ship-mode .wctc-cond label { display: block; margin: 4px 0; cursor: pointer; }
				.wctc .wctc-ship-mode .wctc-cond label input { margin-right: 6px; }
			</style>
			<script>
			( function ( $ ) {
				// Progressive disclosure: when the mode changes, show the matching follow-up question and
				// hide the others. If the merchant switches to a non-conditional mode we drop a hidden input
				// to preserve the current conditions_met value, so saving doesn't accidentally reset it.
				$( '#wctc-t-shipping' ).on( 'change', 'select[name$="[mode]"]', function () {
					var $cell = $( this ).closest( 'td' );
					var mode  = $( this ).val();
					var conditional = mode === '<?php echo esc_js( Engine::MODE_CONDITIONAL ); ?>' || mode === '<?php echo esc_js( Engine::MODE_DELIVERY_TERMS ); ?>';
					$cell.find( '.wctc-cond' ).each( function () {
						$( this ).toggle( $( this ).data( 'for' ) === mode );
					} );
					// Clean out any stale fallback input first.
					$cell.find( '.wctc-cond-fallback' ).remove();
					if ( ! conditional ) {
						var fieldName = $( this ).attr( 'name' ).replace( '[mode]', '[conditions_met]' );
						// Preserve the previous value (keeps user data if they toggle back later).
						var prev = $cell.find( '.wctc-cond input[type="radio"]:checked' ).val() || '0';
						$cell.append( '<input type="hidden" class="wctc-cond-fallback" name="' + fieldName + '" value="' + prev + '">' );
					}
				} );
			}( jQuery ) );
			</script>
			<p class="desc"><?php esc_html_e( 'Fill in the last row of a table to add an entry, tick Remove to delete one, then Save changes.', 'wctc' ); ?></p>
		</div>
		<?php
	}

	/** Return the "Rate there" cell for a rule row as the merchant edits it. */
	public static function ajax_rate_there() {
		check_ajax_referer( 'wctc_rate_there' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rule = array(
			'country'   => self::place( $_POST['country'] ?? '', '' ),
			'state'     => self::place( $_POST['state'] ?? '' ),
			'postcode'  => self::place( $_POST['postcode'] ?? '' ),
			'tax_class' => sanitize_title( wp_unslash( $_POST['tax_class'] ?? '' ) ),
		);
		// phpcs:enable
		wp_send_json_success( array( 'html' => wp_kses_post( self::rate_there( $rule ) ) ) );
	}

	/**
	 * The combined rate a rule's tax class gives in its place, or a warning when there are no rates.
	 *
	 * @param array $rule Rule row.
	 * @return string HTML.
	 */
	private static function rate_there( $rule ) {
		$location = self::rule_location( $rule );
		if ( 'zero-rate' === $rule['tax_class'] ) {
			return '0%';
		}
		if ( '' === $location['state'] && 'US' === $location['country'] ) {
			return esc_html__( 'Varies by state', 'wctc' );
		}
		$rates = \WC_Tax::find_rates( $location + array( 'tax_class' => $rule['tax_class'] ) );
		if ( ! $rates ) {
			return '<span style="color:#d63638">' . esc_html__( 'No rates here: rule skipped', 'wctc' ) . '</span>';
		}
		$pct  = rtrim( rtrim( wc_format_decimal( array_sum( array_column( $rates, 'rate' ) ), 4 ), '0' ), '.' ) . '%';
		$more = '';
		if ( '' === $location['postcode'] ) {
			// Rate rows limited to cities or ZIPs in this place add to the base rate there (e.g. NYC on top of NY State).
			global $wpdb;
			$local = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT r.tax_rate_id) FROM {$wpdb->prefix}woocommerce_tax_rates r INNER JOIN {$wpdb->prefix}woocommerce_tax_rate_locations l ON l.tax_rate_id = r.tax_rate_id WHERE r.tax_rate_country = %s AND r.tax_rate_state = %s AND r.tax_rate_class = %s",
					$location['country'],
					$location['state'],
					$rule['tax_class']
				)
			);
			if ( $local ) {
				$more = ' <span class="wctc-muted">' . esc_html__( '+ local rates in some cities or ZIPs', 'wctc' ) . '</span>';
			}
		}
		return esc_html( $pct ) . $more;
	}

	/** A representative location for a rule row (wildcards become blank). */
	private static function rule_location( $rule ) {
		$clean = fn( $v ) => in_array( trim( (string) $v ), array( '', '*' ), true ) ? '' : explode( ';', str_replace( '*', '', (string) $v ) )[0];
		return array(
			'country'  => $clean( $rule['country'] ?? '' ),
			'state'    => $clean( $rule['state'] ?? '' ),
			'postcode' => $clean( $rule['postcode'] ?? '' ),
			'city'     => '',
		);
	}

	/** Rules whose tax class has no rates where they apply. */
	private static function rules_without_rates( $rules ) {
		return array_filter(
			$rules,
			fn( $rule ) => 'zero-rate' !== $rule['tax_class'] && ! ( '' === self::rule_location( $rule )['state'] && 'US' === self::rule_location( $rule )['country'] ) && ! \WC_Tax::find_rates( self::rule_location( $rule ) + array( 'tax_class' => $rule['tax_class'] ) )
		);
	}

	/**
	 * Which product categories and products use each tax category.
	 *
	 * @return array<string,string>
	 */
	private static function assignment_counts() {
		$out   = array();
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'meta_key'   => Store::META_TERM, // phpcs:ignore
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$slug = get_term_meta( $term->term_id, Store::META_TERM, true );
				if ( $slug ) {
					$out[ $slug ][] = 'Product category: ' . $term->name;
				}
			}
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, COUNT(*) c FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value", Store::META_PRODUCT ) ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$out[ $row->meta_value ][] = sprintf( _n( '%d product', '%d products', (int) $row->c, 'wctc' ), (int) $row->c );
		}
		return array_map( fn( $parts ) => implode( '; ', $parts ), $out );
	}

	/**
	 * Clean a place code. A typed country or state name ("United States", "New York") becomes its code.
	 *
	 * @param string $value   Typed value.
	 * @param string $default Value for blank.
	 * @param string $country '' = this is a country; a country code = this is a state of it; '-' = a postcode.
	 */
	private static function place( $value, $default = '*', $country = '' ) {
		$raw   = trim( sanitize_text_field( wp_unslash( $value ) ) );
		$value = strtoupper( $raw );
		if ( '' === $value ) {
			return $default;
		}
		if ( in_array( $value, array( '*', 'ANY' ), true ) || '-' === $country ) {
			return '-' === $country ? $value : '*';
		}
		$lists = '' === $country ? WC()->countries->get_countries() : ( WC()->countries->get_states( $country ) ?: array() );
		if ( isset( $lists[ $value ] ) ) {
			return $value;
		}
		foreach ( $lists as $code => $name ) {
			$name = strtoupper( wp_strip_all_tags( html_entity_decode( $name ) ) );
			if ( $name === $value || preg_replace( '/\s*\(.*\)$/', '', $name ) === $value ) {
				return (string) $code;
			}
		}
		if ( '' === $country && in_array( $value, array( 'USA', 'UNITED STATES', 'U.S.', 'U.S.A.' ), true ) ) {
			return 'US';
		}
		if ( '' === $country && in_array( $value, array( 'UK', 'GREAT BRITAIN', 'ENGLAND' ), true ) ) {
			return 'GB';
		}
		return $value;
	}

	/** Record a row that was not saved, so the merchant sees why. */
	private static function reject( $message ) {
		\WC_Admin_Settings::add_error( $message );
	}

	/** Save the three tables. */
	public static function save_tables() {
		global $current_section;
		if ( self::SECTION !== $current_section ) {
			return;
		}
		// Nonce is checked by WC_Admin_Settings::save() before this action fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing

		$categories = Store::categories();
		foreach ( (array) ( $_POST['wctc_cat'] ?? array() ) as $slug => $row ) {
			$slug = sanitize_title( $slug );
			if ( ! isset( $categories[ $slug ] ) ) {
				continue;
			}
			if ( ! empty( $row['remove'] ) ) {
				unset( $categories[ $slug ] );
				continue;
			}
			$categories[ $slug ] = array(
				'name'          => sanitize_text_field( wp_unslash( $row['name'] ?? $categories[ $slug ]['name'] ) ),
				'provider_code' => sanitize_text_field( wp_unslash( $row['provider_code'] ?? '' ) ),
			);
		}
		$new = (array) ( $_POST['wctc_cat_new'] ?? array() );
		$name = sanitize_text_field( wp_unslash( $new['name'] ?? '' ) );
		if ( '' !== $name ) {
			$slug = sanitize_title( $name );
			$code = sanitize_text_field( wp_unslash( $new['provider_code'] ?? '' ) );
			if ( isset( $categories[ $slug ] ) ) {
				self::reject( sprintf( __( 'A tax category named "%s" already exists, so it was kept as is.', 'wctc' ), $categories[ $slug ]['name'] ) );
				$code = '' === $code ? ( $categories[ $slug ]['provider_code'] ?? '' ) : $code;
			}
			$categories[ $slug ] = array(
				'name'          => $categories[ $slug ]['name'] ?? $name,
				'provider_code' => $code,
			);
		}
		Store::save_categories( $categories );

		$rules = array();
		foreach ( (array) ( $_POST['wctc_rule'] ?? array() ) as $n => $row ) {
			$category = sanitize_title( $row['category'] ?? '' );
			$blank    = '' === $category && '' === trim( (string) ( $row['country'] ?? '' ) ) && '' === trim( (string) ( $row['max_price'] ?? '' ) );
			if ( ! empty( $row['remove'] ) || $blank ) {
				continue;
			}
			$label = sprintf( __( 'Category rule %d', 'wctc' ), (int) $n + 1 );
			if ( '' === $category || ! isset( $categories[ $category ] ) ) {
				self::reject( sprintf( __( '%s was not saved: pick a tax category.', 'wctc' ), $label ) );
				continue;
			}
			$label .= ' (' . $categories[ $category ]['name'] . ')';
			if ( '' === trim( (string) ( $row['country'] ?? '' ) ) ) {
				self::reject( sprintf( __( '%s was not saved: pick a country, or "Any country".', 'wctc' ), $label ) );
				continue;
			}
			$max = trim( (string) wp_unslash( $row['max_price'] ?? '' ) );
			if ( '' !== $max && ! is_numeric( str_replace( array( ',', ' ' ), array( '.', '' ), $max ) ) ) {
				self::reject( sprintf( __( '%s was not saved: the price limit "%s" is not a number. Enter an amount like 110, or leave it blank for any price.', 'wctc' ), $label, $max ) );
				continue;
			}
			$mode = sanitize_key( $row['limit_mode'] ?? '' );
			$mode = isset( Engine::limit_modes()[ $mode ] ) ? $mode : Engine::LIMIT_CLIFF;
			if ( Engine::LIMIT_EXCESS === $mode && '' === $max ) {
				self::reject( sprintf( __( '%s was not saved: Excess mode needs a price limit (the threshold above which tax applies). Enter an amount like 175, or switch to Cliff mode.', 'wctc' ), $label ) );
				continue;
			}
			$start = sanitize_text_field( $row['start'] ?? '' );
			$end   = sanitize_text_field( $row['end'] ?? '' );
			if ( '' !== $start && '' !== $end && $end < $start ) {
				self::reject( sprintf( __( '%s was not saved: it ends (%s) before it starts (%s).', 'wctc' ), $label, $end, $start ) );
				continue;
			}
			$country = self::place( $row['country'], '*' );
			$rules[] = array(
				'category'   => $category,
				'country'    => $country,
				'state'      => self::place( $row['state'] ?? '', '*', '*' === $country ? '-' : $country ),
				'postcode'   => self::place( $row['postcode'] ?? '', '*', '-' ),
				'max_price'  => '' === $max ? '' : wc_format_decimal( $max ),
				'limit_mode' => $mode,
				'tax_class'  => sanitize_title( $row['tax_class'] ?? '' ),
				'start'      => $start,
				'end'        => $end,
			);
		}
		$dups = Engine::duplicate_category_rules( $rules );
		if ( $dups ) {
			self::reject( sprintf( _n( '%d category rule repeats an earlier row (same category, place and price limit), so only the first can apply. Remove one.', '%d category rules repeat an earlier row (same category, place and price limit), so only the first of each can apply. Remove the extras.', count( $dups ), 'wctc' ), count( $dups ) ) );
		}
		Store::save_rules( $rules );

		$ship  = array();
		$modes = Engine::modes();
		foreach ( (array) ( $_POST['wctc_ship'] ?? array() ) as $row ) {
			if ( ! empty( $row['remove'] ) || '' === trim( (string) ( $row['country'] ?? '' ) ) ) {
				continue;
			}
			$mode    = sanitize_key( $row['mode'] ?? '' );
			$split   = sanitize_key( $row['split'] ?? '' );
			$country = self::place( $row['country'] );
			$ship[]  = array(
				'country'        => $country,
				'state'          => self::place( $row['state'] ?? '', '*', '*' === $country ? '-' : $country ),
				'postcode'       => self::place( $row['postcode'] ?? '', '*', '-' ),
				'mode'           => isset( $modes[ $mode ] ) ? $mode : Engine::MODE_FOLLOWS,
				'conditions_met' => empty( $row['conditions_met'] ) ? 0 : 1,
				'split'          => isset( Engine::splits()[ $split ] ) ? $split : Engine::SPLIT_VALUE,
			);
		}
		Store::save_shipping_rules( $ship );
		// phpcs:enable
	}

	/** Load or clear example data. */
	public static function handle_examples() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed' );
		}
		check_admin_referer( 'wctc_examples' );
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore
		if ( 'load' === $do ) {
			Examples::load();
		} elseif ( 'rates' === $do ) {
			Examples::add_rates();
		} elseif ( 'clear' === $do ) {
			Examples::clear();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=tax&section=' . self::SECTION . '&wctc_done=' . $do ) );
		exit;
	}

	/** Confirmation after example actions. */
	public static function notices() {
		$done = sanitize_key( $_GET['wctc_done'] ?? '' ); // phpcs:ignore
		$msgs = array(
			'load'  => 'Example tax categories, category rules and shipping rules loaded. Assign "Clothing" or "Books" to some product categories to try them.',
			'rates' => 'Example tax rate rows added to the Standard rates table (named "WCTC example …").',
			'clear' => 'Example data and example tax rate rows removed.',
		);
		if ( isset( $msgs[ $done ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $done ] ) . '</p></div>';
		}
	}
}
