<!-- This file mirrors CLAUDE.md at the repo root. The root CLAUDE.md is the authoritative source — Claude Code auto-loads it. This copy exists for tools that look in .claude/ specifically. When updating, keep both in sync. -->

# Razorpay WooCommerce Plugin - Claude Code Context

## Project Overview

This is the official **Razorpay Payment Gateway** plugin for WooCommerce (WordPress). It integrates Razorpay's payment infrastructure into WooCommerce stores, supporting standard checkout, Magic Checkout (1CC - one-click checkout), subscriptions, refunds, Route transfers, and webhook-driven payment processing.

- **Plugin Version:** 4.8.3
- **WooCommerce Tested Up To:** 10.6.2
- **Primary Class:** `WC_Razorpay` (extends `WC_Payment_Gateway`)
- **Plugin ID:** `razorpay`
- **Entry Point:** `woo-razorpay.php`

---

## Architecture Summary

The plugin follows a WordPress plugin architecture built on top of WooCommerce's payment gateway abstraction.

### File Structure Map

```
woo-razorpay.php                   <- Main plugin, WC_Razorpay class
includes/
  razorpay-webhook.php             <- RZP_Webhook class
  razorpay-route.php               <- RZP_Route class, Route UI
  razorpay-route-actions.php       <- RZP_Route_Action, transfers
  razorpay-affordability-widget.php
  plugin-instrumentation.php       <- TrackPluginInstrumentation
  utils.php                        <- Helper functions
  state-map.php                    <- India state mappings
  debug.php                        <- rzpLogInfo/rzpLogError
  api/
    api.php                        <- REST route registration
    auth.php                       <- HMAC/credential auth
    order.php                      <- createWcOrder (1CC)
    cart.php                       <- fetchCartData/createCartData
    shipping-info.php              <- calculateShipping1cc
    coupon-apply.php               <- applyCouponOnCart
    coupon-get.php                 <- getCouponList
    giftcard-apply.php             <- validateGiftCardData
    prepay-cod.php                 <- prepayCODOrder
    save-abandonment-data.php
  cron/
    cron.php                       <- Cron helpers
    plugin-fetch.php               <- Plugin fetch cron
    one-click-checkout/
      Constants.php
      one-cc-address-sync.php
  support/
    abandoned-cart-hooks.php
    cartbounty.php
    smart-coupons.php
    wati.php
razorpay-sdk/                      <- Razorpay PHP SDK
checkout-block.php                 <- Gutenberg block support
script.js                          <- Frontend checkout JS
btn-1cc-checkout.js                <- Magic Checkout button JS
checkout_block.js                  <- Block checkout JS
templates/                         <- PHP templates
tests/                             <- PHPUnit tests
```

---

## Key Payment Flows

### 1. Standard Payment Flow
1. Customer selects Razorpay at WooCommerce checkout
2. `process_payment($order_id)` → sets transient, returns redirect to receipt page
3. `receipt_page($orderId)` → calls `generate_razorpay_form()`
4. `createOrGetRazorpayOrderId()` → creates Razorpay order via SDK
5. Form rendered, `checkout.js` loaded, Razorpay modal opens
6. Customer pays → POST to `woocommerce_api_razorpay` (callback URL)
7. `check_razorpay_response()` → `verifySignature()` → `updateOrder()`
8. Order marked as `payment_complete`, cart cleared

### 2. Magic Checkout (1CC) Flow
1. 1CC button shown via `btn-1cc-checkout.js`
2. REST: `POST /wp-json/1cc/v1/order/create` → `createWcOrder()`
3. WC order created in `checkout-draft` status with `is_magic_checkout_order=yes`
4. Razorpay order created with full `line_items` array
5. Customer completes payment in Razorpay hosted experience
6. Callback: `check_razorpay_response()` → `update1ccOrderWC()`
7. Shipping, COD fees, promotions, address synced from Razorpay to WC order

### 3. Webhook Flow (Async)
1. Razorpay POSTs to `admin-post.php?action=rzp_wc_webhook`
2. `RZP_Webhook::process()` → verifies HMAC signature
3. `payment.authorized` event → stored in `rzp_webhook_requests` table
4. Cron job later calls `paymentAuthorized()` → captures if needed → `updateOrder()`

### 4. Refund Flow
- WC admin triggers refund → `process_refund()` → Razorpay API refund
- OR: Razorpay Dashboard refund → `refund.created` webhook → `refundedCreated()` → `wc_create_refund()`

### 5. Route Transfer Flow
- `route_enable=yes` in settings
- Order creation includes `transfers` array from product meta
- Post-payment: `RZP_Route_Action::transferFromPayment()` called

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_rzp_webhook_requests` | Webhook event queue for async processing |
| `wp_options` | Plugin settings, webhook secret, flags |
| `wp_postmeta` / `wp_wc_orders_meta` | Per-order Razorpay IDs, flags |
| `wp_wc_orders` | HPOS order storage |

---

## WooCommerce Hooks

### Actions
- `plugins_loaded` - Plugin init
- `woocommerce_receipt_{id}` - Render payment form
- `woocommerce_api_{id}` - Payment callback
- `woocommerce_update_options_payment_gateways_{id}` - Settings save
- `admin_post_nopriv_rzp_wc_webhook` - Webhook handler
- `rest_api_init` - Register 1CC REST routes
- `wp_enqueue_scripts` - Enqueue checkout.js
- `woocommerce_blocks_loaded` - Gutenberg block
- `before_woocommerce_init` - HPOS + block compatibility declarations

### Filters
- `woocommerce_thankyou_order_received_text` - Custom thank you message
- `script_loader_tag` - Add defer to checkout.js
- `woocommerce_payment_gateways` - Register gateway

---

## REST API Endpoints (1CC)

Base path: `/wp-json/1cc/v1/`

| Method | Path | Handler | Auth |
|--------|------|---------|------|
| POST | `/coupon/list` | `getCouponList` | HMAC |
| POST | `/coupon/apply` | `applyCouponOnCart` | Credentials |
| POST | `/order/create` | `createWcOrder` | Credentials |
| POST | `/shipping/shipping-info` | `calculateShipping1cc` | Credentials |
| POST | `/abandoned-cart` | `saveCartAbandonmentData` | Credentials |
| POST | `/cart/fetch-cart` | `fetchCartData` | Credentials |
| POST | `/cart/create-cart` | `createCartData` | Credentials |
| POST | `/giftcard/apply` | `validateGiftCardData` | Credentials |
| POST | `/cod/order/prepay` | `prepayCODOrder` | Credentials |

---

## Plugin Settings (key: `woocommerce_razorpay_settings`)

| Key | Purpose |
|-----|---------|
| `enabled` | Enable/disable gateway |
| `key_id` | Razorpay API Key ID |
| `key_secret` | Razorpay API Key Secret |
| `payment_action` | `capture` or `authorize` |
| `route_enable` | Enable Route module |
| `enable_1cc` | Enable Magic Checkout |
| `enable_1cc_test_mode` | 1CC test mode |
| `enable_1cc_debug_mode` | Debug logging |
| `webhook_secret` | Webhook HMAC secret |

---

## Development Guidelines

### HPOS Compatibility Pattern
Always support both HPOS and legacy post meta:
```php
if ($this->isHposEnabled) {
    $value = $order->get_meta('meta_key');
    $order->update_meta_data('meta_key', $value);
    $order->save();
} else {
    $value = get_post_meta($orderId, 'meta_key', true);
    update_post_meta($orderId, 'meta_key', $value);
}
```

### Amount Handling
All amounts to Razorpay API must be in paise (smallest currency unit):
```php
$amount = (int) round($order->get_total() * 100);
```

### Logging
```php
rzpLogInfo("Message");   // Info level
rzpLogError("Message");  // Error level
rzpLogDebug("Message");  // Debug level (only when debug mode on)
```

### Error Handling
- `Errors\BadRequestError` - Safe to show to customer
- `Errors\SignatureVerificationError` - Security failure, log and reject
- Generic `Exception` - Log, return generic message to user

---

## Testing

```bash
composer install
./vendor/bin/phpunit --configuration phpunit.xml
```

### Key Test Scenarios
- Standard checkout payment success/failure
- Webhook signature verification
- Refund flow (WC-initiated and webhook-initiated)
- 1CC order creation and address sync
- HPOS vs legacy meta compatibility
- Concurrent payment prevention (transient locks)
