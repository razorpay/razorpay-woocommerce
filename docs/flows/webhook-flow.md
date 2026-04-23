# Webhook Flow - Razorpay WooCommerce

## Overview

Razorpay webhooks provide server-to-server notifications for payment events. The plugin processes them asynchronously to prevent timeout issues and ensure idempotent order processing.

## Webhook URL

```
{site_url}/wp-admin/admin-post.php?action=rzp_wc_webhook
```

Configured automatically when API keys are saved. Webhook secret is auto-generated and stored in `webhook_secret` option.

## Supported Webhook Events

| Event | Handler Method | Action |
|-------|---------------|--------|
| `payment.authorized` | `paymentAuthorized()` | Store in DB, capture if needed |
| `payment.pending` | `paymentPending()` | Handle COD pending payments |
| `payment.failed` | `paymentFailed()` | No-op (return) |
| `refund.created` | `refundedCreated()` | Create WC refund |
| `virtual_account.credited` | `virtualAccountCredited()` | Process bank transfer payment |
| `subscription.charged` | `subscriptionCharged()` | No-op (companion plugin handles) |
| `subscription.cancelled` | `subscriptionCancelled()` | No-op |
| `subscription.paused` | `subscriptionPaused()` | No-op |
| `subscription.resumed` | `subscriptionResumed()` | No-op |

## Complete Webhook Processing Sequence

```mermaid
sequenceDiagram
    participant RZPAPI as Razorpay Platform
    participant WP as WordPress
    participant WH as RZP_Webhook
    participant DB as WordPress DB
    participant WC as WooCommerce
    participant CRON as WP Cron Job

    RZPAPI->>WP: POST admin-post.php?action=rzp_wc_webhook
    Note over WP,WH: Headers: Content-Type: application/json, X-Razorpay-Signature: sha256_hmac

    WP->>WH: razorpay_webhook_init() via admin_post_nopriv_rzp_wc_webhook hook
    WH->>WH: new RZP_Webhook()
    Note over WH: Constructor: new WC_Razorpay(false) + getRazorpayApiInstance()
    WH->>WH: process()

    WH->>WH: file_get_contents('php://input')
    WH->>WH: json_decode($post)
    alt JSON decode error
        WH-->>RZPAPI: return (200 OK)
    end

    WH->>WH: shouldConsumeWebhook($data)
    Note over WH: Validates: event in eventsArray AND woocommerce_order_id present in notes
    alt Invalid event or missing order ID
        WH-->>RZPAPI: return
    end

    WH->>DB: get_option('webhook_secret')
    alt webhook_secret empty
        WH->>DB: get_option('rzp_webhook_secret') - legacy fallback
        alt still empty
            WH-->>RZPAPI: return (no secret configured)
        end
    end

    WH->>WH: api->utility->verifyWebhookSignature($post, $signature, $secret)
    alt Signature verification fails
        WH->>WH: rzpLogError("signature verify failed")
        WH->>WH: rzpTrackDataLake('razorpay.webhook.signature.verification.failed')
        WH-->>RZPAPI: return
    end

    WH->>WH: switch($data['event'])

    rect rgb(200, 230, 200)
        Note over WH: payment.authorized
        WH->>WH: Build webhookFilteredData {invoice_id, woocommerce_order_id, payment_id, event}
        WH->>DB: SELECT rzp_webhook_data FROM rzp_webhook_requests WHERE order_id AND rzp_order_id
        WH->>DB: UPDATE rzp_webhook_data = JSON array with new event, notified_at = now()
        WH-->>RZPAPI: return
    end

    rect rgb(200, 200, 230)
        Note over CRON: WP Cron: paymentAuthorized($data) called later
        CRON->>WH: paymentAuthorized($filteredData)
        WH->>WH: Check invoice_id not set (skip subscription payments)
        WH->>WH: Check woocommerce_order_id not empty
        WH->>WC: wc_get_order($orderId)
        WH->>WC: order->get_status()
        alt Order already paid (not draft/pending/cancelled)
            WH-->>CRON: return
        end
        alt Order in checkout-draft or draft
            WH->>WC: updateOrderStatus($orderId, 'wc-pending')
        end
        WH->>WH: getPaymentEntity($paymentId)
        WH->>RZPAPI: payment->fetch($paymentId)
        RZPAPI-->>WH: Payment entity
        WH->>WH: getOrderAmountAsInteger($order)
        alt payment.status = captured
            WH->>WC: updateOrder(true)
        else payment.status = authorized AND capture mode
            WH->>RZPAPI: payment->capture({amount})
            alt Capture succeeds
                WH->>WC: updateOrder(true)
            else Capture fails (already captured)
                WH->>RZPAPI: payment->fetch() again
                alt Now captured
                    WH->>WC: updateOrder(true)
                end
            end
        end
    end

    rect rgb(230, 200, 200)
        Note over WH: virtual_account.credited
        WH->>WH: virtualAccountCredited($data)
        WH->>RZPAPI: payment->fetch($paymentId)
        WH->>WH: Check amountPaid == orderAmount
        WH->>WC: updateOrder(success, '', paymentId, virtualAccountId, webhook=true)
        WH-->>RZPAPI: exit()
    end

    rect rgb(230, 230, 200)
        Note over WH: refund.created
        WH->>WH: refundedCreated($data)
        WH->>WH: Skip if invoice_id set or refund_from_website=true
        WH->>RZPAPI: payment->fetch(payment_id)
        WH->>WC: wc_create_refund(amount, reason, order_id, refund_id)
        WH-->>RZPAPI: exit()
    end
```

## Webhook Database Schema

### Table: `wp_rzp_webhook_requests`

```sql
CREATE TABLE wp_rzp_webhook_requests (
    id                          BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    integration                 VARCHAR(50) DEFAULT 'woocommerce',
    order_id                    BIGINT(20) NOT NULL,
    rzp_order_id                VARCHAR(100) NOT NULL,
    rzp_webhook_data            LONGTEXT DEFAULT '[]',
    rzp_update_order_cron_status TINYINT(1) DEFAULT 0,
    rzp_webhook_notified_at     BIGINT(20),
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Status Values for `rzp_update_order_cron_status`:**
- `0` = `RZP_ORDER_CREATED` - Order created, webhook pending
- `1` = `RZP_ORDER_PROCESSED_BY_CALLBACK` - Already processed by payment callback

## Webhook Auto-Registration

When admin saves plugin settings:

```mermaid
flowchart TD
    A[autoEnableWebhook called] --> B{Key ID and Secret present?}
    B -->|No| C[Show error: Keys required]
    B -->|Yes| D[Validate keys via GET /v1/orders]
    D -->|Invalid| E[Show error: Invalid keys]
    D -->|Valid| F{Domain is public IP?}
    F -->|localhost| G[Show error: Cannot use localhost]
    F -->|Public| H[GET /v1/webhooks list]
    H --> I{Webhook URL exists?}
    I -->|Exists| J[PUT /v1/webhooks/{id} - update events]
    I -->|Not exists| K[POST /v1/webhooks/ - create new]
    J --> L[Track: autowebhook.updated]
    K --> M[Track: autowebhook.created]
```

## Signature Verification

The webhook signature is a SHA-256 HMAC of the raw request body using the webhook secret:

```php
// Razorpay SDK handles this:
$api->utility->verifyWebhookSignature($rawBody, $xRazorpaySignature, $webhookSecret);

// Internally:
// expected = HMAC-SHA256($rawBody, $webhookSecret)
// Compare with $xRazorpaySignature using hash_equals()
```

## Idempotency Handling

| Check | Where | Purpose |
|-------|-------|---------|
| `order->needs_payment()` | `paymentAuthorized()` | Skip if already paid |
| `rzp_update_order_cron_status = 1` | Cron job | Skip if callback already processed |
| `refund_from_website = true` | `refundedCreated()` | Skip webhook-initiated duplicate refund |
| `invoice_id` check | Multiple handlers | Skip subscription/invoice payments in main flow |
