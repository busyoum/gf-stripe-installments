<?php
/**
 * Plugin settings.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen and credential access.
 *
 * Credentials can be defined as constants in wp-config.php, which is the
 * recommended setup: keys stay out of the database and out of any backup or
 * migration of the site content.
 *
 *     define( 'GFSI_STRIPE_SECRET_KEY', 'sk_live_…' );
 *     define( 'GFSI_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
 */
class Settings {

	use Singleton;

	const OPTION = 'gfsi_settings';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	/**
	 * Add the settings page under Settings.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Stripe Installments', 'gf-stripe-installments' ),
			__( 'Stripe Installments', 'gf-stripe-installments' ),
			'manage_options',
			'gfsi',
			[ $this, 'render' ]
		);
	}

	/**
	 * Register the option and its fields.
	 */
	public function register(): void {
		register_setting(
			'gfsi',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];

		return [
			'secret_key'     => sanitize_text_field( $input['secret_key'] ?? '' ),
			'webhook_secret' => sanitize_text_field( $input['webhook_secret'] ?? '' ),
		];
	}

	/**
	 * Render the settings page.
	 */
	public function render(): void {
		$settings         = get_option( self::OPTION, [] );
		$key_from_const   = defined( 'GFSI_STRIPE_SECRET_KEY' );
		$hook_from_const  = defined( 'GFSI_STRIPE_WEBHOOK_SECRET' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stripe Installments', 'gf-stripe-installments' ); ?></h1>

			<p><?php esc_html_e( 'Webhook endpoint to register in your Stripe dashboard:', 'gf-stripe-installments' ); ?><br>
				<code><?php echo esc_url( Webhook_Handler::endpoint_url() ); ?></code></p>

			<p><?php esc_html_e( 'Events to subscribe to:', 'gf-stripe-installments' ); ?>
				<code>checkout.session.completed</code>, <code>invoice.paid</code>,
				<code>invoice.payment_failed</code>, <code>subscription_schedule.completed</code></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'gfsi' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="gfsi_secret_key"><?php esc_html_e( 'Secret key', 'gf-stripe-installments' ); ?></label></th>
						<td>
							<?php if ( $key_from_const ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php — this field is ignored.', 'gf-stripe-installments' ); ?></em></p>
							<?php else : ?>
								<input type="password" class="regular-text" id="gfsi_secret_key"
									name="<?php echo esc_attr( self::OPTION ); ?>[secret_key]"
									value="<?php echo esc_attr( $settings['secret_key'] ?? '' ); ?>" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Recommended: define GFSI_STRIPE_SECRET_KEY in wp-config.php instead.', 'gf-stripe-installments' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="gfsi_webhook_secret"><?php esc_html_e( 'Webhook signing secret', 'gf-stripe-installments' ); ?></label></th>
						<td>
							<?php if ( $hook_from_const ) : ?>
								<p><em><?php esc_html_e( 'Defined in wp-config.php — this field is ignored.', 'gf-stripe-installments' ); ?></em></p>
							<?php else : ?>
								<input type="password" class="regular-text" id="gfsi_webhook_secret"
									name="<?php echo esc_attr( self::OPTION ); ?>[webhook_secret]"
									value="<?php echo esc_attr( $settings['webhook_secret'] ?? '' ); ?>" autocomplete="off">
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The Stripe secret key, constant taking precedence over the option.
	 */
	public function get_secret_key(): string {
		if ( defined( 'GFSI_STRIPE_SECRET_KEY' ) ) {
			return (string) constant( 'GFSI_STRIPE_SECRET_KEY' );
		}

		$settings = get_option( self::OPTION, [] );

		return (string) ( $settings['secret_key'] ?? '' );
	}

	/**
	 * The webhook signing secret, constant taking precedence over the option.
	 */
	public function get_webhook_secret(): string {
		if ( defined( 'GFSI_STRIPE_WEBHOOK_SECRET' ) ) {
			return (string) constant( 'GFSI_STRIPE_WEBHOOK_SECRET' );
		}

		$settings = get_option( self::OPTION, [] );

		return (string) ( $settings['webhook_secret'] ?? '' );
	}
}
