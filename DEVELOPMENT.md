# Development notes

PrestaShop payment module for 1pay.cx. The shippable module is `onepaycx/` (its own
README explains installation and the payment flow); the rest of this folder is tooling.

```
onepaycx/          the module (zip this folder for merchants)
test/              stub-parent compile harness — run before every release
tools/make-logo.php  regenerates the placeholder onepaycx/logo.png with GD
```

## Checks (must pass before a release)

```bash
for f in $(find . -name '*.php'); do php -l "$f" >/dev/null || echo "LINT FAIL $f"; done
php test/compile-check.php
```

`test/compile-check.php` loads minimal stand-ins for the PrestaShop classes the module
touches (`PaymentModule`, `Module`, `ModuleFrontController`, `Configuration`, `Tools`,
`Context`, `Db`, `Order`, `Cart`, `Customer`, ...), requires the module and all three
front controllers, instantiates them, drives the hooks/controllers with fake requests
and checks the signature verification and idempotency logic. It exists because a
mismatch against a platform parent class (an abstract method we do not implement, a
property visibility we narrow) is an uncatchable compile-time fatal that white-screens a
live shop; the harness turns that into a failing command on this machine.

The stubs deliberately implement only the members the module calls; extending the module
to call a new PrestaShop API means adding that member to `test/stubs.php`.

## Packaging

```bash
zip -rq /tmp/onepaycx-prestashop.zip onepaycx -x '*.DS_Store' '*/.*'
```

The zip contains `onepaycx/` at its top level, as PrestaShop's module uploader expects.

## Contract this module relies on

- `POST https://api.1pay.cx/v1/payments` -> `{ id, checkoutUrl }`; `checkoutUrl` is a path
  on `https://1pay.cx`. `amount` is a JSON number (rounded to two decimals here),
  `currency` an upper-case ISO code, `reference` the cart id.
- `GET /v1/payments/{id}` -> `status` in `pending | paid | failed | expired`.
- `GET /v1/me` -> `{ id, email, webhookSecret }`.
- Notify: `POST notifyUrl` with `{ event, data, at }`; `X-1pay-Signature` is the hex
  HMAC-SHA256 of the raw body under `webhookSecret`. Only `payment.paid` is acted on.
