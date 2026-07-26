<?php
/**
 * Stripe webhook endpoint.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Receives Stripe events on a single endpoint.
 *
 * One endpoint, several event types. This matters more than it looks: a Stripe
 * account has a hard limit on the number of webhook endpoints it can register,
 * and a plugin that claims one endpoint per event type will eventually make an
 * account unusable for everything else. Route on `type` inside the handler
 * instead.
 */
class Webhook_Handler {

	use Singleton;

	const ROUTE_NAMESPACE = 'gfsi/v1';
	const ROUTE           = '/webhook';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	/**
	 * Kept for the activation hook; the REST API needs no rewrite rule of its
	 * own, but flushing on activation makes the endpoint available immediately
	 * on installs with unusual permalink setups.
	 */
	public static function add_rewrite_rule(): void {
		// No-op: the REST route is registered on rest_api_init.
	}

	/**
	 * The public endpoint URL, to paste into the Stripe dashboard.
	 */
	public static function endpoint_url(): string {
		return rest_url( self::ROUTE_NAMESPACE . self::ROUTE );
	}

	/**
	 * Register the REST route.
	 */
	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true', // Authenticated by signature instead.
			]
		);
	}

	/**
	 * Handle an incoming Stripe event.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe_signature' ) ?? '';
		$secret    = Settings::instance()->get_webhook_secret();

		if ( '' === $secret ) {
			Logger::error( 'Webhook received but no signing secret is configured.' );
			return new \WP_REST_Response( [ 'error' => 'not_configured' ], 500 );
		}

		if ( ! Stripe_Client::verify_signature( $payload, $signature, $secret ) ) {
			Logger::error( 'Webhook signature verification failed.' );
			return new \WP_REST_Response( [ 'error' => 'invalid_signature' ], 400 );
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || ! isset( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'malformed' ], 400 );
		}

		try {
			$this->route( (string) $event['type'], $event['data']['object'] ?? [], $event );
		} catch ( \Throwable $e ) {
			Logger::error( sprintf( 'Handling %s failed: %s', $event['type'], $e->getMessage() ) );

			// 500 tells Stripe to retry, which is what we want for transient failures.
			return new \WP_REST_Response( [ 'error' => 'handler_failed' ], 500 );
		}

		return new \WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Dispatch an event to the right handler.
	 *
	 * @param string $type   Event type.
	 * @param array  $object The event's data object.
	 * @param array  $event  The full event.
	 *
	 * @throws Stripe_Exception On Stripe API failure.
	 */
	private function route( string $type, array $object, array $event ): void {
		switch ( $type ) {
			case 'checkout.session.completed':
				$this->on_setup_completed( $object );
				break;

			case 'invoice.paid':
				$this->on_invoice_paid( $object );
				break;

			case 'invoice.payment_failed':
				$this->on_invoice_failed( $object );
				break;

			case 'subscription_schedule.completed':
				$this->on_schedule_completed( $object );
				break;
		}

		/**
		 * Fires for every verified Stripe event reaching this endpoint.
		 *
		 * Lets other code react without registering a second Stripe endpoint.
		 *
		 * @param string $type   Event type.
		 * @param array  $object The event's data object.
		 * @param array  $event  The full event.
		 */
		do_action( 'gfsi_stripe_event', $type, $object, $event );
	}

	/**
	 * Card saved — create the schedule and charge the first installment.
	 *
	 * @param array $session The Checkout Session.
	 *
	 * @throws Stripe_Exception On Stripe API failure.
	 */
	private function on_setup_completed( array $session ): void {
		$entry_id = (int) ( $session['metadata']['gfsi_entry_id'] ?? 0 );

		if ( ! $entry_id ) {
			return; // Not one of ours.
		}

		if ( gform_get_meta( $entry_id, Form_Handler::META_SCHEDULE ) ) {
			return; // Already handled; Stripe retries are expected and must be safe.
		}

		$client = Stripe_Client::from_settings();

		$setup_intent_id = (string) ( $session['setup_intent'] ?? '' );

		if ( '' === $setup_intent_id ) {
			throw new Stripe_Exception( 'Checkout session has no setup intent.' );
		}

		$setup_intent   = $client->get( 'setup_intents/' . $setup_intent_id );
		$payment_method = (string) ( $setup_intent['payment_method'] ?? '' );
		$customer_id    = (string) ( $setup_intent['customer'] ?? $session['customer'] ?? '' );

		if ( '' === $payment_method ) {
			throw new Stripe_Exception( 'Setup intent has no payment method.' );
		}

		if ( '' === $customer_id ) {
			$customer    = $client->post(
				'customers',
				[
					'email'          => $session['customer_details']['email'] ?? '',
					'payment_method' => $payment_method,
					'metadata'       => [ 'gfsi_entry_id' => (string) $entry_id ],
				]
			);
			$customer_id = (string) $customer['id'];
		}

		$plan = new Plan(
			(int) $session['metadata']['gfsi_total'],
			(int) $session['metadata']['gfsi_installments'],
			(string) ( $session['currency'] ?? 'eur' ),
			(string) ( $session['metadata']['gfsi_interval'] ?? 'month' )
		);

		$builder  = new Schedule_Builder( $client );
		$schedule = $builder->create_schedule(
			$plan,
			$customer_id,
			$payment_method,
			[
				'entry_id'    => $entry_id,
				'description' => $session['metadata']['gfsi_description'] ?? __( 'Installment payment', 'gf-stripe-installments' ),
			]
		);

		gform_update_meta( $entry_id, Form_Handler::META_SCHEDULE, $schedule['id'] );

		Logger::info( sprintf( 'Schedule %s created for entry %d (%s).', $schedule['id'], $entry_id, $plan->describe() ) );

		/**
		 * Fires once an installment plan is live.
		 *
		 * @param array $schedule The Stripe Subscription Schedule.
		 * @param int   $entry_id The Gravity Forms entry id.
		 * @param Plan  $plan     The plan.
		 */
		do_action( 'gfsi_plan_started', $schedule, $entry_id, $plan );
	}

	/**
	 * One installment collected.
	 *
	 * @param array $invoice The invoice.
	 */
	private function on_invoice_paid( array $invoice ): void {
		$entry_id = $this->entry_id_from_invoice( $invoice );

		if ( ! $entry_id ) {
			return;
		}

		$paid = (int) gform_get_meta( $entry_id, '_gfsi_paid_count' );
		gform_update_meta( $entry_id, '_gfsi_paid_count', $paid + 1 );

		/**
		 * Fires when an installment is successfully collected.
		 *
		 * @param array $invoice  The Stripe invoice.
		 * @param int   $entry_id The entry id.
		 */
		do_action( 'gfsi_installment_paid', $invoice, $entry_id );
	}

	/**
	 * An installment failed. Stripe will retry according to your dunning
	 * settings; this hook is where you notify someone.
	 *
	 * @param array $invoice The invoice.
	 */
	private function on_invoice_failed( array $invoice ): void {
		$entry_id = $this->entry_id_from_invoice( $invoice );

		Logger::error(
			sprintf(
				'Installment failed for entry %s (invoice %s).',
				$entry_id ?: 'unknown',
				$invoice['id'] ?? '?'
			)
		);

		/**
		 * Fires when an installment payment fails.
		 *
		 * @param array $invoice  The Stripe invoice.
		 * @param int   $entry_id The entry id, or 0 if unknown.
		 */
		do_action( 'gfsi_installment_failed', $invoice, $entry_id );
	}

	/**
	 * The plan reached its last installment and Stripe closed it.
	 *
	 * @param array $schedule The subscription schedule.
	 */
	private function on_schedule_completed( array $schedule ): void {
		$entry_id = (int) ( $schedule['metadata']['gfsi_entry_id'] ?? 0 );

		if ( $entry_id ) {
			gform_update_meta( $entry_id, '_gfsi_completed', 1 );
		}

		/**
		 * Fires when every installment has been collected.
		 *
		 * @param array $schedule The Stripe Subscription Schedule.
		 * @param int   $entry_id The entry id, or 0 if unknown.
		 */
		do_action( 'gfsi_plan_completed', $schedule, $entry_id );
	}

	/**
	 * Resolve the entry id from an invoice's metadata chain.
	 *
	 * @param array $invoice The invoice.
	 */
	private function entry_id_from_invoice( array $invoice ): int {
		if ( isset( $invoice['subscription_details']['metadata']['gfsi_entry_id'] ) ) {
			return (int) $invoice['subscription_details']['metadata']['gfsi_entry_id'];
		}

		return (int) ( $invoice['metadata']['gfsi_entry_id'] ?? 0 );
	}
}
