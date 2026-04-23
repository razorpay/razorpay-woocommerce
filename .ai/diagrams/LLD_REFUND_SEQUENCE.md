# LLD — Refund Sequence Diagram

## Standard Refund Sequence (Admin-Initiated)

```mermaid
sequenceDiagram
    actor Admin as WC Admin
    participant WC as WooCommerce
    participant GW as WC_Razorpay
    participant RZPAPI as Razorpay API
    participant DB as WordPress DB

    Admin->>WC: Click "Refund" on Order Edit Page
    Admin->>WC: Enter amount + reason, click "Refund via Razorpay"
    WC->>GW: process_refund($orderId, $amount, $reason)

    GW->>WC: wc_get_order($orderId)
    WC-->>GW: WC_Order object

    alt No transaction_id on order
        GW-->>WC: WP_Error('error', 'Refund failed: No transaction ID')
        WC-->>Admin: Error message shown
    else Has transaction_id (razorpay_payment_id)
        GW->>GW: $paymentId = $order->get_transaction_id()
        GW->>GW: Build refund data:<br/>{amount: round(amount*100), notes: {reason, order_id, source}}
        GW->>RZPAPI: POST /v1/payments/{paymentId}/refund<br/>{amount: paise, notes: {...}}
        
        alt Refund Success
            RZPAPI-->>GW: {id: "rfnd_xxx", speed_requested, speed_processed, ...}
            GW->>WC: add_order_note("Refund Id: rfnd_xxx")
            GW->>WC: do_action('woo_razorpay_refund_success', 'rfnd_xxx', orderId, $refund)
            GW->>GW: rzpLogInfo(refund details)
            GW-->>WC: true
            WC->>DB: Create WC refund record
            WC-->>Admin: Refund successful message
        else Refund Failed
            RZPAPI-->>GW: Exception (e.g., payment already refunded)
            GW->>GW: rzpTrackDataLake('razorpay.refund.failed')
            GW-->>WC: WP_Error('error', $e->getMessage())
            WC-->>Admin: Error message shown
        end
    end
```

## Gift Card Refund Sequence (Programmatic)

```mermaid
sequenceDiagram
    participant GW as WC_Razorpay
    participant GC as Gift Card Plugin (YITH/PW)
    participant RZPAPI as Razorpay API
    participant WC as WooCommerce
    participant DB as WordPress DB
    actor Customer as Customer Browser

    Note over GW: Called during update1ccOrderWC()<br/>when gift card deduction fails

    GW->>GC: Check gift card balance / status
    GC-->>GW: Insufficient balance / card invalid

    GW->>GW: processRefundForOrdersWithGiftCard(orderId, paymentId, amount, reason)
    GW->>RZPAPI: POST /v1/payments/{paymentId}/refund<br/>{amount: paise_amount, notes: {...}}
    RZPAPI-->>GW: {id: "rfnd_yyy", ...}
    GW->>WC: wc_create_refund({amount, reason, order_id, refund_id, refund_payment: false})
    WC->>DB: Create WC refund record
    GW->>WC: add_order_note("Refund Id: rfnd_yyy")
    GW->>GW: add_notice("Payment refunded", "error")
    GW-->>Customer: wp_redirect(wc_get_cart_url())
    Note over GW: exit; — stops further execution
```

## Refund via `refund.created` Webhook

```mermaid
sequenceDiagram
    participant RZP as Razorpay Platform
    participant WH as RZP_Webhook
    participant WC as WooCommerce
    participant DB as WordPress DB

    Note over RZP: Refund initiated from Razorpay dashboard<br/>or via API (not from WooCommerce)

    RZP->>WH: POST webhook: event=refund.created<br/>{payload: {refund: {entity: {...}}}}
    WH->>WH: verifyWebhookSignature()
    WH->>WH: refundedCreated($data)
    Note over WH: Adds order note only<br/>Does NOT change order status<br/>Does NOT create WC refund
    WH->>WC: add_order_note(refund details)
    WC->>DB: Save order note
    WH-->>RZP: 200 OK
```
