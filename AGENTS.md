# AGENTS.md — Razorpay WooCommerce Plugin

> This file provides complete context for AI coding assistants (Claude, Gemini, Kimi, GPT-4, Copilot, etc.) working on the Razorpay WooCommerce plugin. Read this before making any changes to the codebase.

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
| Plugin ID | `razorpay` |
| WC Tested Up To | 10.6.2 |
| Repository | `razorpay/razorpay-woocommerce` |

---

## Quick Navigation

| You want to... | Read this file |
|---|---|
| Understand the full codebase | `.ai/context/CODEBASE_OVERVIEW.md` |
| Understand the payment flow | `.ai/context/PAYMENT_FLOW.md` |
| Understand webhook handling | `.ai/context/WEBHOOK_FLOW.md` |
| Understand subscription handling | `.ai/context/SUBSCRIPTION_FLOW.md` |
| Understand refund processing | `.ai/context/REFUND_FLOW.md` |
| See all Razorpay API calls | `.ai/context/API_INTEGRATION.md` |
| Find all WordPress hooks | `.ai/context/WORDPRESS_HOOKS.md` |
| Understand DB schema | `.ai/context/DATABASE_SCHEMA.md` |
| See high-level diagrams | `.ai/diagrams/HLD_*.md` |
| See sequence diagrams | `.ai/diagrams/LLD_*.md` |

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

## Key Architectural Decisions

### 1. Dual Payment Processing (Callback + Webhook)
The plugin processes payments via browser callback AND stores webhook events for background processing. The callback is the primary path; the webhook cron is the fallback. Use `rzp_update_order_cron_status` (0=pending, 1=callback-processed) to prevent double processing.

### 2. HPOS Dual-Path Pattern
WooCommerce High Performance Order Storage (HPOS) requires different APIs. Throughout the codebase, you'll see:
```php
if ($this->isHposEnabled) {
    $order->get_meta('key') / $order->update_meta_data('key', 'value'); $order->save();
} else {
    get_post_meta($orderId, 'key', true) / update_post_meta($orderId, 'key', 'value');
}
```
Always maintain this dual path when touching order metadata.

### 3. Amount Handling
All amounts are in the **smallest currency unit** (paise = 1/100 rupee):
```php
(int) round($order->get_total() * 100)  // Always use this pattern
```
Never pass float amounts to Razorpay API.

### 4. 1CC (Magic Checkout) as Optional Feature
Magic Checkout is gated by `is1ccEnabled()` and `$merchantPreferences['features']['one_click_checkout']`. All 1CC logic is behind these checks. Standard checkout must always work without 1CC.

### 5. Session Key Differentiation
- Standard orders: `razorpay_order_id{$orderId}` → looks up `RAZORPAY_ORDER_ID . $orderId`
- Magic Checkout orders: `razorpay_order_id_1cc{$orderId}` → looks up `RAZORPAY_ORDER_ID_1CC . $orderId`
- `getOrderSessionKey($orderId)` returns the correct key based on `is_magic_checkout_order` meta.

### 6. Security Model
- Admin API calls: HTTP Basic Auth (Key ID + Key Secret)
- Webhook verification: HMAC-SHA256 over raw body
- 1CC coupon endpoint: HMAC-SHA256 using `rzp1cc_hmac_secret` (separate from webhook secret)
- 1CC order creation: WP Nonce (`X-WP-Nonce` header)

### 7. Async Webhook Processing
Payment webhooks are not processed synchronously. Instead:
1. Webhook data saved to `wp_rzp_webhook_requests` table
2. WP Cron job processes events asynchronously
3. This prevents timeout issues and duplicate processing

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

## Code Conventions & Patterns

### PHP Style
- 4-space indentation (Allman-style braces)
- Class names: `PascalCase` (e.g., `WC_Razorpay`, `RZP_Webhook`)
- Method names: `camelCase` (e.g., `createRazorpayOrderId`)
- Function names: `camelCase` for hooks (e.g., `rzp1ccInitRestApi`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `RAZORPAY_PAYMENT_ID`)

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

### Error Handling Pattern
```php
try {
    $result = $api->someMethod($data);
} catch (Errors\BadRequestError $e) {
    // Safe to show to customer
    return new WP_Error('error', $e->getMessage());
} catch (\Exception $e) {
    rzpLogError($e->getMessage());
    $trackObject->rzpTrackDataLake('razorpay.operation.failed', ['error' => $e->getMessage()]);
    return new WP_Error('error', __('Something went wrong', 'woocommerce'));
}
```

### Logging Pattern
```php
rzpLogInfo("Descriptive message: $variable");  // Informational
rzpLogError("Error message: " . $e->getMessage());  // Errors
rzpLogDebug("Debug info: " . json_encode($data)); // only when debug mode on
```
All logging is gated by `isDebugModeEnabled()` (woocommerce_razorpay_settings['enable_1cc_debug_mode']).
Log source: `razorpay-logs` (visible in WC > Status > Logs).

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

### REST API Response Pattern
```php
return new WP_REST_Response($response, $statusCode);
// Success: 200
// Validation error: 400
// Auth error: 401/403
// Server error: 500
```

---

## How to Make Common Changes

### Add a New Webhook Event

1. Add event constant to `RZP_Webhook`:
   ```php
   const NEW_EVENT = 'new.event';
   ```

2. Add to `$eventsArray`:
   ```php
   protected $eventsArray = [
       ...
       self::NEW_EVENT,
   ];
   ```

3. Add to `$supportedWebhookEvents` in `WC_Razorpay` (so it can be auto-registered):
   ```php
   protected $supportedWebhookEvents = [
       ...
       'new.event',
   ];
   ```

4. Add to `$defaultWebhookEvents` if it should be auto-enabled.

5. Add a `case` in the `switch` in `RZP_Webhook::process()`:
   ```php
   case self::NEW_EVENT:
       return $this->handleNewEvent($data);
   ```

6. Implement `handleNewEvent(array $data)` method.

### Add a New 1CC REST Endpoint

1. Register the route in `includes/api/api.php`:
   ```php
   register_rest_route(
       RZP_1CC_ROUTES_BASE . '/newresource',
       'action',
       [
           'methods'             => 'POST',
           'callback'            => 'myHandler',
           'permission_callback' => 'checkAuthCredentials', // or checkHmacSignature
       ]
   );
   ```

2. Create handler file `includes/api/my-handler.php`.

3. Implement `myHandler(WP_REST_Request $request)`.

4. Include the file in `includes/api/api.php`.

5. Add HPOS-aware order meta access if needed.

### Add a New Plugin Setting

1. Add to `$defaultFormFields` in `WC_Razorpay::init_form_fields()`.
2. Add to `$visibleSettings` array if it should appear by default.
3. Access via `$this->getSetting('my_new_key')`.
4. For feature-gated settings, add to the `if ($is1ccAvailable)` block.

### Handle a New Payment Method

In `update1ccOrderWC()`, the `$razorpayPaymentData['method']` field identifies the method:
```php
$paymentDoneBy = $razorpayPaymentData['method'];
// 'card', 'netbanking', 'wallet', 'upi', 'cod', 'emi', etc.
```

Add handling in the payment method detection block after the `$api->payment->fetch()` call.

### Add Order Meta (HPOS-safe)

```php
// Write
if ($this->isHposEnabled) {
    $order->update_meta_data('my_meta_key', $value);
    $order->save();
} else {
    update_post_meta($orderId, 'my_meta_key', $value);
}

// Read
if ($this->isHposEnabled) {
    $value = $order->get_meta('my_meta_key');
} else {
    $value = get_post_meta($orderId, 'my_meta_key', true);
}
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

## Testing Approach

The project uses PHPUnit with WP testing tools.

**Setup:** See `composer.json` — requires `valu/wp-testing-tools`, `yoast/phpunit-polyfills`, `mockery/mockery`.

**Run tests:**
```bash
composer install
./vendor/bin/phpunit --configuration phpunit.xml
```

**Test configuration:** `phpunit.xml`

**Test files:** `tests/` directory

**Key test patterns:**
- Mock `WC_Razorpay` to avoid constructor side effects
- Mock Razorpay API responses using Mockery
- Use `$hooks = false` when instantiating `WC_Razorpay` in tests to skip hook registration

**Test scenarios:**
- Payment success/failure callback handling
- Signature verification (valid/invalid)
- Webhook processing (each event type)
- Refund flow (both WC and webhook-initiated)
- 1CC order creation and address sync
- Route transfer creation
- HPOS vs legacy compatibility

---

## Common Pitfalls

### 1. Double Payment Processing
**Problem:** Webhook and callback both try to mark order as paid.
**Solution:** Always check `needs_payment() === false` before processing. The `wc_order_under_process_` transient prevents simultaneous 1CC updates.

### 2. Amount Mismatch in Verification
**Problem:** `verifyOrderAmount()` fails when cart total changes between order creation and payment.
**Solution:** Plugin creates a new Razorpay order when amounts don't match. This is by design.

### 3. HPOS Incompatibility
**Problem:** Using `get_post_meta()` / `update_post_meta()` on HPOS-enabled installs.
**Solution:** Always use the `isHposEnabled` conditional pattern described above.

### 4. Webhook Secret Not Found
**Problem:** Webhook validation fails silently.
**Solution:** Check secret fallback chain: `woocommerce_razorpay_settings['webhook_secret']` → `get_option('webhook_secret')` → `get_option('rzp_webhook_secret')`.

### 5. 1CC Order Without Line Items
**Problem:** Creating a Razorpay order for 1CC without `line_items[]` causes Razorpay API errors.
**Solution:** Always pass `'yes'` as 3rd argument to `createOrGetRazorpayOrderId()` for 1CC orders. This triggers `orderArg1CC()`.

### 6. Localhost Webhook Registration
**Problem:** `autoEnableWebhook()` blocks registration for localhost/private IP domains.
**Solution:** Use ngrok or similar tunneling for local development. The domain IP is validated with `FILTER_FLAG_NO_PRIV_RANGE`.

### 7. Currency Precision
**Problem:** Using `$total * 100` instead of `round($total * 100)` causes floating point errors.
**Solution:** Always use `(int) round($order->get_total() * 100)`.

### 8. Subscription Payments with Invoice ID
**Problem:** Treating subscription payments as regular payments.
**Solution:** Check `isset($data['invoice_id'])` in `paymentAuthorized()` and return early — subscription payments must be handled by the subscriptions plugin.

### 9. Concurrent Secret Registration Lock
**Problem:** Multiple requests simultaneously try to register 1CC HMAC secret.
**Solution:** The `rzp_wc_ensure_1cc_secret_lock` transient (30s) prevents this. Always delete the lock on failure.

### 10. KWD/OMR/BHD Currency
**Problem:** These 3-decimal currencies don't work with the `*100` conversion.
**Solution:** They are explicitly blocked with an exception in `createRazorpayOrderId()`.

---

## Environment Variables / Configuration

There are no environment variables. All configuration is stored in WordPress options:

| Where to Look | What |
|---|---|
| WC Admin > Payments > Razorpay | Main settings UI |
| `woocommerce_razorpay_settings` WP option | Serialized settings array |
| `webhook_secret` WP option | Webhook HMAC secret |
| `rzp1cc_hmac_secret` WP option | 1CC API signing secret |

Use test keys (`rzp_test_*`) for development. The plugin detects mode from key prefix: `rzp_live_` = live, anything else = test.

---

## File to Read First for Each Task

| Task | Start Here |
|---|---|
| Fix payment flow bug | `woo-razorpay.php` → `check_razorpay_response()` |
| Fix webhook bug | `includes/razorpay-webhook.php` → `process()` |
| Fix 1CC order creation | `includes/api/order.php` → `createWcOrder()` |
| Fix shipping calculation | `includes/api/shipping-info.php` |
| Fix coupon application | `includes/api/coupon-apply.php` |
| Fix refund | `woo-razorpay.php` → `process_refund()` |
| Add new setting | `woo-razorpay.php` → `init_form_fields()` |
| Fix Route transfer | `includes/razorpay-route-actions.php` |
| Fix instrumentation | `includes/plugin-instrumentation.php` |
| Fix cron job | `includes/cron/one-click-checkout/one-cc-address-sync.php` |
