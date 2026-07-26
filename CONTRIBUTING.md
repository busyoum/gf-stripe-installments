# Contributing

Thanks for taking a look.

## Reporting a bug

Please include: WordPress version, PHP version, Gravity Forms version, whether
you are in Stripe test or live mode, and the relevant lines from the Gravity
Forms log (Forms → Settings → Logging).

**Never paste API keys, webhook secrets, full Stripe payloads or customer data
into an issue.** Redact identifiers down to their prefix (`sub_sched_1Ab…`).

## Pull requests

- Follow the [WordPress PHP coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
- Keep money in integers, in the currency's smallest unit. No floats in payment paths.
- Any change to the split arithmetic needs a case added to the rounding table in the README.
- Webhook handlers must stay idempotent: Stripe retries, and a retry must never
  create a second schedule or double-count a payment.
- One logical change per pull request.

## Testing against Stripe

Use test-mode keys and the [Stripe CLI](https://stripe.com/docs/stripe-cli) to
forward events to your local endpoint:

```
stripe listen --forward-to localhost/wp-json/gfsi/v1/webhook
```

Cases worth exercising before you open a PR: a total that does not divide evenly,
a card that fails on the second installment (`4000000000000341`), and a plan that
runs to completion.
