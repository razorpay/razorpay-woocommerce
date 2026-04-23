# LLD — Order State Machine Diagram

## WooCommerce Order Status Transitions (Razorpay Plugin)

```mermaid
stateDiagram-v2
    [*] --> pending: WC checkout creates order\nprocess_payment() called

    pending --> processing: payment_complete(payment_id)\n[callback: signature valid]\n[webhook: payment captured]

    pending --> failed: updateOrder(success=false)\n[signature invalid]\n[payment failed]

    pending --> cancelled: Customer cancels order\n[no payment_id in POST]

    processing --> refunded: Admin initiates refund\nprocess_refund() → Razorpay API

    processing --> completed: Merchant marks complete\n[WC standard flow]

    failed --> pending: Customer retries payment\n[new Razorpay order created]

    cancelled --> pending: Customer reopens order\n[rare - typically new order]

    note right of pending
        Plugin creates Razorpay order here
        Stored in: razorpay_order_id{orderId}
        DB: rzp_webhook_requests status=0
    end note

    note right of processing
        Payment ID stored as transaction_id
        DB: rzp_webhook_requests status=1
        Cart is emptied
        Thank-you page shown
    end note

    note right of failed
        Order note added with error
        Customer redirected to checkout
    end note
```

## 1CC Order Status Transitions

```mermaid
stateDiagram-v2
    [*] --> checkout_draft: createWcOrder REST API\nWC checkout()->create_order()

    checkout_draft --> pending: check_razorpay_response\nordered from draft state\n[HPOS: wc-checkout-draft → wc-pending]

    pending --> processing: payment_complete(payment_id)\nAND update1ccOrderWC() completes\n[address, shipping, promotions applied]

    pending --> processing: COD order\nwebhook: payment.pending\n[update_status('processing')]

    pending --> failed: payment cancelled / failed

    processing --> refunded: Admin refund\nOR gift card failure auto-refund

    note right of checkout_draft
        Magic Checkout creates order
        before customer enters details
        is_magic_checkout_order = yes
        Razorpay order includes line_items[]
    end note

    note right of processing
        For COD: payment_method = cod
        update_status('processing') NOT payment_complete
        POST 1cc/orders/cod/convert called
    end note
```

## Order Meta State

```mermaid
flowchart TD
    A[WC Order Created] --> B{Is 1CC?}
    B -->|No| C[is_magic_checkout_order = no\nMeta key: razorpay_order_id{id}]
    B -->|Yes| D[is_magic_checkout_order = yes\nMeta key: razorpay_order_id_1cc{id}]

    C --> E[Razorpay Order Created]
    D --> E

    E --> F[Meta: razorpay_order_id{id} = order_xxx\nDB: rzp_webhook_requests status=0\nNote: Razorpay OrderId: order_xxx]

    F --> G{Payment Outcome}
    G -->|Callback Success| H[Meta: _transaction_id = pay_xxx\nDB: rzp_webhook_requests status=1\nNote: payment successful]
    G -->|Webhook Success| I[Meta: _transaction_id = pay_xxx\nDB: rzp_webhook_requests status=0 stays\nNote: processed through webhook]
    G -->|Failure| J[Note: Transaction Failed\nStatus: failed]

    H --> K[Cart cleared\nRedirect to thank-you]
    I --> K
```

## Concurrent Processing Prevention

```mermaid
sequenceDiagram
    participant CB as Callback Handler
    participant WH as Webhook/Cron Handler
    participant DB as WordPress DB / Transients

    Note over CB, WH: Race condition scenario

    CB->>DB: GET transient wc_order_under_process_{orderId}
    CB->>CB: (transient not set)
    CB->>DB: SET transient wc_order_under_process_{orderId} = true (300s)
    CB->>CB: update1ccOrderWC() - Process 1CC order

    WH->>DB: GET transient wc_order_under_process_{orderId}
    WH->>WH: (transient IS set → skip)
    Note over WH: "To avoid simultaneous update from callback and webhook"

    CB->>DB: UPDATE rzp_webhook_requests status=1
    Note over CB: Transient expires after 300s automatically
```
