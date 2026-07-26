<?php
/**
 * Plugin Name:       Gravity Forms Stripe Installments
 * Plugin URI:        https://github.com/Busyoum/gf-stripe-installments
 * Description:       Collect a payment in a fixed number of installments (3x, 4x, 10x…) from a Gravity Forms submission, using Stripe Subscription Schedules. Stops automatically after the last installment.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Busyoum
 * Author URI:        https://github.com/Busyoum
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gf-stripe-installments
 * Domain Path:       /languages
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

const VERSION     = '0.1.0';
const PLUGIN_FILE = __FILE__;

require_once __DIR__ . '/includes/trait-singleton.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-stripe-client.php';
require_once __DIR__ . '/includes/class-plan.php';
require_once __DIR__ . '/includes/class-schedule-builder.php';
require_once __DIR__ . '/includes/class-form-handler.php';
require_once __DIR__ . '/includes/class-webhook-handler.php';
require_once __DIR__ . '/includes/class-logger.php';

/**
 * Boot the plugin once all plugins are loaded, so we can check for Gravity Forms.
 */
function boot(): void {
	if ( ! class_exists( 'GFForms' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\missing_gravity_forms_notice' );
		return;
	}

	Settings::instance()->init();
	Form_Handler::instance()->init();
	Webhook_Handler::instance()->init();
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\boot' );

/**
 * Admin notice shown when Gravity Forms is not active.
 */
function missing_gravity_forms_notice(): void {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'Gravity Forms Stripe Installments requires Gravity Forms to be installed and active.', 'gf-stripe-installments' );
	echo '</p></div>';
}

/**
 * Register the webhook rewrite endpoint on activation and flush rules.
 */
function activate(): void {
	Webhook_Handler::add_rewrite_rule();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Clean up rewrite rules on deactivation.
 */
function deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );
