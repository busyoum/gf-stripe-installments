=== Gravity Forms Stripe Installments ===
Contributors: busyoum
Tags: gravity forms, stripe, installments, payment plan, pay in 3
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Charge a Gravity Forms total in a fixed number of installments using Stripe Subscription Schedules. Stops automatically after the last payment.

== Description ==

The official Gravity Forms Stripe add-on covers one-off payments and open-ended subscriptions, but not "pay in 3".

This plugin uses Stripe Subscription Schedules, where a phase takes an iteration count and the schedule closes itself afterwards. There is no cron job to run and nothing to cancel manually — Stripe stops after the last installment on its own.

Amounts are handled in the currency's smallest unit throughout, never as floats, and any indivisible remainder is placed on the first installment so the customer never meets a larger final payment.

The card is collected through Stripe Checkout in setup mode before anything is charged, so a failure to set up the plan cannot leave a customer charged for a plan that does not exist.

= Features =

* Any number of installments, monthly or weekly
* Correct rounding, including zero-decimal currencies
* One webhook endpoint, routed internally by event type
* Signature verification on every incoming event
* Idempotent — Stripe retries cannot create duplicate plans
* Filters and actions for plans, amounts, metadata and payment events
* No Composer dependencies and no bundled Stripe SDK

== Installation ==

1. Upload to `/wp-content/plugins/gf-stripe-installments` and activate.
2. Define your keys in wp-config.php, or enter them under Settings > Stripe Installments.
3. Register the webhook URL shown on the settings page in your Stripe dashboard.
4. Enable installments on a form under Form Settings > Stripe Installments.

== Frequently Asked Questions ==

= Does this need the official Gravity Forms Stripe add-on? =

No. It talks to Stripe directly and can run alongside the official add-on.

= What happens if an installment fails? =

Stripe retries according to your dunning settings. The `gfsi_installment_failed` action fires so you can notify someone.

= Can I offer a different number of installments per product? =

Yes, with the `gfsi_plan` filter. See the README on GitHub.

== Changelog ==

= 0.1.0 =
* Initial release.
