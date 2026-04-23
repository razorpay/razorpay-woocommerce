# Subscription Flow - Razorpay WooCommerce

## Overview

Subscription payments in Razorpay WooCommerce involve recurring billing managed by Razorpay. The base plugin handles webhook events for subscription lifecycle; actual subscription creation/management is done by a companion WooCommerce Subscriptions plugin.

## Webhook Events for Subscriptions

The base plugin registers these subscription webhook events when `rzp_subscription_webhook_enable_flag` is set:

| Event | Handler | Action |
|-------|---------|--------|
| `subscription.charged` | `subscriptionCharged()` | No-op (companion plugin handles) |
| `subscription.cancelled` | `subscriptionCancelled()` | No-op |
| `subscription.paused` | `subscriptionPaused()` | No-op |
| `subscription.resumed` | `subscriptionResumed()` | No-op |

## Subscription Webhook Processing Flow

```mermaid
sequenceDiagram
    participant RZPAPI as Razorpay Platform
    participant WH as RZP_Webhook
    participant SUBPLUGIN as WC Subscriptions Plugin
    participant WC as WooCommerce
    participant DB as WordPress DB

    Note over RZPAPI: Renewal date triggers auto-charge
    RZPAPI->>RZPAPI: Charge subscription payment method
    RZPAPI->>WH: POST webhook {event: subscription.charged}
    Note over WH: Payload includes subscription entity + payment entity

    WH->>WH: process()
    WH->>WH: shouldConsumeWebhook($data)
    Note over WH: Checks subscription.entity.notes.woocommerce_order_id

    WH->>WH: verifyWebhookSignature()
    WH->>WH: subscriptionCharged($data) -> return (no-op)

    Note over SUBPLUGIN: Companion plugin listens to Razorpay webhook hooks
    SUBPLUGIN->>WC: Create renewal order
    SUBPLUGIN->>DB: Update subscription next payment date
    SUBPLUGIN->>WC: Mark renewal order paid
```

## Subscription Webhook Registration

When settings are saved:

```mermaid
flowchart TD
    A[Admin saves plugin settings] --> B[autoEnableWebhook called]
    B --> C{rzp_subscription_webhook_enable_flag set?}
    C -->|Yes| D[Add subscription events to defaultWebhookEvents]
    C -->|No| E[Keep only payment events]
    D --> F[subscription.cancelled + paused + resumed + charged = true]
    E --> G[Only payment.authorized + refund.created]
    F --> H[Create or update Razorpay webhook via API]
    G --> H
```

## Supported Subscription Events in eventsArray

```php
protected $eventsArray = [
    'payment.authorized',
    'virtual_account.credited',
    'refund.created',
    'payment.failed',
    'payment.pending',
    'subscription.cancelled',   // Added when flag set
    'subscription.paused',      // Added when flag set
    'subscription.resumed',     // Added when flag set
    'subscription.charged',     // Added when flag set
];
```

## Notes for Subscription Integration

1. The `notes.woocommerce_order_id` in subscription entity must be set by the subscription plugin when creating the Razorpay subscription
2. Invoice payments (subscription-linked) are explicitly skipped in `paymentAuthorized()`: `if (isset($data['invoice_id'])) return;`
3. The `subscriptionCharged` event payload contains both subscription entity AND payment entity
4. For the `subscription.charged` event, `$razorpayOrderId` is the payment's order_id; for other subscription events, it's set to `"No payment id in subscription event"`
