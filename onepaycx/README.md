# 1pay.cx for PrestaShop

Accept card, Bitcoin and Monero payments in PrestaShop 1.7.x and 8.x. Buyers pay on
the 1pay.cx hosted checkout; the order is confirmed automatically by a signed webhook.

- Version 1.1.0 — PHP 7.4 to 8.1, PrestaShop 1.7.0 to 8.x
- Licence: MIT (see `LICENSE`)
- All traffic goes to `https://api.1pay.cx` and `https://1pay.cx` only

## Install

1. Zip the `onepaycx` folder (the zip must contain `onepaycx/onepaycx.php` at its top
   level) or use the ready-made archive.
2. Back office -> Modules -> Module Manager -> Upload a module, pick the zip.
3. Configure: paste your API key (1pay.cx dashboard -> Settings -> API keys, looks like
   `pk_xxx.yyy`) and Save. The module calls `GET /v1/me` to verify the key and stores
   the webhook-signing secret it returns. Nothing else to set up.
4. Payment -> Preferences: make sure 1pay.cx is enabled for your currencies, countries
   and customer groups.

The PHP cURL extension must be enabled (it is on every standard PrestaShop host).

## How it works

| Step | Where | What happens |
| --- | --- | --- |
| Checkout | `hookPaymentOptions` | Shows "Pay by card or crypto" (title configurable). |
| Pay | `controllers/front/redirect.php` | `POST /v1/payments` with the cart total (two decimals), currency ISO code, customer email, `reference` = cart id, `notifyUrl` and `redirectUrl` pointing back to this shop; the buyer is sent to `https://1pay.cx{checkoutUrl}`. No order exists yet. |
| Paid | `controllers/front/webhook.php` | 1pay.cx POSTs `{ event: "payment.paid", data, at }`. The `X-1pay-Signature-V2` header (`t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`, within 300 s) is checked with `hash_equals` when present, else the legacy `X-1pay-Signature` (hex HMAC-SHA256 of the raw body). The payment id must be one stored for that cart, amount/currency must cover the stored quote (0.01 tolerance), `livemode:false` is refused under a live key, and `GET /v1/payments/{id}` with the configured key must confirm `paid` with matching amount/currency. The cart named by `data.reference` becomes an order in state *Payment accepted* via `validateOrder`. |
| Return | `controllers/front/return.php` | The buyer lands here after paying. If the order already exists they go to the order-confirmation page; otherwise the module polls `GET /v1/payments/{id}` — `paid` creates the order right there, `pending` shows an auto-refreshing wait page, `failed` / `expired` offers a retry. |
| Confirmation | `hookDisplayPaymentReturn` | Small "payment received" block on the standard order-confirmation page. |

### Reliability notes

- Webhooks are at-least-once. A cart that already has an order answers `200 already paid`
  and nothing is touched, so duplicate deliveries are harmless.
- Notifies for a payment this shop did not create for that cart are rejected (404).
- The order is always validated with the total this shop quoted when the payment was
  started (stored per payment). 1pay.cx may report a higher amount — a merchant-side
  customer surcharge is added to the buyer's invoice — and that is fine; a *lower*
  reported amount is rejected (400 on the webhook, error state on the return page) and
  no order is created. `validateOrder` still compares the amount with the cart total
  itself, so a cart edited after the link was created ends up under *Payment error*.
- A buyer who restarts checkout gets a new link while the earlier one stays payable for
  up to 24 hours. Every link is stored, so whichever one they pay completes the order:
  the webhook matches on the payment id, and the return page polls every link still
  pending for the cart.
- The webhook and the buyer's return page race right after payment. Order creation is
  serialised per cart with a MySQL advisory lock (`GET_LOCK`), `validateOrder` failures
  and exceptions are caught, and a second look for an order created meanwhile means both
  parties end on the confirmation page instead of one of them erroring.
- The checkout title is display-only and validated like PrestaShop's own names (no
  `< > = { }`, 255 characters). `Order::$payment` is always the fixed string `1pay.cx`.
- The notify is delivered once, without retries. If it cannot reach the shop (maintenance
  mode with the sender's IP not allow-listed, a firewall, ...) the return page's polling
  still completes the order the moment the buyer comes back.
- The API key and webhook secret live in `Configuration` (`ONEPAYCX_API_KEY`,
  `ONEPAYCX_WEBHOOK_SECRET`) and are never logged or echoed back into a page.

### Data stored

One table `PREFIX_onepaycx_payment`, one row per payment started (`id_payment` primary
key, `id_cart`, the quoted amount, currency, status). It is dropped on uninstall together
with the module's configuration keys; orders are of course kept.

## Compatibility testing

Any class that extends a PrestaShop base class can fatal at *compile* time if the parent
contract changes (abstract method added, property visibility narrowed). Before shipping,
always run the stub-parent harness from the platform folder:

```bash
php -l onepaycx/onepaycx.php   # and every other .php file
php test/compile-check.php
```

## Changelog

### 1.1.0

- Webhook hardening: timestamped `X-1pay-Signature-V2` (5-minute window) with legacy
  fallback, notify amount and currency required and matched to the stored quote, test payments
  refused under a live key, every paid notify re-confirmed with `GET /v1/payments/{id}`.

### 1.0.0

- Initial release: hosted checkout redirect, signed webhook order confirmation, polling
  return page, configuration form with API-key verification.
