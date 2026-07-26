# Changelog

All notable changes to this project are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-07-26

Initial public release.

### Added
- Installment plans on Gravity Forms submissions via Stripe Subscription Schedules.
- Integer-only amount arithmetic, with the indivisible remainder placed on the first installment.
- Zero-decimal currency support.
- Stripe Checkout in `setup` mode: the card is collected before any charge is made.
- Single webhook endpoint routing internally on event type.
- Webhook signature verification without the Stripe SDK.
- Idempotent schedule creation, so Stripe retries cannot produce duplicate plans.
- Per-form settings under Form Settings → Stripe Installments.
- Filters and actions for plans, amounts, Stripe parameters, metadata and payment events.
