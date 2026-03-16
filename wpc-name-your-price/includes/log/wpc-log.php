<?php
defined( 'ABSPATH' ) || exit;

register_activation_hook( defined( 'WOONP_LITE' ) ? WOONP_LITE : WOONP_FILE, 'woonp_activate' );
register_deactivation_hook( defined( 'WOONP_LITE' ) ? WOONP_LITE : WOONP_FILE, 'woonp_deactivate' );
add_action( 'admin_init', 'woonp_check_version' );

function woonp_check_version() {
	if ( ! empty( get_option( 'woonp_version' ) ) && ( get_option( 'woonp_version' ) < WOONP_VERSION ) ) {
		wpc_log( 'woonp', 'upgraded' );
		update_option( 'woonp_version', WOONP_VERSION, false );
	}
}

function woonp_activate() {
	wpc_log( 'woonp', 'installed' );
	update_option( 'woonp_version', WOONP_VERSION, false );
}

function woonp_deactivate() {
	wpc_log( 'woonp', 'deactivated' );
}

if ( ! function_exists( 'wpc_log' ) ) {
	function wpc_log( $prefix, $action ) {
		$logs = get_option( 'wpc_logs', [] );
		$user = wp_get_current_user();

		if ( ! isset( $logs[ $prefix ] ) ) {
			$logs[ $prefix ] = [];
		}

		$logs[ $prefix ][] = [
			'time'   => current_time( 'mysql' ),
			'user'   => $user->display_name . ' (ID: ' . $user->ID . ')',
			'action' => $action
		];

		update_option( 'wpc_logs', $logs, false );
	}
}