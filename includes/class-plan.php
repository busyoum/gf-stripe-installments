<?php
/**
 * Value object describing an installment plan.
 *
 * @package GFSI
 */

declare( strict_types = 1 );

namespace GFSI;

defined( 'ABSPATH' ) || exit;

/**
 * An installment plan: a total amount split over N charges.
 *
 * All amounts are handled in the currency's smallest unit (cents for EUR/USD),
 * never in floats. This is the single most common source of bugs in installment
 * implementations: 100.00 / 3 in floating point does not give you three amounts
 * that add back up to 100.00.
 */
class Plan {

	/**
	 * Zero-decimal currencies, where the "smallest unit" is the whole unit.
	 *
	 * @var string[]
	 */
	const ZERO_DECIMAL_CURRENCIES = [
		'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga',
		'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf',
	];

	/**
	 * Total amount in the smallest currency unit.
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * Number of installments.
	 *
	 * @var int
	 */
	private int $count;

	/**
	 * Lowercase ISO currency code.
	 *
	 * @var string
	 */
	private string $currency;

	/**
	 * Billing interval: 'month' or 'week'.
	 *
	 * @var string
	 */
	private string $interval;

	/**
	 * Constructor.
	 *
	 * @param int    $total    Total in smallest currency unit.
	 * @param int    $count    Number of installments (>= 2).
	 * @param string $currency ISO currency code.
	 * @param string $interval 'month' or 'week'.
	 *
	 * @throws \InvalidArgumentException On invalid input.
	 */
	public function __construct( int $total, int $count, string $currency = 'eur', string $interval = 'month' ) {
		if ( $total <= 0 ) {
			throw new \InvalidArgumentException( 'Plan total must be greater than zero.' );
		}

		if ( $count < 2 ) {
			throw new \InvalidArgumentException( 'An installment plan needs at least 2 installments.' );
		}

		if ( ! in_array( $interval, [ 'week', 'month' ], true ) ) {
			throw new \InvalidArgumentException( 'Interval must be "week" or "month".' );
		}

		// Below one smallest-unit per installment, the split would produce
		// zero-amount invoices, which Stripe accepts and no one wants.
		if ( $total < $count ) {
			throw new \InvalidArgumentException(
				sprintf( 'Total (%d) is too small to split over %d installments.', $total, $count )
			);
		}

		$this->total    = $total;
		$this->count    = $count;
		$this->currency = strtolower( $currency );
		$this->interval = $interval;
	}

	/**
	 * Build a plan from a human-readable amount (e.g. "1250.50" or 1250.5).
	 *
	 * @param float|string $amount   Amount in major units.
	 * @param int          $count    Number of installments.
	 * @param string       $currency ISO currency code.
	 * @param string       $interval 'month' or 'week'.
	 */
	public static function from_decimal( $amount, int $count, string $currency = 'eur', string $interval = 'month' ): self {
		$currency = strtolower( $currency );
		$normalised = (float) str_replace( [ ' ', ',' ], [ '', '.' ], (string) $amount );

		$multiplier = in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		$total      = (int) round( $normalised * $multiplier );

		return new self( $total, $count, $currency, $interval );
	}

	/**
	 * The per-installment amounts, in the smallest currency unit.
	 *
	 * The indivisible remainder is spread one unit at a time across the EARLIEST
	 * installments, rather than dumped entirely on the first one. Two reasons:
	 * the amounts always sum back to the exact total, and no single installment
	 * is ever more than one cent above the others — so the customer never meets
	 * a payment that looks wrong.
	 *
	 * 100.00 EUR over 3  → [3334, 3333, 3333]
	 * 999.99 EUR over 7  → [14286, 14286, 14286, 14286, 14285, 14285, 14285]
	 *
	 * Loading the remainder onto the front rather than the back is deliberate:
	 * a final payment larger than the ones before it is what generates support
	 * emails.
	 *
	 * @return int[]
	 */
	public function installments(): array {
		$base      = intdiv( $this->total, $this->count );
		$remainder = $this->total % $this->count;

		$amounts = array_fill( 0, $this->count, $base );

		for ( $i = 0; $i < $remainder; $i++ ) {
			++$amounts[ $i ];
		}

		/**
		 * Filter the computed installment amounts.
		 *
		 * Use this to move the remainder to the last installment instead, or to
		 * implement an uneven schedule such as a larger deposit.
		 *
		 * @param int[] $amounts Amounts in the smallest currency unit.
		 * @param Plan  $plan    The plan being computed.
		 */
		$amounts = apply_filters( 'gfsi_installment_amounts', $amounts, $this );

		return array_values( array_map( 'intval', $amounts ) );
	}

	/**
	 * True when every installment is the same amount.
	 */
	public function is_even(): bool {
		return 0 === $this->total % $this->count;
	}

	/**
	 * Total in the smallest currency unit.
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * Number of installments.
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Lowercase currency code.
	 */
	public function currency(): string {
		return $this->currency;
	}

	/**
	 * Billing interval.
	 */
	public function interval(): string {
		return $this->interval;
	}

	/**
	 * Human-readable summary, e.g. "3 × 33,34 € (total 100,00 €)".
	 */
	public function describe(): string {
		$amounts   = $this->installments();
		$divisor   = in_array( $this->currency, self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		$formatter = static fn( int $cents ): string => number_format_i18n( $cents / $divisor, 1 === $divisor ? 0 : 2 );

		if ( $this->is_even() ) {
			return sprintf(
				/* translators: 1: number of installments, 2: amount per installment, 3: currency, 4: total */
				__( '%1$d × %2$s %3$s (total %4$s %3$s)', 'gf-stripe-installments' ),
				$this->count,
				$formatter( $amounts[0] ),
				strtoupper( $this->currency ),
				$formatter( $this->total )
			);
		}

		$high  = $amounts[0];
		$highs = count( array_filter( $amounts, static fn( int $a ): bool => $a === $high ) );
		$low   = min( $amounts );

		return sprintf(
			/* translators: 1: count of higher installments, 2: higher amount, 3: currency, 4: count of lower installments, 5: lower amount, 6: total */
			__( '%1$d × %2$s %3$s then %4$d × %5$s %3$s (total %6$s %3$s)', 'gf-stripe-installments' ),
			$highs,
			$formatter( $high ),
			strtoupper( $this->currency ),
			$this->count - $highs,
			$formatter( $low ),
			$formatter( $this->total )
		);
	}
}
