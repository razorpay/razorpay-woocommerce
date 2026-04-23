# Standard Payment Flow - Razorpay WooCommerce

## Overview

The standard payment flow handles customers who go through the regular WooCommerce checkout process (cart → checkout → payment).

## Sequence Diagram

```mermaid
sequenceDiagram
    actor C as Customer
    participant WC as WooCommerce Checkout
    participant RZPGW as WC_Razorpay Gateway
    participant RZPAPI as Razorpay API
    participant DB as WordPress DB
    participant MODAL as Razorpay Modal/Checkout

    C->>WC: Fill checkout form, click Place Order
    WC->>RZPGW: process_payment($order_id)
    RZPGW->>DB: set_transient(razorpay_wc_order_id, $orderId, 3600)
    RZPGW-->>WC: {result: success, redirect: payment_page_url}
    WC-->>C: 302 Redirect to payment page

    C->>WC: Load payment receipt page (woocommerce/pay/{orderId})
    WC->>RZPGW: receipt_page($orderId) via woocommerce_receipt_razorpay hook
    RZPGW->>RZPGW: generate_razorpay_form($orderId)
    RZPGW->>RZPAPI: Check merchant preferences GET /preferences
    RZPAPI-->>RZPGW: {options: {redirect: false}}

    RZPGW->>RZPAPI: Create/fetch Razorpay order POST /v1/orders
    Note over RZPGW,RZPAPI: Payload: {receipt, amount_paise, currency, payment_capture, app_offer, notes}
    RZPAPI-->>RZPGW: {id: order_xxx, amount, currency, status: created}

    RZPGW->>DB: Store razorpay_order_id in order meta
    RZPGW->>DB: INSERT rzp_webhook_requests (status=0)
    RZPGW-->>C: HTML form + script.js + checkout.js

    C->>MODAL: checkout.js opens Razorpay payment modal
    C->>MODAL: Select UPI/Card/NetBanking/Wallet
    C->>RZPAPI: Complete payment
    RZPAPI-->>MODAL: {razorpay_payment_id, razorpay_order_id, razorpay_signature}

    MODAL->>WC: Form POST to callback_url
    Note over MODAL,WC: {razorpay_payment_id, razorpay_signature, razorpay_wc_form_submit=1}

    WC->>RZPGW: check_razorpay_response() via woocommerce_api_razorpay

    RZPGW->>DB: Lookup order by order_key
    RZPGW->>DB: Get razorpay_order_id from meta
    RZPGW->>RZPGW: verifySignature($orderId)
    Note over RZPGW: HMAC-SHA256(order_id + "|" + payment_id, key_secret)
    RZPGW->>RZPAPI: SDK: utility->verifyPaymentSignature()

    alt Signature Valid
        RZPGW->>RZPGW: updateOrder($order, true, '', $paymentId)
        RZPGW->>WC: order->payment_complete($paymentId)
        RZPGW->>WC: cart->empty_cart()
        RZPGW->>DB: UPDATE rzp_webhook_requests SET status=1
        RZPGW-->>C: Redirect to thank you page
    else Signature Invalid
        RZPGW->>WC: order->update_status('failed')
        RZPGW-->>C: Redirect to checkout with error message
    else Customer Cancelled
        RZPGW->>WC: order->update_status('failed')
        RZPGW-->>C: Redirect to checkout page
    end
```

## Key Functions

| Function | File | Purpose |
|----------|------|---------|
| `process_payment($order_id)` | `woo-razorpay.php` | Entry point, sets transient, returns redirect |
| `receipt_page($orderId)` | `woo-razorpay.php` | Renders payment form |
| `generate_razorpay_form($orderId)` | `woo-razorpay.php` | Generates HTML + JS |
| `createOrGetRazorpayOrderId()` | `woo-razorpay.php` | Creates/fetches Razorpay order |
| `createRazorpayOrderId()` | `woo-razorpay.php` | Calls Razorpay API to create order |
| `verifyOrderAmount()` | `woo-razorpay.php` | Validates existing order amount matches |
| `check_razorpay_response()` | `woo-razorpay.php` | Handles POST callback |
| `verifySignature()` | `woo-razorpay.php` | HMAC verification |
| `updateOrder()` | `woo-razorpay.php` | Marks WC order complete/failed |

## Order Data Sent to Razorpay

```php
[
    'receipt'         => (string)$orderId,
    'amount'          => (int) round($order->get_total() * 100),  // In paise
    'currency'        => 'INR',  // or other supported currency
    'payment_capture' => 1,  // 0 for authorize-only mode
    'app_offer'       => ($order->get_discount_total() > 0) ? 1 : 0,
    'notes'           => [
        'woocommerce_order_id'     => $orderId,
        'woocommerce_order_number' => $order->get_order_number()
    ],
    // If Route enabled:
    'transfers'       => [...transfer_data...]
]
```

## States and Transitions

```mermaid
stateDiagram-v2
    [*] --> Pending: Customer places order
    Pending --> PaymentPage: process_payment() redirect
    PaymentPage --> Processing: Payment successful + signature valid
    PaymentPage --> Failed: Payment failed / cancelled / signature invalid
    Processing --> [*]: Order fulfilled
    Failed --> Pending: Customer retries
```

## Error Scenarios

| Scenario | Handling |
|----------|---------|
| Razorpay API down during order creation | Returns generic "Payment failed" exception |
| BadRequestError from Razorpay | Error message shown to customer |
| Invalid signature on callback | Order marked failed, user redirected to checkout |
| Customer cancels payment | `razorpay_wc_form_submit=1` with no payment_id → order failed |
| Duplicate payment attempt | `order->needs_payment() === false` check skips re-processing |
