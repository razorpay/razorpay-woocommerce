# KIMI.md — Razorpay WooCommerce Plugin Context

## Project Summary

**razorpay-woocommerce** is a PHP WordPress plugin (v4.8.3) that connects WooCommerce e-commerce stores to the Razorpay payment gateway. It supports:
- Standard checkout (modal/hosted)
- Magic Checkout / One Click Checkout (1CC) — streamlined checkout with saved addresses
- Webhooks for async payment confirmation
- Refunds
- Route payments (marketplace/linked account transfers)
- Razorpay Subscriptions (stub hooks, implemented in separate plugin)

## Entry Points

| Entry Point | File | Triggered By |
|---|---|---|
| Plugin init | `woo-razorpay.php` | `plugins_loaded` hook |
| Payment form | `woo-razorpay.php::receipt_page()` | WC receipt page |
| Payment callback | `woo-razorpay.php::check_razorpay_response()` | `?wc-api=razorpay` |
| Webhook handler | `includes/razorpay-webhook.php` | `admin-post.php?action=rzp_wc_webhook` |
| 1CC order create | `includes/api/order.php::createWcOrder()` | `POST /wp-json/1cc/v1/order/create` |
| 1CC shipping | `includes/api/shipping-info.php::calculateShipping1cc()` | `POST /wp-json/1cc/v1/shipping/shipping-info` |
| 1CC coupon list | `includes/api/coupon-get.php::getCouponList()` | `POST /wp-json/1cc/v1/coupon/list` |
| 1CC coupon apply | `includes/api/coupon-apply.php::applyCouponOnCart()` | `POST /wp-json/1cc/v1/coupon/apply` |
| Refund | `woo-razorpay.php::process_refund()` | WC admin refund action |

## Important Class Relationships

```
WC_Payment_Gateway (WooCommerce)
    └── WC_Razorpay (woo-razorpay.php) — Main gateway
            └── [subscriptions plugin extends this]

WC_List_Table (WordPress)
    └── RZP_Route (includes/razorpay-route.php) — Route admin UI

AbstractPaymentMethodType (WooCommerce Blocks)
    └── WC_Razorpay_Blocks (checkout-block.php)

TrackPluginInstrumentation (includes/plugin-instrumentation.php)
    └── [standalone analytics class]

RZP_Webhook (includes/razorpay-webhook.php)
    └── [subscriptions plugin extends this]

RZP_Route_Action (includes/razorpay-route-actions.php)
    └── [standalone Route operations class]
```

## Database Interaction Points

### Custom Table: `{prefix}rzp_webhook_requests`
- **INSERT**: When Razorpay order is created (status=0)
- **UPDATE data**: When `payment.authorized` webhook arrives (store payload)
- **UPDATE status**: When callback successfully processes payment (status=1)
- **SELECT**: Cron job reads status=0 records for processing

### Options
- `woocommerce_razorpay_settings` — all plugin config
- `webhook_secret` — HMAC secret for webhook verification
- `rzp1cc_hmac_secret` — HMAC secret for 1CC coupon endpoint

## Razorpay API Calls Summary

```
POST  /v1/orders                           # Create payment order
GET   /v1/orders/{id}                      # Verify order amount
GET   /v1/payments/{id}                    # Fetch payment status
POST  /v1/payments/{id}/capture            # Capture authorized payment
POST  /v1/payments/{id}/refund             # Issue refund
POST  /v1/payments/{id}/transfer           # Route: transfer from payment
GET   /v1/webhooks?count=N&skip=N          # List registered webhooks
POST  /v1/webhooks/                        # Create webhook
PUT   /v1/webhooks/{id}                    # Update webhook
GET   /v1/preferences                      # Get merchant checkout config
GET   /v1/merchant/1cc_preferences         # Check 1CC feature availability
GET   /v1/accounts/me/features             # Check affordability widget
POST  /v1/plugins/segment                  # Track events (analytics)
POST  /v1/magic/merchant/auth/secret       # Register 1CC HMAC secret
POST  /v1/1cc/orders/cod/convert           # Mark COD order as prepaid
```

## Security Checklist

When making changes, ensure:
- [ ] Webhook handlers verify `HTTP_X_RAZORPAY_SIGNATURE` before processing
- [ ] All monetary amounts use `(int) round($amount * 100)`
- [ ] Order meta reads/writes have both HPOS and classic paths
- [ ] User input is sanitized via `sanitize_text_field()` before use
- [ ] REST endpoints use appropriate `permission_callback`
- [ ] The `wc_order_under_process_` transient prevents concurrent processing

## Full Documentation Index

| Topic | File |
|---|---|
| Architecture | `.ai/context/CODEBASE_OVERVIEW.md` |
| Payment flow | `.ai/context/PAYMENT_FLOW.md` |
| Webhooks | `.ai/context/WEBHOOK_FLOW.md` |
| Subscriptions | `.ai/context/SUBSCRIPTION_FLOW.md` |
| Refunds | `.ai/context/REFUND_FLOW.md` |
| Razorpay APIs | `.ai/context/API_INTEGRATION.md` |
| WP hooks | `.ai/context/WORDPRESS_HOOKS.md` |
| Database | `.ai/context/DATABASE_SCHEMA.md` |
| System HLD | `.ai/diagrams/HLD_SYSTEM_OVERVIEW.md` |
| Payment HLD | `.ai/diagrams/HLD_PAYMENT_FLOW.md` |
| Webhook HLD | `.ai/diagrams/HLD_WEBHOOK_FLOW.md` |
| Checkout LLD | `.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md` |
| Webhook LLD | `.ai/diagrams/LLD_WEBHOOK_SEQUENCE.md` |
| Refund LLD | `.ai/diagrams/LLD_REFUND_SEQUENCE.md` |
| Subscription LLD | `.ai/diagrams/LLD_SUBSCRIPTION_SEQUENCE.md` |
| Order state machine | `.ai/diagrams/LLD_ORDER_FLOW.md` |
| Multi-LLM context | `AGENTS.md` |
