# Gravity Forms Stripe Installments

Charge a Gravity Forms total in a fixed number of installments — 3×, 4×, 10× — using Stripe **Subscription Schedules**, so the plan stops by itself after the last payment.

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

## Why this exists

The official Gravity Forms Stripe add-on handles two things well: one-off payments, and open-ended subscriptions. It does not handle *"pay in 3"*.

The usual workarounds are all bad in the same way:

- create a normal subscription and cancel it manually after N payments — someone forgets, and a customer pays a fourth time;
- create a subscription with `cancel_at` — closer, but the date drifts if a payment is retried;
- split the form into several payments — the customer can walk away after the first one;
- pay a third-party service a cut of every transaction.

A Stripe **Subscription Schedule** already does exactly this. A phase takes an `iterations` count and the schedule takes `end_behavior: cancel`, so Stripe stops on its own after the last installment. No cron job, no cleanup task, nothing on your side to remember. This plugin is a thin, well-behaved bridge between a Gravity Forms submission and that object.

## How it works

```
Form submitted
      │
      ▼
Plan computed from the entry (total, N, interval)
      │
      ▼
Stripe Checkout — mode: setup      ← no money moves yet
      │  card saved
      ▼
Webhook: checkout.session.completed
      │
      ▼
Subscription Schedule created      ← first installment charged now
   phases[0].iterations = N
   end_behavior = cancel
      │
      ▼
Stripe collects the rest, then closes the schedule
```

The card is collected **before** anything is charged. If the schedule cannot be created, no money has moved — which is the opposite of taking a first payment and then discovering the plan is broken.

## Installation

1. Download or clone into `wp-content/plugins/gf-stripe-installments`.
2. Activate the plugin.
3. Put your keys in `wp-config.php` (recommended — keys stay out of the database and out of content backups):

   ```php
   define( 'GFSI_STRIPE_SECRET_KEY', 'sk_live_…' );
   define( 'GFSI_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
   ```

   Or enter them under **Settings → Stripe Installments**.

4. In your Stripe dashboard, add a webhook endpoint pointing at the URL shown on that settings page:

   ```
   https://example.com/wp-json/gfsi/v1/webhook
   ```

   Subscribe it to: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `subscription_schedule.completed`.

5. On your form: **Form Settings → Stripe Installments**. Turn it on, pick the field holding the total, set the number of installments and the interval.

> **One endpoint, several events.** A Stripe account caps how many webhook endpoints you can register, and it is lower than people expect. This plugin claims exactly one and routes internally on event type. If you need to react to those events elsewhere, hook `gfsi_stripe_event` rather than registering a second endpoint.

## Rounding

Amounts are handled in the currency's smallest unit throughout — never floats. `100.00 / 3` in floating point does not give you three amounts that add back up to `100.00`.

The indivisible remainder is spread one unit at a time across the **earliest** installments, so no single payment is ever more than one cent above the others, and a customer never meets a final payment larger than the ones before it:

| Total | N | Installments | Sum |
|---|---|---|---|
| 100.00 € | 3 | 33.34 + 33.33 + 33.33 | 100.00 |
| 1250.00 € | 4 | 312.50 × 4 | 1250.00 |
| 999.99 € | 7 | 142.86 × 4 then 142.85 × 3 | 999.99 |
| 1000.00 € | 12 | 83.34 × 4 then 83.33 × 8 | 1000.00 |

Zero-decimal currencies (JPY, KRW, XOF…) are handled.

Consecutive equal amounts are collapsed into a single schedule phase with an `iterations` count, so even a 24× plan stays within Stripe's limit on phases per schedule. In practice a plan is never more than two phases.

To put the remainder on the last installment instead, or to build a deliberately uneven schedule such as a larger deposit, filter `gfsi_installment_amounts`.

A total too small to divide (less than one cent per installment) is rejected rather than turned into zero-amount invoices.

## Hooks

### Filters

| Filter | Purpose |
|---|---|
| `gfsi_plan` | Adjust or reject the plan before it reaches Stripe (vary N by product, enforce a minimum total). |
| `gfsi_installment_amounts` | Change how the total is split. |
| `gfsi_checkout_session_params` | Modify the Checkout Session parameters. |
| `gfsi_schedule_params` | Modify the Subscription Schedule parameters. |
| `gfsi_stripe_metadata` | Add your own metadata to every Stripe object created. |
| `gfsi_return_url` / `gfsi_cancel_url` | Control the post-Checkout redirects. |
| `gfsi_checkout_error_message` | Change what the user sees if Stripe is unreachable. |
| `gfsi_log_message` | Filter or suppress log lines. |

### Actions

| Action | Fires when |
|---|---|
| `gfsi_plan_started` | The schedule is live and the first installment is charged. |
| `gfsi_installment_paid` | An installment is collected. |
| `gfsi_installment_failed` | An installment fails. |
| `gfsi_plan_completed` | Every installment has been collected. |
| `gfsi_stripe_event` | Any verified Stripe event reaches the endpoint. |

### Example: vary installments by total

```php
add_filter( 'gfsi_plan', function ( $plan, $form, $entry ) {
    // Under 300 €, no installments.
    if ( $plan->total() < 30000 ) {
        throw new InvalidArgumentException( 'Total too low for installments.' );
    }

    // Over 2000 €, offer 10× instead of 3×.
    if ( $plan->total() > 200000 ) {
        return new GFSI\Plan( $plan->total(), 10, $plan->currency(), 'month' );
    }

    return $plan;
}, 10, 3 );
```

### Example: notify on a failed installment

```php
add_action( 'gfsi_installment_failed', function ( $invoice, $entry_id ) {
    wp_mail(
        get_option( 'admin_email' ),
        'Installment failed',
        sprintf( 'Entry %d — invoice %s', $entry_id, $invoice['id'] )
    );
}, 10, 2 );
```

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Gravity Forms 2.5+
- A Stripe account

No Composer dependencies. The Stripe REST calls go through the WordPress HTTP API rather than the official SDK, deliberately: several plugins each bundling their own copy of the Stripe SDK is a well-known way to produce a fatal "cannot redeclare class" error on a live site.

## Status

Early. The flow works, and the arithmetic and webhook signature verification are the parts I would trust first. Not yet battle-tested across many Stripe account configurations — **test in Stripe test mode before pointing it at live keys.**

Issues and pull requests welcome, especially around: Strong Customer Authentication edge cases on later installments, partial refunds mid-plan, and currencies I have not exercised.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright (C) 2026 Nawel Initiative.
