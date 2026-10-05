<?php
/**
 * Plugin Name: Playground SQLite shim (WCTC test store only)
 * Description: WordPress Playground runs on SQLite, which lacks MySQL's SUBSTRING_INDEX(). Stock WooCommerce's Analytics → Taxes list report uses it, so the per-rate table is empty on Playground with or without the tax categories prototype. This mu-plugin adds the function so the report can be reviewed. Not part of the proposal.
 */

add_action(
	'plugins_loaded',
	function () {
		global $wpdb;
		if ( ! isset( $wpdb->dbh ) || ! is_object( $wpdb->dbh ) ) {
			return;
		}
		$pdo = method_exists( $wpdb->dbh, 'get_sqlite_pdo' ) ? $wpdb->dbh->get_sqlite_pdo() : $wpdb->dbh;
		if ( ! $pdo instanceof PDO || ! method_exists( $pdo, 'sqliteCreateFunction' ) ) {
			return;
		}
		$pdo->sqliteCreateFunction(
			'SUBSTRING_INDEX',
			function ( $s, $d, $c ) {
				$parts = explode( (string) $d, (string) $s );
				$c     = (int) $c;
				if ( $c > 0 ) {
					return implode( $d, array_slice( $parts, 0, $c ) );
				}
				return $c < 0 ? implode( $d, array_slice( $parts, $c ) ) : '';
			},
			3
		);
	},
	1
);
