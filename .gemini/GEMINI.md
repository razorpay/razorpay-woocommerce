# Razorpay WooCommerce Plugin - Gemini Context

## Project Overview

This is the official Razorpay Payment Gateway plugin for WooCommerce (WordPress). Plugin version 4.8.3, tested with WooCommerce up to 10.6.2.

**Technology Stack:**
- Language: PHP 7.4+
- Framework: WordPress Plugin + WooCommerce Payment Gateway API
- Payment SDK: Razorpay PHP SDK (bundled in `razorpay-sdk/`)
- Frontend: Vanilla JS + Razorpay checkout.js CDN

---

## Project Architecture

The plugin is structured as a standard WordPress plugin that hooks into WooCommerce's payment gateway system.

### Core Components

**`woo-razorpay.php`** - Main entry point and primary class definition
- Defines `WC_Razorpay` class (extends `WC_Payment_Gateway`)
- Registers WordPress hooks
- Handles plugin initialization on `plugins_loaded`
- Manages webhook auto-registration with Razorpay API

**`includes/razorpay-webhook.php`** - `RZP_Webhook` class
- Processes incoming Razorpay webhooks
- Handles: `payment.authorized`, `refund.created`, `virtual_account.credited`, `payment.pending`, subscription events
- HMAC signature verification
- Async processing via `rzp_webhook_requests` DB table

**`includes/api/api.php`** - Magic Checkout (1CC) REST API
- Registers 9 REST endpoints under `/wp-json/1cc/v1/`
- Handles Magic Checkout flow: order creation, shipping, coupons, gift cards, COD

**`includes/razorpay-route.php`** - Route Module
- Admin UI for Razorpay Route (marketplace payments)
- Transfer management, reversals, settlements

**`includes/razorpay-route-actions.php`** - `RZP_Route_Action` class
- Business logic for Route transfers
- Direct transfers, payment transfers, reversals

---

## Payment Flows

### Standard Checkout Flow

```
Customer → WC Checkout → process_payment()
         → Redirect to receipt page
         → createOrGetRazorpayOrderId() → Razorpay API
         → Generate checkout form + checkout.js
         → Customer pays in Razorpay modal
         → POST callback to check_razorpay_response()
         → verifySignature() [HMAC-SHA256]
         → updateOrder() → WC order complete
```

### Magic Checkout (1CC) Flow

```
Customer → 1CC Button Click
         → POST /wp-json/1cc/v1/order/create → createWcOrder()
         → WC Order created (checkout-draft status)
         → POST /wp-json/1cc/v1/shipping/shipping-info → calculateShipping1cc()
         → Razorpay hosted checkout (address + payment)
         → Callback → check_razorpay_response()
         → update1ccOrderWC() → sync address/shipping/promotions
         → WC order complete
```

### Webhook Processing Flow

```
Razorpay → POST admin-post.php?action=rzp_wc_webhook
         → razorpay_webhook_init() → RZP_Webhook::process()
         → Verify HMAC signature (X-Razorpay-Signature header)
         → payment.authorized → saveWebhookEvent() → rzp_webhook_requests table
         → WP Cron → paymentAuthorized() → capture if needed → updateOrder()
```

### Refund Flow

```
WC Admin → process_refund($orderId, $amount, $reason)
         → Razorpay API: payment->fetch()->refund()
         → Order note added

OR:

Razorpay → refund.created webhook
         → refundedCreated() → wc_create_refund()
         → Skipped if notes.refund_from_website = true (avoid duplicate)
```

---

## File Structure

```
razorpay-woocommerce/
├── woo-razorpay.php              # Main plugin file
├── checkout-block.php            # Gutenberg blocks support
├── script.js                     # Frontend JS (checkout modal)
├── btn-1cc-checkout.js           # Magic Checkout button
├── checkout_block.js             # Block editor JS
├── composer.json
├── phpunit.xml
├── includes/
│   ├── razorpay-webhook.php      # Webhook handler
│   ├── razorpay-route.php        # Route UI (WP_List_Table)
│   ├── razorpay-route-actions.php # Route business logic
│   ├── razorpay-affordability-widget.php
│   ├── plugin-instrumentation.php # Analytics/tracking
│   ├── utils.php                 # is1ccEnabled(), isHposEnabled(), etc.
│   ├── state-map.php             # India state code mappings
│   ├── debug.php                 # Logging wrappers
│   ├── api/
│   │   ├── api.php               # REST endpoint registration
│   │   ├── auth.php              # HMAC auth, credential check
│   │   ├── order.php             # 1CC order creation
│   │   ├── cart.php              # Cart data APIs
│   │   ├── shipping-info.php     # Shipping calculation
│   │   ├── coupon-apply.php      # Coupon application
│   │   ├── coupon-get.php        # Coupon listing
│   │   ├── giftcard-apply.php    # Gift card validation
│   │   ├── prepay-cod.php        # COD pre-payment
│   │   └── save-abandonment-data.php
│   ├── cron/
│   │   ├── cron.php
│   │   ├── plugin-fetch.php
│   │   └── one-click-checkout/
│   │       ├── Constants.php
│   │       └── one-cc-address-sync.php
│   └── support/
│       ├── abandoned-cart-hooks.php
│       ├── cartbounty.php
│       ├── smart-coupons.php
│       └── wati.php
├── razorpay-sdk/                 # Bundled PHP SDK
├── templates/                    # PHP template files
└── tests/                        # PHPUnit tests
```

---

## Database Schema

### `wp_rzp_webhook_requests`
- `integration` - Always "woocommerce"
- `order_id` - WooCommerce order ID
- `rzp_order_id` - Razorpay order ID
- `rzp_webhook_data` - JSON array of webhook event data
- `rzp_update_order_cron_status` - 0=created, 1=processed by callback
- `rzp_webhook_notified_at` - Unix timestamp

---

## Key WooCommerce Hooks

| Hook | Handler | Purpose |
|------|---------|---------|
| `plugins_loaded` | `woocommerce_razorpay_init` | Gateway registration |
| `woocommerce_receipt_razorpay` | `receipt_page` | Payment form |
| `woocommerce_api_razorpay` | `check_razorpay_response` | Payment callback |
| `woocommerce_payment_gateways` | Anonymous | Gateway registration |
| `admin_post_nopriv_rzp_wc_webhook` | `razorpay_webhook_init` | Webhook processing |
| `rest_api_init` | `rzp1ccInitRestApi` | 1CC REST routes |

---

## Important Implementation Details

### Amount Conversion
All Razorpay amounts are in smallest currency unit (paise for INR):
```php
$amount = (int) round($order->get_total() * 100);
```

### HPOS Support
WooCommerce introduced High-Performance Order Storage (HPOS). Plugin checks `isHposEnabled` flag and uses appropriate storage:
- HPOS: `$order->get_meta()`, `$order->update_meta_data()`, `$order->save()`
- Legacy: `get_post_meta()`, `update_post_meta()`

### Signature Verification
Two HMAC verification points:
1. **Payment callback**: `api->utility->verifyPaymentSignature()` using `razorpay_order_id + | + razorpay_payment_id`
2. **Webhook**: `api->utility->verifyWebhookSignature()` using raw POST body

### 1CC Secret Management
A separate HMAC secret (`rzp1cc_hmac_secret`) is used for securing 1CC REST API calls. This secret is auto-registered with Razorpay via `POST magic/merchant/auth/secret` and refreshed when API keys change.

---

## Development Setup

1. Install WordPress + WooCommerce locally
2. Clone plugin to `wp-content/plugins/razorpay-woocommerce/`
3. Run `composer install` for PHP dependencies
4. Configure test API keys from Razorpay Dashboard
5. Run `./vendor/bin/phpunit --configuration phpunit.xml` for tests

---

## Coding Conventions

- Functions: `snake_case` (WordPress convention)
- Methods: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Always sanitize user input: `sanitize_text_field()`
- Always verify nonces for admin actions: `wp_verify_nonce()`
- Log with `rzpLogInfo()` / `rzpLogError()` (never raw `error_log()`)
