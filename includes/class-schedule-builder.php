<?php
/**
 * Turns a Plan into a Stripe Subscription Schedule.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the Stripe objects that actually run an installment plan.
 *
 * Why a Subscription Schedule rather than a plain Subscription: a Subscription
 * runs forever until something cancels it. A Schedule phase accepts an
 * `iterations` count and an `end_behavior`, so Stripe itself stops after the
 * last installment. Nothing on your side has to remember to cancel anything,
 * which is what makes the difference between a demo and something you can leave
 * running for a year.
 */
class Schedule_Builder {

	/**
	 * Stripe client.
	 *
	 * @var Stripe_Client
	 */
	private Stripe_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Stripe_Client $client Stripe client.
	 */
	public function __construct( Stripe_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Create a Checkout Session that collects a payment method without charging.
	 *
	 * The customer is not charged during Checkout. Once the card is saved we
	 * create the schedule from the webhook, which charges the first installment
	 * immediately. Doing it in this order means we never take money for a plan
	 * we then fail to set up.
	 *
	 * @param Plan   $plan        The installment plan.
	 * @param array  $context     Arbitrary context stored in metadata (entry id, form id…).
	 * @param string $success_url Redirect URL on success.
	 * @param string $cancel_url  Redirect URL on abandon.
	 *
	 * @return array The Checkout Session object.
	 * @throws Stripe_Exception On API error.
	 */
	public function create_setup_session( Plan $plan, array $context, string $success_url, string $cancel_url ): array {
		$metadata = $this->build_metadata( $plan, $context );

		$params = [
			'mode'                => 'setup',
			'currency'            => $plan->currency(),
			'success_url'         => $success_url,
			'cancel_url'          => $cancel_url,
			'metadata'            => $metadata,
			'setup_intent_data'   => [ 'metadata' => $metadata ],
			'payment_method_types' => [ 'card' ],
		];

		if ( ! empty( $context['email'] ) && is_email( $context['email'] ) ) {
			$params['customer_email'] = $context['email'];
		}

		/**
		 * Filter the Checkout Session parameters before creation.
		 *
		 * @param array $params Stripe parameters.
		 * @param Plan  $plan   The plan.
		 * @param array $context Submission context.
		 */
		$params = apply_filters( 'gfsi_checkout_session_params', $params, $plan, $context );

		return $this->client->post( 'checkout/sessions', $params );
	}

	/**
	 * Create the Subscription Schedule that charges the installments.
	 *
	 * @param Plan   $plan              The plan.
	 * @param string $customer_id       Stripe customer id.
	 * @param string $payment_method_id Saved payment method id.
	 * @param array  $context           Context for metadata and the invoice description.
	 *
	 * @return array The Subscription Schedule object.
	 * @throws Stripe_Exception On API error.
	 */
	public function create_schedule( Plan $plan, string $customer_id, string $payment_method_id, array $context = [] ): array {
		$amounts     = $plan->installments();
		$description = $context['description'] ?? __( 'Installment payment', 'gf-stripe-installments' );
		$metadata    = $this->build_metadata( $plan, $context );

		$phases = $this->phases( $amounts, $plan, $description );

		$params = [
			'customer'      => $customer_id,
			'start_date'    => 'now',
			'end_behavior'  => 'cancel',
			'metadata'      => $metadata,
			'phases'        => $phases,
			'default_settings' => [
				'default_payment_method' => $payment_method_id,
				'collection_method'      => 'charge_automatically',
				'description'            => $description,
				'invoice_settings'       => [ 'days_until_due' => 0 ],
			],
		];

		/**
		 * Filter the Subscription Schedule parameters before creation.
		 *
		 * @param array $params  Stripe parameters.
		 * @param Plan  $plan    The plan.
		 * @param array $context Submission context.
		 */
		$params = apply_filters( 'gfsi_schedule_params', $params, $plan, $context );

		$idempotency_key = 'gfsi_schedule_' . ( $context['entry_id'] ?? md5( wp_json_encode( $params ) ) );

		return $this->client->post( 'subscription_schedules', $params, $idempotency_key );
	}

	/**
	 * Group the installment amounts into as few schedule phases as possible.
	 *
	 * Consecutive equal amounts collapse into one phase with an `iterations`
	 * count. An even 4× plan becomes a single phase; a plan whose remainder is
	 * spread over the first three installments becomes two phases. Stripe caps
	 * how many phases a schedule can hold, so collapsing runs rather than
	 * emitting one phase per installment is what keeps long plans (12×, 24×)
	 * workable.
	 *
	 * @param int[]  $amounts     Per-installment amounts.
	 * @param Plan   $plan        The plan.
	 * @param string $description Invoice description.
	 *
	 * @return array[]
	 */
	private function phases( array $amounts, Plan $plan, string $description ): array {
		$phases  = [];
		$current = null;
		$run     = 0;

		foreach ( $amounts as $amount ) {
			if ( $amount === $current ) {
				++$run;
				continue;
			}

			if ( null !== $current ) {
				$phases[] = $this->phase( $current, $run, $plan, $description );
			}

			$current = $amount;
			$run     = 1;
		}

		if ( null !== $current ) {
			$phases[] = $this->phase( $current, $run, $plan, $description );
		}

		return $phases;
	}

	/**
	 * Build one schedule phase.
	 *
	 * @param int    $amount      Amount per iteration.
	 * @param int    $iterations  Number of iterations.
	 * @param Plan   $plan        The plan.
	 * @param string $description Invoice description.
	 */
	private function phase( int $amount, int $iterations, Plan $plan, string $description ): array {
		return [
			'items'      => [
				[
					'price_data' => $this->price_data( $amount, $plan, $description ),
					'quantity'   => 1,
				],
			],
			'iterations' => $iterations,
		];
	}

	/**
	 * Inline price definition for one installment.
	 *
	 * Inline price_data avoids polluting the Stripe dashboard with one saved
	 * Price object per order, which becomes unmanageable fast.
	 *
	 * @param int    $amount      Amount in smallest currency unit.
	 * @param Plan   $plan        The plan.
	 * @param string $description Product name shown on the invoice.
	 */
	private function price_data( int $amount, Plan $plan, string $description ): array {
		return [
			'currency'    => $plan->currency(),
			'unit_amount' => $amount,
			'recurring'   => [
				'interval'       => $plan->interval(),
				'interval_count' => 1,
			],
			'product_data' => [ 'name' => $description ],
		];
	}

	/**
	 * Metadata attached to every Stripe object we create, so a payment can
	 * always be traced back to the form entry that produced it.
	 *
	 * @param Plan  $plan    The plan.
	 * @param array $context Submission context.
	 *
	 * @return array<string, string>
	 */
	private function build_metadata( Plan $plan, array $context ): array {
		$metadata = [
			'gfsi_version'      => VERSION,
			'gfsi_installments' => (string) $plan->count(),
			'gfsi_total'        => (string) $plan->total(),
			'gfsi_interval'     => $plan->interval(),
		];

		foreach ( [ 'entry_id', 'form_id' ] as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$metadata[ 'gfsi_' . $key ] = (string) $context[ $key ];
			}
		}

		/**
		 * Filter the metadata sent to Stripe.
		 *
		 * @param array $metadata Metadata key/value pairs.
		 * @param Plan  $plan     The plan.
		 * @param array $context  Submission context.
		 */
		return apply_filters( 'gfsi_stripe_metadata', $metadata, $plan, $context );
	}
}
