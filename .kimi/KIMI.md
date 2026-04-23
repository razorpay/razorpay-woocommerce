# Razorpay WooCommerce Plugin - Kimi Context

## Project Summary

Official Razorpay payment gateway plugin for WooCommerce (WordPress). Enables merchants to accept payments via UPI, cards, netbanking, wallets, and more.

- **Version:** 4.8.3
- **Language:** PHP 7.4+
- **Platform:** WordPress + WooCommerce
- **Main Class:** `WC_Razorpay` extends `WC_Payment_Gateway`

---

## Repository Structure

```
woo-razorpay.php              ← Main plugin, defines WC_Razorpay
checkout-block.php            ← Gutenberg block (WC_Razorpay_Blocks)
script.js                     ← Checkout modal JS
btn-1cc-checkout.js           ← Magic Checkout button JS
checkout_block.js             ← Block-based checkout JS
includes/
  razorpay-webhook.php        ← RZP_Webhook (webhook processing)
  razorpay-route.php          ← RZP_Route (Route admin UI)
  razorpay-route-actions.php  ← RZP_Route_Action (Route business logic)
  razorpay-affordability-widget.php
  plugin-instrumentation.php  ← TrackPluginInstrumentation
  utils.php                   ← Global helpers (is1ccEnabled, isHposEnabled, etc.)
  state-map.php               ← India state code → name mapping
  debug.php                   ← rzpLogInfo / rzpLogError / rzpLogDebug
  api/
    api.php                   ← 1CC REST API route registration
    auth.php                  ← HMAC + credential auth
    order.php                 ← createWcOrder (1CC)
    cart.php                  ← fetchCartData, createCartData
    shipping-info.php         ← calculateShipping1cc
    coupon-apply.php          ← applyCouponOnCart
    coupon-get.php            ← getCouponList
    giftcard-apply.php        ← validateGiftCardData
    prepay-cod.php            ← prepayCODOrder
    save-abandonment-data.php
  cron/
    cron.php                  ← createCron, deleteCron
    plugin-fetch.php
    one-click-checkout/
      Constants.php
      one-cc-address-sync.php
  support/
    abandoned-cart-hooks.php
    cartbounty.php
    smart-coupons.php
    wati.php
razorpay-sdk/                 ← Bundled Razorpay PHP SDK
templates/
tests/
```

---

## Payment Flows

### Standard Checkout
```
WC checkout → process_payment() → receipt_page() → Razorpay Modal → 
callback → check_razorpay_response() → verifySignature() → updateOrder()
```

### Magic Checkout (1CC)
```
1CC Button → POST /wp-json/1cc/v1/order/create → createWcOrder() →
Razorpay Checkout → callback → check_razorpay_response() → 
update1ccOrderWC() → sync address/shipping/promotions
```

### Webhook Processing
```
Razorpay webhook → admin-post.php?action=rzp_wc_webhook →
RZP_Webhook::process() → verify HMAC → save to DB →
WP Cron → paymentAuthorized() → capture → updateOrder()
```

### Refund
```
WC Admin → process_refund() → Razorpay API refund
OR
Razorpay refund → refund.created webhook → refundedCreated() → wc_create_refund()
```

---

## Key API Calls

### Razorpay API Endpoints Used

| Operation | SDK Call |
|-----------|----------|
| Create order | `$api->order->create($data)` |
| Fetch order | `$api->order->fetch($orderId)` |
| Fetch payment | `$api->payment->fetch($paymentId)` |
| Capture payment | `$api->payment->capture(['amount' => $amount])` |
| Refund payment | `$api->payment->fetch($id)->refund($data)` |
| Create transfer | `$api->transfer->create($data)` |
| Reverse transfer | `$api->transfer->fetch($id)->reverse($data)` |
| Verify payment signature | `$api->utility->verifyPaymentSignature($attrs)` |
| Verify webhook signature | `$api->utility->verifyWebhookSignature($body, $sig, $secret)` |
| Get merchant preferences | `$api->request->request('GET', 'preferences')` |
| Register 1CC secret | `$api->request->request('POST', 'magic/merchant/auth/secret', $data)` |

---

## Database Usage

### WordPress Options
- `woocommerce_razorpay_settings` - All plugin settings
- `webhook_secret` - Webhook HMAC secret
- `rzp1cc_hmac_secret` - 1CC REST API HMAC secret
- `rzp_wc_last_key_id` - Last API key (for detecting key changes)
- `webhook_enable_flag` - Timestamp of last webhook setup

### Custom DB Table: `wp_rzp_webhook_requests`
- Stores webhook events for async processing by WP Cron
- Columns: `integration`, `order_id`, `rzp_order_id`, `rzp_webhook_data`, `rzp_update_order_cron_status`, `rzp_webhook_notified_at`

### Order Meta (per order)
- `razorpay_order_id{wcOrderId}` - Standard checkout Razorpay order ID
- `razorpay_order_id_1cc{wcOrderId}` - 1CC checkout Razorpay order ID
- `is_magic_checkout_order` - `yes` for 1CC orders
- `1cc_shippinginfo` - Stored shipping options for 1CC

---

## WooCommerce Integration Points

### Hooks Used
- `woocommerce_receipt_razorpay` → `receipt_page()`
- `woocommerce_api_razorpay` → `check_razorpay_response()`
- `woocommerce_update_options_payment_gateways_razorpay` → settings save
- `woocommerce_payment_gateways` → register gateway
- `woocommerce_blocks_loaded` → register Gutenberg block
- `woocommerce_thankyou_order_received_text` → custom message
- `before_woocommerce_init` → HPOS + blocks compatibility

### HPOS Support
Plugin supports both legacy post meta and HPOS (High-Performance Order Storage). Uses `isHposEnabled` flag to choose correct API.

---

## Security

1. **Webhook verification**: HMAC-SHA256 of raw request body vs `webhook_secret`
2. **Payment signature**: HMAC-SHA256 of `{order_id}|{payment_id}` vs `key_secret`
3. **1CC API auth**: HMAC-SHA256 of request body vs `rzp1cc_hmac_secret`
4. **Admin actions**: WordPress nonces + capability checks
5. **Input sanitization**: All user input via `sanitize_text_field()`

---

## Amount Handling

All amounts sent to/from Razorpay API are in smallest currency unit (paise for INR):
```php
$razorpayAmount = (int) round($order->get_total() * 100);
$wcAmount = $razorpayAmount / 100;
```

---

## Development

### Setup
```bash
composer install
./vendor/bin/phpunit --configuration phpunit.xml
```

### Code Style
- PHP functions: `snake_case`
- Class methods: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Always use `rzpLogInfo()` / `rzpLogError()` for logging
- Always sanitize input, verify nonces

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
