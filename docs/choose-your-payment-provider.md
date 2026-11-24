---
title: Choose how customers pay
tags: payments, provider, stripe, paypal, checkout, card payments, hosted payments, mals
page: admin/settings/payments
---

**Shop Payments** is where you choose which payment provider checkout uses. Payment providers are services you install from the Osmium Store, such as Stripe or PayPal.

## Pick the provider

1. Install a payment provider from the **Osmium Store** and set up its keys on its own settings page.
2. Open **Shop Payments** and choose it under **Active Checkout Provider**.
3. Press **Save Settings**.

If no provider is installed, checkout takes no payments, and the page tells you so. A warning appears if the provider you chose is not yet ready, for example because its keys are missing. Providers that are planned but not built yet are shown as **coming soon** and cannot be chosen.

## Hosted payments

If your site takes payment on a hosted page, such as a Mals basket, the page says so. There is no provider to choose, and you set it up on its own settings page.

Use each provider's test mode first. A test payment shows **Test payment** against the order, and no real money moves.
