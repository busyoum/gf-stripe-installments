<?php
/**
 * Minimal Stripe REST client built on the WordPress HTTP API.
 *
 * We deliberately avoid bundling the official Stripe PHP SDK: multiple plugins
 * shipping their own copy of the SDK is a classic source of fatal "cannot
 * redeclare class" errors on WordPress installs. The handful of endpoints this
 * plugin needs are simple enough to call directly.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when the Stripe API returns an error or is unreachable.
 */
class Stripe_Exception extends \Exception {}

/**
 * Thin wrapper around the Stripe REST API.
 */
class Stripe_Client {

	const API_BASE    = 'https://api.stripe.com/v1/';
	const API_VERSION = '2024-06-20';

	/**
	 * Secret API key.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Stripe secret key.
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/**
	 * Build a client from the stored settings.
	 *
	 * @throws Stripe_Exception If no secret key is configured.
	 */
	public static function from_settings(): self {
		$key = Settings::instance()->get_secret_key();

		if ( '' === $key ) {
			throw new Stripe_Exception( 'No Stripe secret key configured.' );
		}

		return new self( $key );
	}

	/**
	 * POST to a Stripe endpoint.
	 *
	 * @param string $path            Endpoint path, e.g. 'checkout/sessions'.
	 * @param array  $params          Request parameters (nested arrays allowed).
	 * @param string $idempotency_key Optional idempotency key.
	 *
	 * @return array Decoded response body.
	 * @throws Stripe_Exception On transport or API error.
	 */
	public function post( string $path, array $params = [], string $idempotency_key = '' ): array {
		$headers = $this->headers();

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = wp_remote_post(
			self::API_BASE . $path,
			[
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $this->encode( $params ),
			]
		);

		return $this->handle_response( $response, $path );
	}

	/**
	 * GET a Stripe resource.
	 *
	 * @param string $path   Endpoint path.
	 * @param array  $params Query parameters.
	 *
	 * @return array Decoded response body.
	 * @throws Stripe_Exception On transport or API error.
	 */
	public function get( string $path, array $params = [] ): array {
		$url = self::API_BASE . $path;

		if ( $params ) {
			$url .= '?' . http_build_query( $params );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 30,
				'headers' => $this->headers(),
			]
		);

		return $this->handle_response( $response, $path );
	}

	/**
	 * Default request headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		];
	}

	/**
	 * Stripe expects PHP-style bracket notation for nested params, which is
	 * exactly what http_build_query produces. Booleans must be sent as
	 * "true"/"false" rather than 1/0.
	 *
	 * @param array $params Parameters to encode.
	 */
	private function encode( array $params ): string {
		array_walk_recursive(
			$params,
			static function ( &$value ): void {
				if ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}
			}
		);

		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Normalise a WP HTTP response into an array, or throw.
	 *
	 * @param array|\WP_Error $response Raw response.
	 * @param string          $path     Endpoint, for error context.
	 *
	 * @return array
	 * @throws Stripe_Exception On transport or API error.
	 */
	private function handle_response( $response, string $path ): array {
		if ( is_wp_error( $response ) ) {
			throw new Stripe_Exception(
				sprintf( 'Stripe request to %s failed: %s', $path, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			throw new Stripe_Exception( sprintf( 'Unreadable Stripe response from %s.', $path ) );
		}

		if ( $code >= 400 ) {
			$message = $body['error']['message'] ?? 'Unknown Stripe error';
			$type    = $body['error']['type'] ?? 'api_error';

			throw new Stripe_Exception( sprintf( '[%s] %s', $type, $message ), $code );
		}

		return $body;
	}

	/**
	 * Verify a webhook signature header (Stripe's scheme, without the SDK).
	 *
	 * @param string $payload         Raw request body.
	 * @param string $signature_header Value of the Stripe-Signature header.
	 * @param string $secret          Webhook signing secret (whsec_…).
	 * @param int    $tolerance       Max age of the timestamp, in seconds.
	 */
	public static function verify_signature( string $payload, string $signature_header, string $secret, int $tolerance = 300 ): bool {
		$timestamp  = '';
		$signatures = [];

		foreach ( explode( ',', $signature_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			if ( 't' === $pair[0] ) {
				$timestamp = $pair[1];
			} elseif ( 'v1' === $pair[0] ) {
				$signatures[] = $pair[1];
			}
		}

		if ( '' === $timestamp || ! $signatures ) {
			return false;
		}

		if ( abs( time() - (int) $timestamp ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		foreach ( $signatures as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true;
			}
		}

		return false;
	}
}
