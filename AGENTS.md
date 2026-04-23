# AGENTS.md - Universal AI Agent Context for Razorpay WooCommerce Plugin

> This file provides complete context for AI coding assistants (Claude, Gemini, Kimi, GPT-4, Copilot, etc.) working on the Razorpay WooCommerce plugin.

---

## Project Identity

| Attribute | Value |
|-----------|-------|
| Plugin Name | Razorpay Payment Gateway for WooCommerce |
| Version | 4.8.3 |
| Language | PHP 7.4+ |
| Platform | WordPress + WooCommerce |
| Entry Point | `woo-razorpay.php` |
| Primary Class | `WC_Razorpay` (extends `WC_Payment_Gateway`) |
| WC Tested Up To | 10.6.2 |

---

## Complete File Structure

```
razorpay-woocommerce/
├── woo-razorpay.php                    # Main plugin file + WC_Razorpay class
├── checkout-block.php                  # Gutenberg block integration (WC_Razorpay_Blocks)
├── script.js                           # Frontend checkout JS - opens Razorpay modal
├── btn-1cc-checkout.js                 # Magic Checkout (1CC) button JS
├── checkout_block.js                   # Block-based checkout JS
├── composer.json                       # PHP dependencies
├── composer.wp-install.json            # WP-specific composer config
├── phpunit.xml                         # PHPUnit configuration
├── debug.md                            # Debug notes
├── readme.txt                          # WordPress plugin readme
├── release.sh                          # Release script
├── public/                             # Public assets (CSS/images)
├── templates/                          # PHP template files
├── tests/                              # PHPUnit test files
├── razorpay-sdk/                       # Bundled Razorpay PHP SDK
│   ├── Razorpay.php                    # SDK entry point
│   └── libs/Requests-2.0.4/           # HTTP library
└── includes/
    ├── razorpay-webhook.php            # RZP_Webhook class
    ├── razorpay-route.php              # RZP_Route class (admin UI)
    ├── razorpay-route-actions.php      # RZP_Route_Action class
    ├── razorpay-affordability-widget.php # EMI widget
    ├── plugin-instrumentation.php      # TrackPluginInstrumentation
    ├── utils.php                       # Global helper functions
    ├── state-map.php                   # India state code mappings
    ├── debug.php                       # rzpLogInfo/rzpLogError/rzpLogDebug
    ├── api/
    │   ├── api.php                     # REST route registration
    │   ├── auth.php                    # Authentication callbacks
    │   ├── order.php                   # 1CC WC order creation
    │   ├── cart.php                    # Cart data management
    │   ├── shipping-info.php           # Shipping calculation
    │   ├── coupon-apply.php            # Coupon application
    │   ├── coupon-get.php              # Coupon listing
    │   ├── giftcard-apply.php          # Gift card validation
    │   ├── prepay-cod.php              # COD pre-payment conversion
    │   └── save-abandonment-data.php   # Cart abandonment tracking
    ├── cron/
    │   ├── cron.php                    # createCron/deleteCron helpers
    │   ├── plugin-fetch.php            # Merchant plugin list sync
    │   └── one-click-checkout/
    │       ├── Constants.php           # Cron hook/schedule constants
    │       └── one-cc-address-sync.php # Address sync job
    └── support/
        ├── abandoned-cart-hooks.php    # Abandoned cart integration
        ├── cartbounty.php              # CartBounty plugin support
        ├── smart-coupons.php           # WC Smart Coupons support
        └── wati.php                    # Wati.io WhatsApp integration
```

---

## Architecture Decisions

### Why WC_Payment_Gateway?
WooCommerce provides `WC_Payment_Gateway` as the base class for all payment methods. The plugin extends this to integrate cleanly with WooCommerce's checkout, order management, and refund systems.

### Dual Storage (HPOS + Legacy)
WooCommerce 7.1+ introduced High-Performance Order Storage (HPOS) which stores orders in dedicated DB tables instead of `wp_posts`/`wp_postmeta`. The plugin detects HPOS availability and uses the correct API:
- HPOS: `$order->get_meta()` / `$order->update_meta_data()` / `$order->save()`
- Legacy: `get_post_meta()` / `update_post_meta()`

### Async Webhook Processing
Payment webhooks are not processed synchronously. Instead:
1. Webhook data saved to `wp_rzp_webhook_requests` table
2. WP Cron job processes events asynchronously
3. This prevents timeout issues and duplicate processing

### Two Checkout Modes
- **Standard (Native)**: Razorpay `checkout.js` modal in browser
- **Hosted Redirect**: Uses Razorpay's hosted checkout page (when merchant has `options.redirect=true`)

### Magic Checkout (1CC) Architecture
Magic Checkout is a separate product that allows one-click checkout from the product/cart page. It requires:
- 1CC feature enabled on merchant's Razorpay account
- `enable_1cc=yes` in plugin settings
- Separate HMAC secret (`rzp1cc_hmac_secret`) for REST API auth
- Order created via REST API before payment

---

## All Payment Flows

### Flow 1: Standard WooCommerce Checkout

**Trigger:** Customer clicks "Place Order" on WC checkout page with Razorpay selected.

**Steps:**
1. WC calls `WC_Razorpay::process_payment($order_id)`
2. Plugin sets transient `razorpay_wc_order_id = $order_id` (TTL: 1hr)
3. Returns `['result' => 'success', 'redirect' => $paymentPageUrl]`
4. WC redirects to payment/receipt page
5. WordPress fires `woocommerce_receipt_razorpay` → `receipt_page($orderId)`
6. `generate_razorpay_form($orderId)` called
7. `getRazorpayPaymentParams()` → `createOrGetRazorpayOrderId()`
8. If no existing valid Razorpay order: `createRazorpayOrderId()` → `api->order->create($data)`
9. Razorpay order ID stored in order meta (`razorpay_order_id{$orderId}`)
10. Entry created in `wp_rzp_webhook_requests` with status=0
11. Form HTML + `script.js` injected into page
12. Customer pays in Razorpay modal (handled by `script.js`)
13. On success: modal closes, form POSTs to callback URL
14. `check_razorpay_response()` fires on `woocommerce_api_razorpay`
15. `verifySignature()` - HMAC verification of `razorpay_order_id|razorpay_payment_id`
16. `updateOrder()` - marks order as paid, clears cart
17. `wp_rzp_webhook_requests` entry updated to status=1
18. User redirected to thank you page

**Failure Path:** If signature fails or payment cancelled → order marked failed, user redirected to checkout.

---

### Flow 2: Magic Checkout (1CC)

**Trigger:** Customer clicks "Buy Now" / "Magic Checkout" button on product or cart page.

**Pre-conditions:** `enable_1cc=yes`, merchant has 1CC feature on Razorpay account.

**Steps:**
1. `btn-1cc-checkout.js` handles button click
2. REST POST `/wp-json/1cc/v1/order/create` authenticated via nonce
3. `createWcOrder()` handler:
   - Initializes WC session, cart
   - Creates WC order via `WC()->checkout()->create_order()`
   - Sets order status to `checkout-draft`
   - Sets `is_magic_checkout_order=yes` meta
   - Removes default shipping methods
   - Applies abandonment coupon if token present
4. 1CC button calls `/shipping/shipping-info` → `calculateShipping1cc()`
   - Clears and rebuilds cart from WC order
   - Sets customer shipping address
   - Returns available shipping rates with costs
5. Customer fills address, selects payment in Razorpay hosted checkout
6. Razorpay creates RZP order with `line_items` and optionally COD method
7. Payment completed → same callback as standard flow
8. `check_razorpay_response()` detects `is_magic_checkout_order=yes`
9. `update1ccOrderWC()` called:
   - Fetches Razorpay order data (address, shipping_fee, cod_fee)
   - `UpdateOrderAddress()` - sets billing/shipping from Razorpay order
   - Applies shipping fees to WC order
   - Handles COD fee if payment_method=cod
   - `handlePromotions()` - applies coupons, gift cards, Terra wallet
10. `order->payment_complete($razorpayPaymentId)` called
11. `handleCBRecoveredOrder()` and `handleWatiRecoveredOrder()` for retargeting plugins

---

### Flow 3: Webhook Processing

**Trigger:** Razorpay sends POST to `admin-post.php?action=rzp_wc_webhook`.

**Events handled:**
- `payment.authorized` - Primary payment confirmation
- `payment.failed` - Currently no-op
- `payment.pending` - COD orders
- `refund.created` - External refund sync
- `virtual_account.credited` - Bank transfer payments
- `subscription.cancelled` / `subscription.paused` / `subscription.resumed` / `subscription.charged` - Subscription lifecycle

**Steps:**
1. `razorpay_webhook_init()` → `new RZP_Webhook()` → `process()`
2. Parse JSON body from `php://input`
3. `shouldConsumeWebhook()` validates event type and payload structure
4. Retrieve `HTTP_X_RAZORPAY_SIGNATURE` header
5. Get `webhook_secret` from options
6. `api->utility->verifyWebhookSignature($post, $signature, $secret)`
7. **For `payment.authorized`:** Call `saveWebhookEvent()` → stores in `rzp_webhook_requests` table
8. WP Cron scheduled job calls `RZP_Webhook::paymentAuthorized()`:
   - Checks order needs payment
   - Fetches payment entity from Razorpay API
   - If `payment_action=capture` and status=`authorized`: captures payment
   - `updateOrder()` finalizes WC order

---

### Flow 4: Refund Processing

**WC Admin-Initiated:**
1. Admin clicks "Refund" in WC order screen
2. WooCommerce calls `WC_Razorpay::process_refund($orderId, $amount, $reason)`
3. Validates transaction ID exists
4. `$client->payment->fetch($paymentId)->refund($data)`
5. Data: `{amount: paise, notes: {reason, order_id, refund_from_website: true}}`
6. On success: order note added with Refund ID, `woo_razorpay_refund_success` action fired
7. Returns `true` (WC marks refund complete)

**Webhook-Initiated (External Refund):**
1. `refund.created` webhook received
2. `refundedCreated()` handler called
3. Skips if `notes.refund_from_website=true` (avoids duplicate WC refund)
4. Skips if invoice_id set (subscription)
5. Calls `wc_create_refund()` with refund amount and reason from webhook data

---

### Flow 5: Route Transfers

**Setup:** Admin enables Route in plugin settings, configures linked accounts on products.

**Order Creation with Transfers:**
1. `getOrderCreationData()` checks `route_enable=yes`
2. `RZP_Route_Action::getOrderTransferData($orderId)` fetches transfer rules from product meta
3. `transfers` array added to Razorpay order creation payload

**Post-Payment Transfer:**
1. `updateOrder()` on success calls `RZP_Route_Action::transferFromPayment($orderId, $razorpayPaymentId)`
2. Transfer created from payment to linked accounts

**Admin Operations (via UI):**
- Direct Transfer: `rzp_direct_transfer` → `api->transfer->create()`
- Reverse Transfer: `rzp_reverse_transfer` → `api->transfer->fetch()->reverse()`
- Settlement Change: `rzp_settlement_change` → update transfer settlement
- Payment Transfer: `rzp_payment_transfer` → `createPaymentTransfer()`

---

### Flow 6: Subscription Events

Subscription events come from Razorpay when a subscription plugin is used. The plugin registers additional webhook events when `rzp_subscription_webhook_enable_flag` is set:
- `subscription.charged` - New charge created
- `subscription.cancelled` - Subscription cancelled
- `subscription.paused` - Subscription paused
- `subscription.resumed` - Subscription resumed

The base webhook handlers for these events are no-ops in this plugin (return without action). Actual subscription management is handled by a companion subscription plugin.

---

## Coding Conventions

### PHP Conventions
- Functions: `snake_case` (WordPress style)
- Methods: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Class names: `PascalCase`

### Input Sanitization
Always sanitize:
```php
$value = sanitize_text_field($_POST['field']);
$id = (int) sanitize_text_field($_POST['id']);
```

### Nonce Verification
```php
$verifyReq = wp_verify_nonce($nonce, 'action_name');
if ($verifyReq === false) { wp_die('Security check failed'); }
```

### HPOS Pattern
```php
if ($this->isHposEnabled) {
    $val = $order->get_meta('key');
    $order->update_meta_data('key', $val);
    $order->save();
} else {
    $val = get_post_meta($orderId, 'key', true);
    update_post_meta($orderId, 'key', $val);
}
```

### Amount Conversion
```php
// WC total is in display currency (e.g., 100.00)
// Razorpay requires smallest unit (e.g., 10000 paise)
$amount = (int) round($order->get_total() * 100);
```

### Logging
```php
rzpLogInfo("Informational message");
rzpLogError("Error condition: " . $e->getMessage());
rzpLogDebug("Debug info: " . json_encode($data)); // only when debug mode on
```

---

## Key Options in `wp_options`

| Option Key | Purpose |
|-----------|---------|
| `woocommerce_razorpay_settings` | All plugin settings array |
| `webhook_secret` | HMAC secret for webhook verification |
| `rzp1cc_hmac_secret` | HMAC secret for 1CC REST API auth |
| `rzp_wc_last_key_id` | Last known API key (detect key changes) |
| `webhook_enable_flag` | Timestamp of last webhook auto-setup |
| `rzp_subscription_webhook_enable_flag` | Whether subscription events should be added |
| `rzp_hpos` | Tracks if HPOS was enabled (`yes`/`no`) |
| `rzp_afd_enable` | Affordability widget feature flag |
| `rzp_rtb_enable` | Razorpay Trusted Business widget flag |
| `one_cc_merchant_preference` | Cached merchant 1CC preferences (transient) |

---

## External Integrations

| Plugin | Integration File | Purpose |
|--------|----------------|---------|
| CartBounty | `includes/support/cartbounty.php` | Abandoned cart recovery |
| Wati.io | `includes/support/wati.php` | WhatsApp retargeting |
| WC Smart Coupons | `includes/support/smart-coupons.php` | Smart coupon support |
| PW Gift Cards | `woo-razorpay.php` | Gift card product detection |
| YITH Gift Cards | `woo-razorpay.php` | Gift card product detection |
| PixelYourSite PRO | `includes/api/order.php` | UTM data to orders |
| YITH Dynamic Pricing | `includes/api/order.php` | Dynamic discount compatibility |
| WCFM Marketplace | `woo-razorpay.php` | Multi-vendor shipping |

---

## Security Model

1. **Webhook HMAC**: SHA-256 HMAC of raw request body using `webhook_secret`
2. **Payment Signature**: SHA-256 HMAC of `{razorpay_order_id}|{razorpay_payment_id}` using `key_secret`
3. **1CC REST Auth**: SHA-256 HMAC of request body using `rzp1cc_hmac_secret`
4. **Admin Actions**: WordPress nonce verification + `current_user_can('manage_woocommerce')`
5. **1CC Order Creation**: WordPress nonce via `X-WP-Nonce` header

---

## Testing

```bash
composer install
./vendor/bin/phpunit --configuration phpunit.xml
```

Test files: `/tests/` directory

Test scenarios:
- Payment success/failure callback handling
- Signature verification (valid/invalid)
- Webhook processing (each event type)
- Refund flow (both WC and webhook-initiated)
- 1CC order creation and address sync
- Route transfer creation
- HPOS vs legacy compatibility
