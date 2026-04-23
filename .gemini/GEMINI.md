# GEMINI.md — Razorpay WooCommerce Plugin Context

## Project Overview

This is the **Razorpay WooCommerce Plugin** (v4.8.3), a WordPress payment gateway that integrates Razorpay's payment infrastructure with WooCommerce stores. Written in PHP, it handles payment collection, webhooks, refunds, and an advanced "Magic Checkout" (1CC) one-click checkout experience.

## Technology Stack

- **Language:** PHP 7.4+
- **Framework:** WordPress plugin API + WooCommerce payment gateway API
- **External SDK:** Razorpay PHP SDK (vendored in `razorpay-sdk/`)
- **Testing:** PHPUnit + Mockery

## Repository Structure

```
woo-razorpay.php              # Main plugin — class WC_Razorpay
checkout-block.php            # WooCommerce Blocks integration
script.js                     # Frontend payment modal JS
includes/
  razorpay-webhook.php        # class RZP_Webhook
  api/api.php                 # 1CC REST endpoint registration
  api/order.php               # createWcOrder handler
  api/shipping-info.php       # Shipping calculation
  api/coupon-get.php          # Coupon listing
  api/coupon-apply.php        # Coupon application
  api/auth.php                # HMAC authentication
  plugin-instrumentation.php  # class TrackPluginInstrumentation
  razorpay-route.php          # Route module admin UI
  razorpay-route-actions.php  # Route module API operations
  debug.php                   # rzpLog*() wrappers
  utils.php                   # is1ccEnabled(), isHposEnabled(), etc.
  state-map.php               # India state name → WC state code
  cron/                       # WP Cron job definitions
  support/                    # Third-party plugin integrations
```

## Core Payment Flow

1. Customer clicks "Place Order" with Razorpay selected
2. `process_payment()` sets transient and returns redirect to receipt page
3. Receipt page calls `generate_razorpay_form()` which creates a Razorpay order via `POST /v1/orders`
4. JavaScript opens the Razorpay checkout modal
5. Customer pays; Razorpay sends payment credentials back to form
6. Form POSTs to callback URL `?wc-api=razorpay`
7. `check_razorpay_response()` verifies HMAC signature and marks order paid
8. Simultaneously, Razorpay sends `payment.authorized` webhook (async fallback)

## Critical Code Patterns

### Currency Amounts (ALWAYS use this)
```php
(int) round($order->get_total() * 100)  // paise for INR
```

### HPOS-aware Order Meta (ALWAYS use dual path)
```php
if ($this->isHposEnabled) {
    $order->update_meta_data('key', 'value');
    $order->save();
} else {
    update_post_meta($orderId, 'key', 'value');
}
```

### API Instance
```php
$api = $this->getRazorpayApiInstance();  // uses settings key_id/key_secret
$api = $this->getRazorpayApiPublicInstance();  // no secret, for /preferences
```

## Key Design Decisions

| Decision | Rationale |
|---|---|
| Dual callback+webhook processing | Resilience: callback is fast; webhook is reliable fallback |
| `rzp_webhook_requests` table | Tracks processing state to prevent double-payment |
| `$hooks = false` constructor param | Subscriptions plugin inherits WC_Razorpay without re-registering hooks |
| Localhost webhook blocked | Razorpay cannot reach localhost; prevents misconfiguration |
| HMAC-SHA256 for 1CC coupon endpoint | Extra security for price-sensitive coupon data |

## What NOT to Change

1. Do not remove the `$hooks = false` constructor parameter path
2. Do not use `get_post_meta()` without adding the HPOS equivalent
3. Do not change the session key format (`razorpay_order_id{$orderId}`)
4. Do not remove the KWD/OMR/BHD currency block — these are 3-decimal currencies
5. Do not use `$amount * 100` without `round()` and `(int)` cast

## Extended Documentation

- Full architecture: `.ai/context/CODEBASE_OVERVIEW.md`
- Payment flow: `.ai/context/PAYMENT_FLOW.md`
- Webhook flow: `.ai/context/WEBHOOK_FLOW.md`
- API endpoints: `.ai/context/API_INTEGRATION.md`
- All WP hooks: `.ai/context/WORDPRESS_HOOKS.md`
- DB schema: `.ai/context/DATABASE_SCHEMA.md`
- System diagrams: `.ai/diagrams/`
- Multi-LLM context: `AGENTS.md`
