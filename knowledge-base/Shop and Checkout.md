# Shop and checkout

How a product reaches a customer and how an order is made. Part of [[Osmium Architecture]]. The shop is two modules:
`shop` (catalogue, search, product and category pages) and `checkout` (baskets, totals, orders, payment). The page folders
`basket` and `thank-you` hold the theme's views for those steps. Read from `app/modules/shop/` and
`app/modules/checkout/`.

## The shop is a service

There is no shop switch. The shop is the **Osmium Shop service** (`osmium-shop`, public repo in the Osmium-Services org);
a site sells products when that service is installed and enabled. Core holds no shop code: no routes, admin
screens, finance code, emails or tables (core's first migration creates only the tables every site needs). The service ships all of it and draws the red **Shop** group in the admin sidebar.

- Installing it creates the four pages it owns (`shop`, `basket`, `checkout`, `thank-you`); disabling it bins them and
  enabling restores them; removing it takes its routes and menu away. Tables, products, orders and settings are never touched.
- The service plugs into core through hooks: `pages.owners`, `pages.reserved_ids`, `pages.delete_blockers`, `migrations.actions`, `dashboard.quick_links`,
  `setup.checklist`, `pages.protected_urls`, `mcp.tools`, `email.samples`, `dashboard.widgets` and the lifecycle events
  (see [[Hooks Reference]]).
- It also answers three read-only lookups for other services, so they never call its PHP: `shop.product.get` (one live product: id, brand,
  name, reference, ex-tax price, availability), `shop.brands` (the live brands) and `shop.product.files` (products with documents and
  images on disk, with their category path). Trade Ordering uses the first two, Trade Downloads the third. The shop stays trade-free: the
  old per-brand `trade_discount_allowed` column was dropped (migration 011, v0.38.0) and Trade Ordering keeps its own brand exclusions.
- The service declares its boot class in `service.json` (`"boot": {"OsmiumShop": "shop"}`: class name => module folder), so core boots it
  only while the service is enabled and the base's `modules.json.php` names no shop. A site's older `modules.json.php` shop entry still works. Services that only make sense on a shop (payments, stock, accounting) declare `"requires": {"services":
  ["osmium-shop"]}`, so installing one installs the Shop first and the Shop cannot be uninstalled while they remain.
- A site's own copy of a storefront controller or view wins over the service's, which is the fallback. Files deleted from a
  site's repo are **not** deleted by the deploy: remove them on the server by hand (this took staging down on 2026-10-19).

## The market and the money

Osmium is **UK-only** for now: one currency, one tax rate, one country, taken from Shop settings by `StoreFinance`
(`StoreFinance::forOsmium($osmium)`; it throws if a setting is missing, so a misconfigured shop fails loudly). Views read currency
and VAT from `$shop['finance']` (`->format(12.5)` gives "£12.50"). There is no multi-currency or multi-country code and none
is planned.

## Catalogue pages (`shop` module)

`OsmiumShop` (booted from the manifest `boot` key) works out whether the page is a category or a product from the page row
and the `products_pages` / category mapping, loads the model, runs `shop/controllers/category.php` or `product-full.php`,
and sets `pageContent` to `shop/views/category.phtml` or `product.phtml`. A product's QR page is the same page ending `/qr/`.

- **Product page data** is built by `ShopProductService`, `ShopProductPageData` and `ShopProductCard` (prices, savings, flags
  shared by every listing). Services add keys through the `product.page.data` hook (reviews, restock state); core's own keys
  are never replaced and the first service to set a key keeps it.
- **Options and kits.** A product can have option types and choices, optionally linked to other products (a kit's parts).
  `ShopKitAvailability` decides whether a kit can be bought and when it is back from the stock status of the linked
  products: a type that is always shown and has no choice left blocks the kit, a type that only appears after another
  choice never does. `restock_at` dates in the past are not promised.
- **Search** is `api/search` (rate limited, 60-second result cache, `ShopSearchService` for query parsing, synonyms from
  the `search.synonyms` hook and ranking). It fires `search.performed` after each search.
- **Featured and new arrivals** are plain include controllers (`featured-products.php`, `new-arrivals.php`) a theme pulls
  into a page; featured products come from the Featured Products service through the `featured.products` hook.
- `ai-product-spec.php` serves `api/ai/products/<slug>.md|json`, a machine-readable product description.

## Baskets

`api/checkout-basket` actions: `get`, `add`, `update_quantity`, `remove`, `apply_coupon`, `remove_coupon`. The basket is a
row in `checkout_baskets` keyed by the **PHP session id** (there is no basket cookie). Before an add or a quantity rise the
`basket.line.check` hook can refuse (stock control), and `order.check` can refuse the whole basket at checkout. A coupon
code is kept in the session (`applied_coupon_code`).

## Totals (`ShopTotalsService`)

Every figure is **recomputed on the server from the database basket**; nothing monetary is read from a submitted form.

1. Resolve the discounts: a **sitewide** discount wins; otherwise a **coupon**; otherwise **per-product** discounts. One
   discount applies per line.
2. Per line: discount first, then quantity, rounded to pence; VAT is taken **per unit and multiplied by quantity** (not on
   the line total), because PayPal requires the tax total to equal the sum of unit tax times quantity exactly.
3. Delivery from the postcode: zones are mainland, highlands (a surcharge) and excluded (cannot deliver), plus surcharge
   postcodes, a flat charge and an optional free-delivery threshold, all from `config/modules/checkout.json.php`.
4. Discounts apply only when the selected payment provider supports them (`PaymentProviders::supportsDiscounts()`:
   anything but `none`).

## Orders (`ShopOrderService`)

- **The order row is written before payment is attempted**, as `pending`. An order that only exists on success cannot
  record a payment that succeeded while the browser died.
- **Payment is provider-agnostic.** Core has no built-in provider; Stripe and PayPal (and Square) are services answering
  `payment.providers`, `payment.create` and `payment.confirm`. The admin chooses one in Settings > Payments, and only that
  one may take money. The default is `none` (no money taken), also the fallback if the chosen service is uninstalled.
- **Flow:** the browser calls `create_order` (validate the form again, create the pending order, `payment.create`, store the
  provider reference and the order id in the session), the customer pays, then `capture_order` (checks the session
  reference, then `payment.confirm`, validates amount and currency against the stored order, then `markPaid`). Provider
  webhooks reconcile the same way.
- **`markPaid` is idempotent.** It reads the status under the order row's lock, so a double click, a retried capture and a
  late webhook resolve to one payment, one invoice number and one confirmation email. It also records a coupon redemption.
  It then fires `order.paid` (the `order.created` hook fires when the pending order is written).
- **Statuses:** `pending`, `paid`, `dispatched`, `failed`, `cancelled`, `refunded`. Only legal moves happen: `failed` and
  `cancelled` from `pending`, `refunded` from `paid` or `dispatched`; an out-of-order event (a `failed` webhook after the
  capture) changes nothing and is not an error. An admin marks an order dispatched.
- **Order numbers:** `SG2-` plus a counter from 1001 (the prefix is a legacy name hard-coded in `ShopOrderService`).
- **Mail and texts:** `ShopOrderMailer` sends the confirmation (templates in `checkout/templates/email`).
  `CustomerNotifications` holds a settings matrix (event by email or SMS; events `order_paid` and `order_dispatched`);
  SMS goes through the `sms.send` hook to whichever SMS service is installed (only one at a time). Mail transport is SMTP
  or a mail-provider service (`mail.providers`), falling back to SMTP.
- **PDFs:** `ShopOrderPdfService` (TCPDF) renders invoices and despatch notes.
- **Accounting and fulfilment** are services reacting to `order.panels` and `order.action` (Xero, QuickBooks, Sage,
  FreeAgent push an invoice from the admin order page) and `order.paid` (Meta Pixel's server-side Purchase).

## Admin side

Products (with price CSV import and a price history), categories, brands, statuses, discounts, orders, baskets: controllers
under `admin/controllers/`. Product saves fire `product.saved` / `product.removed` (Google Merchant Center syncs on them).
See [[Admin Framework]].

## What stays in core, and why

Pricing, discounts, orders and payment state change money, so they are not behind hooks that fail silently
([[Core and Services]]).

## Not verified

Read from the code, not traced end to end in a browser here. The `thank-you` and `basket` page views and the exact
webhook paths per provider live in the theme and in each provider service. The kit and option-link rules were read from
`ShopKitAvailability` and its tests, not exercised on a live product.
