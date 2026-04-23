# CLAUDE.md — Razorpay WooCommerce Plugin

## Build & Test Commands

```bash
# Install dev dependencies
composer install

# Run PHPUnit tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/SomeTest.php

# Install WordPress test environment
composer run wp-install
```

## Code Style

- PHP 7.4+ compatible (avoid PHP 8+ only features)
- Allman-style braces `{` on same line for control flow, new line for methods
- 4-space indentation
- Class constants: `UPPER_SNAKE_CASE`
- Methods: `camelCase`
- Global functions: `camelCase` (e.g., `rzpLogInfo`, `is1ccEnabled`)
- Always use `(int) round($amount * 100)` for currency amounts — never bare `* 100`

## Architecture Notes

### Critical Files

1. **`woo-razorpay.php`** — The entire `WC_Razorpay` class. Contains payment gateway registration, checkout form generation, callback handling, refund processing, and admin settings.

2. **`includes/razorpay-webhook.php`** — `RZP_Webhook` class. Handles all incoming webhooks. The `payment.authorized` handler saves to DB and defers to cron.

3. **`includes/api/api.php`** — REST endpoint registration for Magic Checkout (1CC) feature.

4. **`includes/api/order.php`** — `createWcOrder()` — the entry point for Magic Checkout order creation.

### HPOS Dual-Path (Important!)

Every place that reads/writes order metadata must handle both HPOS and classic:
```php
if ($this->isHposEnabled) {
    $order->update_meta_data('key', 'value');
    $order->save();
} else {
    update_post_meta($orderId, 'key', 'value');
}
```

Forgetting this breaks HPOS-enabled stores.

### Gateway ID

The gateway ID is `'razorpay'` (the `$this->id` property). This appears in:
- Hook names: `woocommerce_receipt_razorpay`, `woocommerce_api_razorpay`
- Settings key: `woocommerce_razorpay_settings`
- URL param: `?wc-api=razorpay`

### Session Key Pattern

Razorpay order ID is stored in order meta with a composite key:
- Standard: `razorpay_order_id{WC_ORDER_ID}` (e.g., `razorpay_order_id456`)
- 1CC: `razorpay_order_id_1cc{WC_ORDER_ID}` (e.g., `razorpay_order_id_1cc456`)

Always use `getOrderSessionKey($orderId)` which returns the correct key based on `is_magic_checkout_order` meta.

### Webhook Table

`{prefix}rzp_webhook_requests` tracks payment.authorized events:
- Inserted when Razorpay order is created (`status=0`)
- Updated to `status=1` when callback successfully processes the payment
- Cron queries `status=0` to find orders needing webhook processing

### 1CC Feature Flag

Magic Checkout features are behind `is1ccEnabled()`:
```php
function is1ccEnabled() {
    return 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc'];
}
```

Also gated by merchant having the feature: `$merchantPreferences['features']['one_click_checkout']`.

## Key Gotchas for Claude

1. **Do NOT use `$amount * 100`** — always `(int) round($amount * 100)` for currency
2. **Do NOT use `get_post_meta` alone** — always add HPOS path
3. **The `$hooks = false` constructor param** exists for subscriptions plugin compatibility — don't remove it
4. **`autoEnableWebhook()` blocks localhost** — don't test webhook registration on local without tunneling
5. **`exit`** is used intentionally in webhook handlers (virtual_account.credited, payment.pending) — this is standard WordPress pattern for early exits in POST handlers
6. **KWD/OMR/BHD currencies** are explicitly blocked — don't remove this check

## Where Things Are Configured

| Setting | Location |
|---|---|
| API keys (Key ID/Secret) | WC Admin > Payments > Razorpay > Settings |
| Webhook secret | Auto-generated, stored in `webhook_secret` option |
| Debug logging | Enable "debug mode" in plugin settings |
| 1CC features | Requires merchant feature flag from Razorpay |
| Route module | Only shown for INR currency stores |

## Running Locally

1. Install WordPress with WooCommerce
2. Copy plugin to `wp-content/plugins/razorpay-woocommerce/`
3. Activate plugin
4. Enter test Key ID (`rzp_test_...`) and Key Secret in settings
5. Use `ngrok http 80` for webhook testing (localhost is blocked)
6. Use Razorpay test cards: https://razorpay.com/docs/payments/testing/

## Relevant Context Files

- `.ai/context/CODEBASE_OVERVIEW.md` — Full architecture
- `.ai/context/PAYMENT_FLOW.md` — How payments work end-to-end
- `.ai/context/WEBHOOK_FLOW.md` — Webhook processing details
- `.ai/context/DATABASE_SCHEMA.md` — All meta keys and DB tables
- `.ai/context/WORDPRESS_HOOKS.md` — All hooks registered/fired
- `.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md` — Detailed checkout sequence
