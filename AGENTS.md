# AGENTS.md — Razorpay WooCommerce Plugin

> This file provides context for AI coding assistants including Claude (all versions), Gemini (all versions), and Kimi. Read this before making any changes to the codebase.

---

## Project Description

The **Razorpay WooCommerce Plugin** (v4.8.3) is a WordPress payment gateway plugin that integrates Razorpay's payment infrastructure with WooCommerce. It enables Indian and international merchants to accept payments via UPI, Credit/Debit Cards, NetBanking, Wallets, and Cash on Delivery through the Razorpay platform.

**Repository:** `razorpay/razorpay-woocommerce`
**Entry Point:** `woo-razorpay.php`
**Plugin ID:** `razorpay` (used in WC gateway registration and hook names like `woocommerce_receipt_razorpay`)

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

---

## Code Conventions & Patterns

### PHP Style
- 4-space indentation (Allman-style braces)
- Class names: `PascalCase` (e.g., `WC_Razorpay`, `RZP_Webhook`)
- Method names: `camelCase` (e.g., `createRazorpayOrderId`)
- Function names: `camelCase` for hooks (e.g., `rzp1ccInitRestApi`)
- Constants: `UPPER_SNAKE_CASE` (e.g., `RAZORPAY_PAYMENT_ID`)

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
```
All logging is gated by `isDebugModeEnabled()` (woocommerce_razorpay_settings['enable_1cc_debug_mode']).
Log source: `razorpay-logs` (visible in WC > Status > Logs).

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

## Testing Approach

The project uses PHPUnit with WP testing tools.

**Setup:** See `composer.json` — requires `valu/wp-testing-tools`, `yoast/phpunit-polyfills`, `mockery/mockery`.

**Run tests:**
```bash
composer install
./vendor/bin/phpunit
```

**Test configuration:** `phpunit.xml`

**Test files:** `tests/` directory

**Key test patterns:**
- Mock `WC_Razorpay` to avoid constructor side effects
- Mock Razorpay API responses using Mockery
- Use `$hooks = false` when instantiating `WC_Razorpay` in tests to skip hook registration

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
