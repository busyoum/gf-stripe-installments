<?php
/**
 * Logging helper.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the Gravity Forms logger when available, and to the PHP error log
 * otherwise. Never logs card data, keys or full Stripe payloads.
 */
class Logger {

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message.
	 */
	public static function info( string $message ): void {
		self::write( $message, 'info' );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message.
	 */
	public static function error( string $message ): void {
		self::write( $message, 'error' );
	}

	/**
	 * Write a line.
	 *
	 * @param string $message Message.
	 * @param string $level   'info' or 'error'.
	 */
	private static function write( string $message, string $level ): void {
		/**
		 * Filter log messages, or short-circuit logging by returning an empty string.
		 *
		 * @param string $message The message.
		 * @param string $level   Severity.
		 */
		$message = (string) apply_filters( 'gfsi_log_message', $message, $level );

		if ( '' === $message ) {
			return;
		}

		if ( class_exists( 'GFLogging' ) ) {
			\GFLogging::include_logger();
			\GFLogging::log_message(
				'gf-stripe-installments',
				$message,
				'error' === $level ? \KLogger::ERROR : \KLogger::INFO
			);
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[gf-stripe-installments] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
