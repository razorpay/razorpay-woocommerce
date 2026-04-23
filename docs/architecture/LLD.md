# Low Level Design (LLD) - Razorpay WooCommerce Plugin

## 1. Standard Payment Flow - Sequence Diagram

```mermaid
sequenceDiagram
    actor Customer
    participant WC as WooCommerce
    participant RZP as WC_Razorpay
    participant DB as WordPress DB
    participant RZPAPI as Razorpay API
    participant JS as checkout.js

    Customer->>WC: Submit checkout form
    WC->>RZP: process_payment($order_id)
    RZP->>DB: set_transient(SESSION_KEY, $order_id, 3600)
    RZP-->>WC: return ['result'=>'success', 'redirect'=>$paymentUrl]
    WC-->>Customer: Redirect to payment page

    Customer->>WC: Load payment receipt page
    WC->>RZP: receipt_page($orderId)
    RZP->>RZP: generate_razorpay_form($orderId)
    RZP->>RZP: getRazorpayPaymentParams($order, $orderId)
    RZP->>RZP: createOrGetRazorpayOrderId($order, $orderId)

    Note over RZP,DB: Check for existing valid Razorpay order
    RZP->>DB: get_post_meta($orderId, 'razorpay_order_id'+$orderId)
    alt No existing order or amount mismatch
        RZP->>RZPAPI: POST /v1/orders {receipt, amount, currency, payment_capture, notes}
        RZPAPI-->>RZP: {id: 'order_xxx', amount, currency}
        RZP->>DB: update_post_meta($orderId, sessionKey, $razorpayOrderId)
        RZP->>DB: INSERT wp_rzp_webhook_requests (order_id, rzp_order_id, status=0)
        RZP->>WC: add_order_note("Razorpay OrderId: $razorpayOrderId")
    else Valid order exists
        RZP->>RZPAPI: GET /v1/orders/{id} (verify amount)
        RZPAPI-->>RZP: Order data
    end

    RZP->>RZPAPI: GET /preferences (check redirect mode)
    RZPAPI-->>RZP: {options: {redirect: bool}}

    alt redirect = false (modal mode)
        RZP-->>Customer: HTML form + enqueue script.js + checkout.js
        Customer->>JS: Page loads, Razorpay.open() called
        JS->>Customer: Payment modal opens
    else redirect = true (hosted mode)
        RZP-->>Customer: Form POST to checkout.razorpay.com
        Customer->>Customer: Hosted checkout page
    end

    Customer->>RZPAPI: Complete payment
    RZPAPI-->>JS: Payment success event {payment_id, signature}
    JS->>WC: Form POST to callback URL {razorpay_payment_id, razorpay_signature, razorpay_wc_form_submit=1}

    WC->>RZP: check_razorpay_response() via woocommerce_api_razorpay hook
    RZP->>DB: Query order by order_key
    RZP->>RZP: verifySignature($orderId)
    RZP->>DB: get_post_meta($orderId, sessionKey) -> razorpay_order_id
    Note over RZP,RZPAPI: HMAC-SHA256(razorpay_order_id + "|" + payment_id, key_secret)
    RZP->>RZPAPI: SDK: api->utility->verifyPaymentSignature()
    RZPAPI-->>RZP: Valid / SignatureVerificationError

    alt Signature Valid
        RZP->>RZP: updateOrder($order, true, '', $paymentId)
        RZP->>WC: order->payment_complete($razorpayPaymentId)
        RZP->>WC: cart->empty_cart()
        RZP->>DB: UPDATE rzp_webhook_requests SET status=1
        RZP->>WC: add_order_note("Razorpay payment successful")
        RZP-->>Customer: Redirect to thank you page
    else Signature Invalid
        RZP->>RZP: updateOrder($order, false, $error)
        RZP->>WC: order->update_status('failed')
        RZP-->>Customer: Redirect to checkout with error
    end
```

---

## 2. Razorpay Route / Magic Checkout (1CC) Flow - Sequence Diagram

```mermaid
sequenceDiagram
    actor Customer
    participant BTN as btn-1cc-checkout.js
    participant WP as WordPress REST API
    participant RZP as WC_Razorpay
    participant WC as WooCommerce
    participant DB as WordPress DB
    participant RZPAPI as Razorpay API

    Customer->>BTN: Click Magic Checkout button
    BTN->>WP: POST /wp-json/1cc/v1/order/create {cookies, cart_data}
    Note over WP: X-WP-Nonce header for auth

    WP->>WP: checkAuthCredentials()
    WP->>RZP: createWcOrder($request)
    RZP->>WC: initCartCommon() - init session, customer, cart
    RZP->>WC: WC()->checkout()->create_order([])
    WC-->>RZP: $orderId

    RZP->>DB: updateOrderStatus($orderId, 'checkout-draft')
    RZP->>DB: update_meta 'is_magic_checkout_order' = 'yes'
    RZP->>WC: order->remove_item(shipping items)
    RZP->>WC: order->calculate_totals()
    RZP->>RZP: createOrGetRazorpayOrderId($order, $orderId, '1cc')

    Note over RZP,RZPAPI: 1CC Order has line_items array
    RZP->>RZPAPI: POST /v1/orders {line_items_total, line_items[], receipt, currency}
    RZPAPI-->>RZP: {id: 'order_xxx'}

    RZP->>DB: update_meta sessionKey -> razorpay_order_id_1cc{orderId} = razorpay_order_id
    RZP-->>WP: {status: true, orderId, razorpay_order_id, ...}
    WP-->>BTN: Order created response

    BTN->>WP: POST /wp-json/1cc/v1/shipping/shipping-info {order_id, addresses[]}
    WP->>WP: calculateShipping1cc($request)
    WP->>WC: cart->empty_cart()
    WP->>WC: create1ccCart($orderId) - rebuild cart from order items
    WP->>WC: shippingUpdateCustomerInformation1cc($address)
    WP->>WC: shippingCalculatePackages1cc() - WC shipping calculation
    WC-->>WP: Available shipping rates with costs
    WP-->>BTN: Shipping options array

    BTN->>Customer: Show Razorpay checkout with address + shipping
    Customer->>RZPAPI: Fill address, select shipping, pay

    RZPAPI-->>WC: POST callback {razorpay_payment_id, razorpay_signature}
    WC->>RZP: check_razorpay_response()
    RZP->>RZP: verifySignature($orderId)
    Note over RZP: Uses razorpay_order_id_1cc{orderId} session key
    RZP->>RZPAPI: verifyPaymentSignature()

    alt Signature Valid
        RZP->>DB: get_meta 'is_magic_checkout_order'
        Note over RZP: is_magic_checkout_order = 'yes'
        RZP->>RZP: update1ccOrderWC($order, $orderId, $paymentId)
        RZP->>RZPAPI: GET /v1/orders/{razorpay_order_id} - fetch order data
        RZPAPI-->>RZP: {shipping_fee, cod_fee, notes:{address,gstin}, offers[]}
        RZP->>RZP: UpdateOrderAddress($razorpayData, $order)
        RZP->>WC: order->set_billing_address / set_shipping_address
        RZP->>WC: Add shipping fee item to order
        RZP->>RZPAPI: GET /v1/payments/{paymentId} - check payment method
        RZPAPI-->>RZP: {method: 'card'|'cod'|'upi'|...}
        alt method = 'cod'
            RZP->>WC: order->set_payment_method('cod')
            RZP->>WC: Add COD fee item to order
        end
        RZP->>RZP: handlePromotions($razorpayData, $order)
        Note over RZP: Apply coupons, gift cards, Terra wallet from RZP order
        RZP->>WC: order->payment_complete($paymentId)
        RZP-->>Customer: Redirect to thank you page
    end
```

---

## 3. Subscription Creation Flow - Sequence Diagram

```mermaid
sequenceDiagram
    actor Customer
    participant WC as WooCommerce
    participant SUBPLUGIN as Subscription Plugin
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API
    participant DB as WordPress DB

    Customer->>WC: Purchase subscription product
    WC->>SUBPLUGIN: Create subscription order
    SUBPLUGIN->>RZP: process_payment (subscription-aware)
    RZP->>RZPAPI: POST /v1/subscriptions {plan_id, total_count, ...}
    RZPAPI-->>RZP: {id: 'sub_xxx', status: 'created'}
    RZP->>DB: Store subscription_id in order meta
    RZP-->>WC: Redirect to checkout page with subscription link
    Customer->>RZPAPI: Complete payment via checkout
    RZPAPI-->>WC: Callback with subscription payment_id + signature
    WC->>RZP: check_razorpay_response()
    RZP->>RZP: verifySignature()
    RZP->>WC: updateOrder() - mark initial payment complete
    RZPAPI->>DB: Register webhook for subscription.charged events
```

---

## 4. Subscription Renewal Flow - Sequence Diagram

```mermaid
sequenceDiagram
    participant RZPAPI as Razorpay API
    participant WH as RZP_Webhook
    participant DB as WordPress DB
    participant WC as WooCommerce
    participant SUBPLUGIN as Subscription Plugin

    Note over RZPAPI: Renewal date arrives
    RZPAPI->>RZPAPI: Auto-charge subscription payment
    RZPAPI->>WH: POST webhook {event: subscription.charged, payload: {subscription, payment}}
    WH->>WH: process() - parse JSON
    WH->>WH: shouldConsumeWebhook() - validate
    WH->>WH: verifyWebhookSignature()
    WH->>WH: subscriptionCharged($data) - no-op in base plugin
    Note over WH,SUBPLUGIN: Companion subscription plugin handles via its own hook
    SUBPLUGIN->>WC: Create renewal order
    SUBPLUGIN->>DB: Update subscription status
    SUBPLUGIN->>WC: Mark renewal order complete
```

---

## 5. Refund Flow - Sequence Diagram

```mermaid
sequenceDiagram
    actor Admin
    participant WC as WooCommerce
    participant RZP as WC_Razorpay
    participant RZPAPI as Razorpay API

    Note over Admin,WC: WC Admin-Initiated Refund
    Admin->>WC: Click Refund in order admin
    WC->>RZP: process_refund($orderId, $amount, $reason)
    RZP->>WC: wc_get_order($orderId)
    RZP->>WC: order->get_transaction_id() -> $paymentId
    alt No transaction ID
        RZP-->>WC: return WP_Error('Refund failed: No transaction ID')
    end
    RZP->>RZPAPI: payment->fetch($paymentId)->refund({amount_paise, notes})
    Note over RZP,RZPAPI: notes: {reason, order_id, refund_from_website: true, source: woocommerce}
    RZPAPI-->>RZP: {id: 'rfnd_xxx', speed_requested, speed_processed}
    RZP->>WC: order->add_order_note("Refund Id: rfnd_xxx")
    RZP->>WC: do_action('woo_razorpay_refund_success', refund_id, orderId, refund)
    RZP-->>WC: return true
    WC-->>Admin: Refund recorded

    Note over RZPAPI,WC: Webhook-Initiated External Refund
    RZPAPI->>WH: POST {event: refund.created, payload: {refund, payment}}
    WH->>WH: refundedCreated($data)
    WH->>WH: Check invoice_id not set (skip subscriptions)
    WH->>WH: Check refund_from_website not true (avoid duplicates)
    WH->>RZPAPI: payment->fetch($razorpayPaymentId)
    RZPAPI-->>WH: Payment data with notes.woocommerce_order_id
    WH->>WC: wc_get_order($orderId)
    WH->>WH: Check order->needs_payment() == false
    WH->>WC: wc_create_refund({amount, reason, order_id, refund_id})
    WH->>WC: order->add_order_note("Refund Id: rfnd_xxx")
```

---

## 6. Webhook Processing - Sequence Diagram

```mermaid
sequenceDiagram
    participant RZPAPI as Razorpay Platform
    participant WP as WordPress
    participant WH as RZP_Webhook
    participant DB as WordPress DB
    participant WC as WooCommerce
    participant CRON as WP Cron

    RZPAPI->>WP: POST admin-post.php?action=rzp_wc_webhook
    Note over WP: HTTP_X_RAZORPAY_SIGNATURE header present
    WP->>WH: razorpay_webhook_init()
    WH->>WH: new RZP_Webhook()
    WH->>WH: process()
    WH->>WH: file_get_contents('php://input') -> $post
    WH->>WH: json_decode($post) -> $data
    WH->>WH: shouldConsumeWebhook($data)
    Note over WH: Validates event is in eventsArray AND woocommerce_order_id present

    WH->>DB: get_option('webhook_secret')
    WH->>WH: api->utility->verifyWebhookSignature($post, $signature, $secret)
    alt Invalid signature
        WH->>WH: rzpLogError("signature verify failed")
        WH-->>RZPAPI: Return (200 OK, no processing)
    end

    alt event = payment.authorized
        WH->>WH: Extract invoice_id, woocommerce_order_id, payment_id
        WH->>DB: SELECT from rzp_webhook_requests WHERE order_id AND rzp_order_id
        WH->>DB: UPDATE rzp_webhook_requests SET rzp_webhook_data=JSON, notified_at=now()
        WH-->>RZPAPI: Return

        CRON->>WH: paymentAuthorized($data) [scheduled later]
        WH->>WC: wc_get_order($orderId)
        WH->>WH: Check order->needs_payment()
        WH->>RZPAPI: payment->fetch($razorpayPaymentId)
        RZPAPI-->>WH: Payment entity {status, amount, method}
        alt status = captured
            WH->>WC: updateOrder(order, true, '', paymentId, null, webhook=true)
        else status = authorized AND payment_action = capture
            WH->>RZPAPI: payment->capture({amount})
            RZPAPI-->>WH: Capture result
            WH->>WC: updateOrder(order, true, '', paymentId, null, webhook=true)
        end
        WC->>WC: order->payment_complete($paymentId)

    else event = virtual_account.credited
        WH->>WH: virtualAccountCredited($data)
        WH->>RZPAPI: payment->fetch($paymentId)
        WH->>WH: Check amount_paid == order_amount
        WH->>WC: updateOrder(order, success, '', paymentId, virtualAccountId, true)
        WH-->>RZPAPI: exit()

    else event = refund.created
        WH->>WH: refundedCreated($data)
        WH->>WC: wc_create_refund(...)
        WH-->>RZPAPI: exit()

    else event = payment.pending (COD)
        WH->>WH: paymentPending($data)
        WH->>WC: updateOrder(order, true, '', paymentId, null, true)
        WH-->>RZPAPI: exit()
    end
```

---

## 7. Order Status Update Flow - Sequence Diagram

```mermaid
sequenceDiagram
    participant CALLER as Caller (callback/webhook)
    participant RZP as WC_Razorpay.updateOrder()
    participant DB as WordPress DB
    participant WC as WooCommerce
    participant CART as WC Cart
    participant RACT as RZP_Route_Action

    CALLER->>RZP: updateOrder($order, $success, $errorMsg, $paymentId, $virtualAccountId, $webhook)
    RZP->>WC: order->get_id() -> $orderId

    alt success = true
        RZP->>DB: get_meta 'is_magic_checkout_order'
        alt is 1CC order
            RZP->>DB: get_transient('wc_order_under_process_' + orderId)
            alt Not already processing
                RZP->>DB: set_transient('wc_order_under_process_' + orderId, true, 300)
                RZP->>RZP: update1ccOrderWC($order, $orderId, $paymentId)
                Note over RZP: Syncs address, shipping, COD, promotions
            end
        end

        RZP->>WC: order->get_payment_method()
        alt payment_method = 'cod'
            RZP->>WC: order->update_status('processing')
        else
            RZP->>WC: order->payment_complete($paymentId)
        end

        alt Route enabled
            RZP->>RACT: transferFromPayment($orderId, $paymentId)
            RACT->>RACT: api->payment->transfer(...)
        end

        alt virtualAccountId present
            RZP->>WC: order->add_order_note("Virtual Account Id: ...")
        end

        alt Cart exists
            RZP->>CART: cart->empty_cart()
        end

        RZP->>WC: order->add_order_note("Razorpay payment successful")
        RZP->>RZP: msg = ['class'=>'success', 'message'=>SUCCESS_MSG]

    else success = false
        RZP->>RZP: msg = ['class'=>'error', 'message'=>$errorMsg]
        RZP->>WC: order->add_order_note("Transaction Failed: $errorMsg")
        RZP->>WC: order->update_status('failed')
    end

    alt webhook = false (callback mode)
        RZP->>WC: add_notice($msg['message'], $msg['class'])
    end
```
