# Refund Flow - Razorpay WooCommerce

## Overview

Refunds can be initiated in two ways:
1. **WC Admin-Initiated**: Admin clicks "Refund" in the WooCommerce order screen
2. **Webhook-Initiated**: Refund created in Razorpay Dashboard, synced to WC via `refund.created` webhook

## Flow 1: WC Admin-Initiated Refund

```mermaid
sequenceDiagram
    actor Admin
    participant WC as WooCommerce Admin
    participant RZP as WC_Razorpay.process_refund()
    participant RZPAPI as Razorpay API

    Admin->>WC: Open order, click "Refund"
    Admin->>WC: Enter amount + reason, click "Refund via Razorpay"
    WC->>RZP: process_refund($orderId, $amount, $reason)

    RZP->>WC: wc_get_order($orderId)
    RZP->>WC: order->get_transaction_id() -> $paymentId

    alt No transaction ID
        RZP-->>WC: WP_Error("Refund failed: No transaction ID")
        WC-->>Admin: Error shown
    end

    RZP->>RZPAPI: payment->fetch($paymentId)
    RZP->>RZPAPI: payment->refund({amount: paise, notes: {reason, order_id, refund_from_website: true, source: woocommerce}})

    alt Refund Success
        RZPAPI-->>RZP: {id: rfnd_xxx, speed_requested, speed_processed}
        RZP->>WC: order->add_order_note("Refund Id: rfnd_xxx")
        RZP->>WC: do_action('woo_razorpay_refund_success', refund_id, orderId, refund)
        RZP-->>WC: return true
        WC-->>Admin: Refund recorded successfully
    else Refund Failed
        RZPAPI-->>RZP: Exception
        RZP->>RZP: rzpTrackDataLake('razorpay.refund.failed')
        RZP-->>WC: WP_Error($e->getMessage())
        WC-->>Admin: Error message shown
    end
```

## Flow 2: Webhook-Initiated Refund (External)

```mermaid
sequenceDiagram
    participant RZPDASH as Razorpay Dashboard
    participant RZPAPI as Razorpay Platform
    participant WH as RZP_Webhook.refundedCreated()
    participant WC as WooCommerce

    RZPDASH->>RZPAPI: Admin initiates refund from Razorpay Dashboard
    RZPAPI->>WH: POST webhook {event: refund.created, payload: {refund, payment}}

    WH->>WH: refundedCreated($data)
    WH->>WH: Check payload.payment.entity.invoice_id
    alt invoice_id present (subscription)
        WH-->>RZPAPI: return (skip - subscription payment)
    end

    WH->>WH: Check payload.refund.entity.notes.refund_from_website
    alt refund_from_website = true
        WH-->>RZPAPI: return (skip - already refunded via WC)
    end

    WH->>WH: $razorpayPaymentId = payload.refund.entity.payment_id
    WH->>RZPAPI: payment->fetch($razorpayPaymentId)
    RZPAPI-->>WH: Payment entity with notes.woocommerce_order_id

    WH->>WC: wc_get_order($orderId)
    WH->>WC: order->needs_payment()
    alt order still needs payment (not paid yet)
        WH-->>RZPAPI: return (skip - order not yet paid)
    end

    WH->>WH: $refundAmount = payload.refund.entity.amount / 100
    WH->>WH: $refundReason = payload.refund.entity.notes.comment
    WH->>WC: wc_create_refund({amount, reason, order_id, refund_id, line_items: [], refund_payment: false})
    WH->>WC: order->add_order_note("Refund Id: rfnd_xxx")
    WH-->>RZPAPI: exit()
```

## Special Case: Gift Card Orders

For orders containing gift cards, a separate refund function handles the flow:

```mermaid
sequenceDiagram
    actor Customer
    participant WC as WooCommerce
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API

    Customer->>WC: Request refund for gift card order
    WC->>RZP: processRefundForOrdersWithGiftCard($orderId, $razorpayPaymentId, $amount, $reason)
    RZP->>RZPAPI: payment->fetch($paymentId)->refund({amount_paise, notes})
    RZPAPI-->>RZP: {id: rfnd_xxx}
    RZP->>WC: wc_create_refund({amount, reason, order_id, refund_id})
    RZP->>WC: order->add_order_note("Refund Id: rfnd_xxx")
    RZP-->>Customer: wp_redirect(wc_get_cart_url())
```

## Refund Data Structure

### WC Admin Refund Request to Razorpay API
```php
[
    'amount' => (int) round($amount * 100),  // In paise
    'notes'  => [
        'reason'              => $reason,
        'order_id'            => $orderId,
        'refund_from_website' => true,
        'source'              => 'woocommerce',
    ]
]
```

### Razorpay Refund Speed Types
| Speed | Description |
|-------|-------------|
| `normal` | Standard processing (3-5 business days) |
| `optimum` | Fastest available speed |
| `instant` | Instant refund (if payment method supports it) |

## Duplicate Refund Prevention

The `refund_from_website: true` note is the key mechanism to prevent duplicate refunds when both a WC admin refund and a Razorpay webhook fire for the same refund:

1. WC admin initiates refund → Razorpay API called with `refund_from_website: true`
2. Razorpay sends `refund.created` webhook
3. `refundedCreated()` checks `notes.refund_from_website` → skips if `true`

This prevents double-refunds in WooCommerce.
