<?php
/**
 * Gravity Forms integration.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the per-form settings, builds the Plan from the submitted entry and
 * sends the user to Stripe Checkout.
 */
class Form_Handler {

	use Singleton;

	/**
	 * Entry meta key holding the Stripe Checkout Session id.
	 */
	const META_SESSION = '_gfsi_session_id';

	/**
	 * Entry meta key holding the Stripe Subscription Schedule id.
	 */
	const META_SCHEDULE = '_gfsi_schedule_id';

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'gform_form_settings_fields', [ $this, 'settings_fields' ], 10, 2 );
		add_filter( 'gform_confirmation', [ $this, 'maybe_redirect_to_checkout' ], 10, 4 );
	}

	/**
	 * Add an "Installments" section to the Gravity Forms form settings screen.
	 *
	 * @param array $fields Settings fields.
	 * @param array $form   Current form.
	 *
	 * @return array
	 */
	public function settings_fields( array $fields, array $form ): array {
		$number_fields = [ [ 'label' => __( '— select a field —', 'gf-stripe-installments' ), 'value' => '' ] ];

		foreach ( rgar( $form, 'fields', [] ) as $field ) {
			if ( in_array( $field->get_input_type(), [ 'number', 'total', 'product', 'calculation', 'hidden' ], true ) ) {
				$number_fields[] = [
					'label' => GFCommon::get_label( $field ),
					'value' => (string) $field->id,
				];
			}
		}

		$fields['gfsi'] = [
			'title'  => __( 'Stripe Installments', 'gf-stripe-installments' ),
			'fields' => [
				[
					'name'  => 'gfsi_enabled',
					'type'  => 'toggle',
					'label' => __( 'Pay in installments', 'gf-stripe-installments' ),
					'tooltip' => __( 'Send this form\'s submissions to Stripe Checkout and charge the total in several installments.', 'gf-stripe-installments' ),
				],
				[
					'name'    => 'gfsi_amount_field',
					'type'    => 'select',
					'label'   => __( 'Total amount field', 'gf-stripe-installments' ),
					'choices' => $number_fields,
					'dependency' => [ 'live' => true, 'fields' => [ [ 'field' => 'gfsi_enabled' ] ] ],
				],
				[
					'name'    => 'gfsi_count',
					'type'    => 'text',
					'label'   => __( 'Number of installments', 'gf-stripe-installments' ),
					'default_value' => '3',
					'input_type'    => 'number',
					'dependency' => [ 'live' => true, 'fields' => [ [ 'field' => 'gfsi_enabled' ] ] ],
				],
				[
					'name'    => 'gfsi_interval',
					'type'    => 'select',
					'label'   => __( 'Interval', 'gf-stripe-installments' ),
					'choices' => [
						[ 'label' => __( 'Monthly', 'gf-stripe-installments' ), 'value' => 'month' ],
						[ 'label' => __( 'Weekly', 'gf-stripe-installments' ), 'value' => 'week' ],
					],
					'default_value' => 'month',
					'dependency' => [ 'live' => true, 'fields' => [ [ 'field' => 'gfsi_enabled' ] ] ],
				],
			],
		];

		return $fields;
	}

	/**
	 * After a submission, redirect to Stripe Checkout if installments are on.
	 *
	 * @param string|array $confirmation Confirmation output.
	 * @param array        $form         Form object.
	 * @param array        $entry        Entry object.
	 * @param bool         $ajax         Whether the form was submitted over AJAX.
	 *
	 * @return string|array
	 */
	public function maybe_redirect_to_checkout( $confirmation, $form, $entry, $ajax ) {
		if ( ! rgar( $form, 'gfsi_enabled' ) ) {
			return $confirmation;
		}

		try {
			$plan = $this->build_plan( $form, $entry );

			$builder = new Schedule_Builder( Stripe_Client::from_settings() );
			$session = $builder->create_setup_session(
				$plan,
				[
					'entry_id'    => $entry['id'],
					'form_id'     => $form['id'],
					'email'       => $this->find_email( $form, $entry ),
					'description' => rgar( $form, 'title' ),
				],
				$this->return_url( $entry ),
				$this->cancel_url( $form )
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'Checkout session failed for entry ' . rgar( $entry, 'id' ) . ': ' . $e->getMessage() );

			/**
			 * Filter the message shown when Stripe cannot be reached.
			 *
			 * @param string $message Error message.
			 * @param array  $entry   The entry.
			 */
			return apply_filters(
				'gfsi_checkout_error_message',
				'<div class="gform_confirmation_message">' .
				esc_html__( 'We could not reach the payment provider. Your submission has been saved — please contact us to complete payment.', 'gf-stripe-installments' ) .
				'</div>',
				$entry
			);
		}

		gform_update_meta( $entry['id'], self::META_SESSION, $session['id'] );

		return [ 'redirect' => $session['url'] ];
	}

	/**
	 * Build the Plan from the form settings and the submitted entry.
	 *
	 * @param array $form  Form object.
	 * @param array $entry Entry object.
	 *
	 * @throws \InvalidArgumentException If the settings or the amount are unusable.
	 */
	private function build_plan( array $form, array $entry ): Plan {
		$field_id = (string) rgar( $form, 'gfsi_amount_field' );
		$count    = (int) rgar( $form, 'gfsi_count' );
		$interval = (string) rgar( $form, 'gfsi_interval' ) ?: 'month';

		if ( '' === $field_id ) {
			throw new \InvalidArgumentException( 'No amount field configured.' );
		}

		$raw = rgar( $entry, $field_id );

		if ( '' === $raw || null === $raw ) {
			throw new \InvalidArgumentException( sprintf( 'Amount field %s is empty.', $field_id ) );
		}

		$currency = strtolower( GFCommon::get_currency() ?: 'EUR' );

		$plan = Plan::from_decimal( $raw, $count, $currency, $interval );

		/**
		 * Filter the plan before it is sent to Stripe.
		 *
		 * Useful to vary the number of installments by product, or to refuse
		 * installments below a minimum total.
		 *
		 * @param Plan  $plan  The plan.
		 * @param array $form  Form object.
		 * @param array $entry Entry object.
		 */
		return apply_filters( 'gfsi_plan', $plan, $form, $entry );
	}

	/**
	 * Find the first email field value in the entry, to prefill Checkout.
	 *
	 * @param array $form  Form object.
	 * @param array $entry Entry object.
	 */
	private function find_email( array $form, array $entry ): string {
		foreach ( rgar( $form, 'fields', [] ) as $field ) {
			if ( 'email' === $field->get_input_type() ) {
				$value = rgar( $entry, (string) $field->id );

				if ( is_string( $value ) && is_email( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * URL Stripe returns to after a successful setup.
	 *
	 * @param array $entry Entry object.
	 */
	private function return_url( array $entry ): string {
		/**
		 * Filter the post-payment return URL.
		 *
		 * @param string $url   Return URL.
		 * @param array  $entry Entry object.
		 */
		return apply_filters(
			'gfsi_return_url',
			add_query_arg( 'gfsi', 'done', home_url( '/' ) ),
			$entry
		);
	}

	/**
	 * URL Stripe returns to if the customer abandons Checkout.
	 *
	 * @param array $form Form object.
	 */
	private function cancel_url( array $form ): string {
		/**
		 * Filter the cancel URL.
		 *
		 * @param string $url  Cancel URL.
		 * @param array  $form Form object.
		 */
		return apply_filters( 'gfsi_cancel_url', wp_get_referer() ?: home_url( '/' ), $form );
	}
}
