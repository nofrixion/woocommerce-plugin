<?php

declare( strict_types=1 );

namespace Nofrixion\WC\Helper;

class Logger {

	public static function debug($message, $force = false): void {
		if ( get_option( 'nofrixion_debug' ) === 'yes' || $force ) {
			// Convert message to string
			if ( ! is_string( $message ) ) {
				$message = wc_print_r( $message, true );
			}

			$logger = new \WC_Logger();
			$context = array( 'source' => NOFRIXION_PLUGIN_ID );
			$logger->debug( $message, $context );
		}
	}

	public static function getLogFileUrl(): string {
		$log_file = NOFRIXION_PLUGIN_ID . '-' . date('Y-m-d') . '-' . sanitize_file_name( wp_hash( NOFRIXION_PLUGIN_ID ) ) . '-log';
		return esc_url(admin_url('admin.php?page=wc-status&tab=logs&log_file=' . $log_file));
	}

}
