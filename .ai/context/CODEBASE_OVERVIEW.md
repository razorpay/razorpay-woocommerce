# Codebase Overview — Razorpay WooCommerce Plugin

## Plugin Identity

- **Name:** 1 Razorpay (prefixed "1" so it appears first in the gateway list)
- **Version:** 4.8.3
- **Entry Point:** `woo-razorpay.php`
- **WC Compatibility:** Tested up to WooCommerce 10.6.2
- **License:** GPL-2.0-or-later

## Architecture Summary

The plugin is a **WooCommerce payment gateway** that extends `WC_Payment_Gateway`. It is structured around one large main class (`WC_Razorpay`) with satellite helper classes and REST API endpoints for the Magic Checkout (1CC — One Click Checkout) feature.

### High-Level Components

| Component | File(s) | Responsibility |
|---|---|---|
| Main Gateway Class | `woo-razorpay.php` | WC integration, payment flow, order management |
| Webhook Handler | `includes/razorpay-webhook.php` | Receive & process Razorpay webhooks |
| Route Module | `includes/razorpay-route.php`, `includes/razorpay-route-actions.php` | Linked account transfers (Razorpay Route) |
| 1CC REST API | `includes/api/*.php` | Magic Checkout internal REST endpoints |
| Plugin Instrumentation | `includes/plugin-instrumentation.php` | Analytics tracking (Segment + DataLake) |
| Affordability Widget | `includes/razorpay-affordability-widget.php` | EMI/offer display widget on product pages |
| Cron Jobs | `includes/cron/` | Address sync, plugin fetch for Magic Checkout |
| Debug/Logging | `includes/debug.php` | WC logger wrapper |
| State Map | `includes/state-map.php` | Indian state name → WC state code mapping |
| Utils | `includes/utils.php` | Feature-flag helpers (`is1ccEnabled()`, etc.) |
| Blocks Integration | `checkout-block.php` | WooCommerce Blocks / Gutenberg checkout support |
| Razorpay PHP SDK | `razorpay-sdk/` | Official Razorpay PHP SDK (vendored) |

## File Structure

```
razorpay-woocommerce/
├── woo-razorpay.php              # Main plugin file; class WC_Razorpay defined here
├── checkout-block.php            # WC_Razorpay_Blocks for Gutenberg checkout
├── checkout_block.js             # JS for WC Blocks payment registration
├── script.js                     # Frontend JS (opens Razorpay checkout modal)
├── btn-1cc-checkout.js           # Magic Checkout button JS logic
├── composer.json                 # Dev dependencies (PHPUnit, mockery)
├── phpunit.xml                   # PHPUnit configuration
├── readme.txt                    # WordPress.org plugin readme
├── release.sh                    # Release script
├── includes/
│   ├── api/
│   │   ├── api.php               # REST route registration (1CC endpoints)
│   │   ├── auth.php              # Auth callbacks: checkAuthCredentials, checkHmacSignature
│   │   ├── order.php             # createWcOrder REST handler
│   │   ├── shipping-info.php     # calculateShipping1cc REST handler
│   │   ├── coupon-get.php        # getCouponList REST handler
│   │   ├── coupon-apply.php      # applyCouponOnCart REST handler
│   │   ├── cart.php              # fetchCartData, createCartData handlers
│   │   ├── giftcard-apply.php    # validateGiftCardData REST handler
│   │   ├── prepay-cod.php        # prepayCODOrder REST handler
│   │   └── save-abandonment-data.php # saveCartAbandonmentData handler
│   ├── cron/
│   │   ├── cron.php              # Generic cron create/delete helpers
│   │   ├── plugin-fetch.php      # Cron to sync plugin list for Magic Checkout
│   │   └── one-click-checkout/
│   │       ├── Constants.php     # Cron and status constants
│   │       └── one-cc-address-sync.php # Address sync cron job
│   ├── support/
│   │   ├── cartbounty.php        # Integration with CartBounty abandoned cart plugin
│   │   ├── wati.php              # Integration with WATI WhatsApp plugin
│   │   └── smart-coupons.php     # Integration with Smart Coupons plugin
│   ├── debug.php                 # rzpLog*() logging wrappers
│   ├── plugin-instrumentation.php # TrackPluginInstrumentation class
│   ├── razorpay-affordability-widget.php # EMI widget rendering
│   ├── razorpay-route.php        # RZP_Route WP_List_Table + admin pages
│   ├── razorpay-route-actions.php # RZP_Route_Action: transfer operations
│   ├── razorpay-webhook.php      # RZP_Webhook class
│   ├── state-map.php             # Indian state mapping functions
│   └── utils.php                 # Feature flag helpers + input validation
├── templates/
│   ├── rzp-cart-checkout-btn.php # Cart page Magic Checkout button template
│   ├── rzp-mini-checkout-btn.php # Mini-cart Magic Checkout button template
│   ├── rzp-pdp-checkout-btn.php  # Product page Magic Checkout button template
│   └── rzp-spinner.php           # Loading spinner template
├── public/
│   ├── css/                      # Admin/frontend stylesheets
│   ├── images/                   # Plugin images
│   ├── js/                       # Admin JS for Route module
│   └── phpunit/                  # PHPUnit bootstrap
├── razorpay-sdk/                 # Vendored Razorpay PHP SDK
└── tests/                        # PHPUnit test files
```

## Key Classes

### `WC_Razorpay` (woo-razorpay.php)

Extends `WC_Payment_Gateway`. The heart of the plugin.

**Key Constants:**

| Constant | Value | Purpose |
|---|---|---|
| `SESSION_KEY` | `razorpay_wc_order_id` | Transient key for WC order id |
| `RAZORPAY_PAYMENT_ID` | `razorpay_payment_id` | POST param from callback |
| `RAZORPAY_ORDER_ID` | `razorpay_order_id` | Standard order session key prefix |
| `RAZORPAY_ORDER_ID_1CC` | `razorpay_order_id_1cc` | Magic Checkout order session key prefix |
| `RAZORPAY_SIGNATURE` | `razorpay_signature` | POST param from callback |
| `CAPTURE` | `capture` | Auto-capture payment action |
| `AUTHORIZE` | `authorize` | Authorize-only payment action |
| `INR` | `INR` | Default currency |
| `RZP_ORDER_CREATED` | `0` | Initial webhook table status |
| `RZP_ORDER_PROCESSED_BY_CALLBACK` | `1` | Callback-processed status |

**Key Methods:**

| Method | Purpose |
|---|---|
| `__construct($hooks=true)` | Init settings, check HPOS, load 1CC merchant preferences |
| `initHooks()` | Register all WP/WC action/filter hooks |
| `process_payment($order_id)` | WC hook: redirect to receipt page |
| `receipt_page($orderId)` | Render payment form / open Razorpay modal |
| `generate_razorpay_form($orderId)` | Build checkout args and render form |
| `createOrGetRazorpayOrderId($order, $orderId, $is1cc)` | Create or retrieve Razorpay order |
| `createRazorpayOrderId($orderId, $sessionKey)` | Call Razorpay Orders API, save result |
| `verifyOrderAmount($rzpOrderId, $orderId)` | Compare local vs. API order amounts |
| `check_razorpay_response()` | Handle POST callback after payment |
| `verifySignature($orderId)` | HMAC signature verification |
| `updateOrder($order, $success, ...)` | Set WC order status after payment |
| `process_refund($orderId, $amount, $reason)` | Initiate refund via Razorpay API |
| `autoEnableWebhook()` | Auto-create/update webhook on settings save |
| `getRazorpayApiInstance($key, $secret)` | Create `Razorpay\Api\Api` instance |

### `RZP_Webhook` (includes/razorpay-webhook.php)

Standalone class instantiated via `razorpay_webhook_init()` action on `admin_post_nopriv_rzp_wc_webhook`.

**Supported Events:**

- `payment.authorized` → saved to `rzp_webhook_requests` table; processed by cron
- `payment.pending` → handles COD order status update
- `payment.failed` → no-op (order status managed by callback)
- `refund.created` → adds order note
- `virtual_account.credited` → captures payment for virtual account payments
- `subscription.cancelled` / `subscription.paused` / `subscription.resumed` / `subscription.charged` → base no-ops (overridden in subscriptions plugin)

### `TrackPluginInstrumentation` (includes/plugin-instrumentation.php)

Analytics wrapper. Sends events to:
- **Razorpay Segment** via `POST /plugins/segment` API
- **Lumberjack DataLake** via `POST https://lumberjack.razorpay.com/v1/track`

### `WC_Razorpay_Blocks` (checkout-block.php)

Implements `AbstractPaymentMethodType` for WooCommerce Blocks (Gutenberg) checkout compatibility.

### `RZP_Route` / `RZP_Route_Action` (includes/razorpay-route.php/.php)

Admin UI (`WP_List_Table`) and action handlers for Razorpay Route (linked account payments / marketplace transfers).

## Settings Stored

All settings are stored in WordPress options under key `woocommerce_razorpay_settings` as an associative array.

**Core Fields:**

| Key | Type | Description |
|---|---|---|
| `enabled` | checkbox | Enable/disable gateway |
| `title` | text | Title shown at checkout |
| `description` | textarea | Description shown at checkout |
| `key_id` | text | Razorpay API Key ID |
| `key_secret` | text | Razorpay API Key Secret |
| `payment_action` | select | `capture` or `authorize` |
| `order_success_message` | textarea | Thank-you page message |
| `enable_1cc_debug_mode` | checkbox | Enable WC debug logging |
| `route_enable` | checkbox | Enable Route module |
| `webhook_secret` | text | Webhook HMAC secret (auto-generated) |

**Magic Checkout (1CC) Fields** (only shown if merchant has feature):

| Key | Default | Description |
|---|---|---|
| `enable_1cc` | no | Enable Magic Checkout |
| `enable_1cc_test_mode` | no | Only admins see 1CC button |
| `enable_1cc_pdp_checkout` | yes | Buy Now button on product pages |
| `enable_1cc_mini_cart_checkout` | yes | Mini-cart checkout button |
| `enable_1cc_ga_analytics` | no | Google Analytics tracking |
| `enable_1cc_fb_analytics` | no | Facebook Pixel tracking |
| `1cc_min_cart_amount` | 0 | Minimum cart value for 1CC |
| `1cc_min_COD_slab_amount` | 0 | Minimum amount for COD |
| `1cc_max_COD_slab_amount` | 0 | Maximum amount for COD |
| `1cc_account_creation` | No | Allow new user account creation |

## HPOS (High Performance Order Storage) Support

The plugin checks `OrderUtil::custom_orders_table_usage_is_enabled()` and uses either:
- `$order->get_meta()` / `$order->update_meta_data()` for HPOS
- `get_post_meta()` / `update_post_meta()` for classic WP posts

This dual-path pattern appears throughout the codebase.

## Third-Party Plugin Integrations

| Plugin | Integration Point |
|---|---|
| CartBounty (`woo-save-abandoned-carts`) | Mark recovered carts in `handleCBRecoveredOrder()` |
| WATI (`wati-chat-and-notification`) | Mark recovered orders in `handleWatiRecoveredOrder()` |
| Smart Coupons | Coupon handling in 1CC flow |
| YITH Gift Cards (`yith-woocommerce-gift-cards`) | Gift card balance deduction |
| PW Gift Cards (`pw-woocommerce-gift-cards`) | Gift card balance deduction |
| Woo Wallet (`woo-wallet`) | Terra Wallet partial payment |
| WooCommerce Abandoned Cart Lite | `updateRecoverCartInfo()` |
| YWDPD Dynamic Pricing | Disable coupon flag |
| Pixel Your Site PRO | UTM data capture on order |
| WCFM Marketplace | Multi-vendor shipping |
