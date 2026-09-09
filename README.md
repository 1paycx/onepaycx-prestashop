# 1pay.cx for PrestaShop

Accept card, Bitcoin and Monero payments in PrestaShop 1.7.x and 8.x with
[1pay.cx](https://1pay.cx). Buyers pay on the hosted checkout; the order is confirmed
automatically by a signed webhook. Card sales settle to you in USDC on Polygon, crypto at a
flat 1%. No KYB, no rolling reserves, no chargebacks.

- **Version 1.0.0** for PrestaShop 1.7.0 to 8.x, PHP 7.4 to 8.1
- Licence: MIT (see `LICENSE`)

```
onepaycx/            the module (zip this folder, or download the ready-made archive)
test/                stub-parent compile harness, run before every release
tools/               developer tooling
DEVELOPMENT.md       release checks and packaging
```

## Install

1. Download https://1pay.cx/plugins/onepaycx-prestashop.zip (the archive contains
   `onepaycx/onepaycx.php` at its top level, as the module uploader expects).
2. Back office: **Modules → Module Manager → Upload a module**, pick the zip.
3. Configure: paste your API key (1pay.cx dashboard → **Settings → API keys**) and save.
   The module verifies the key and stores the webhook-signing secret it returns.
4. **Payment → Preferences**: make sure 1pay.cx is enabled for your currencies, countries
   and customer groups.

The module's own [README](onepaycx/README.md) explains the payment flow, the callback and
the return trip in detail.

## Support

- Documentation and every integration: https://1pay.cx/docs
- Service status: https://1pay.cx/status
- Contact: `hello@1pay.cx` on Jabber/XMPP, or the chat on any 1pay.cx page
