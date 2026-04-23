# Payment Flow — Razorpay WooCommerce Plugin

## Overview

The plugin implements the **Razorpay Standard Checkout** (modal-based) flow and a **Redirect (Hosted) Checkout** flow depending on merchant preferences fetched from the Razorpay API. There is also a separate **Magic Checkout (1CC)** flow.

---

## Standard Checkout Flow (Modal)

### Step 1 — Customer Places Order

1. Customer adds items to cart and proceeds to WooCommerce checkout.
2. Customer selects "Razorpay" as the payment method.
3. Customer clicks "Place Order".
4. WooCommerce calls `WC_Razorpay::process_payment($order_id)`.

**`process_payment()` behavior:**
- Sets a transient `razorpay_wc_order_id` → `$order_id` (TTL: 3600s).
- Returns `['result' => 'success', 'redirect' => checkout_payment_url]`.
- WooCommerce redirects the browser to the **payment/receipt page**.

---

### Step 2 — Receipt Page (Payment Form)

WC fires `woocommerce_receipt_razorpay` → `WC_Razorpay::receipt_page($orderId)`.

`receipt_page()` calls `generate_razorpay_form($orderId)`:

1. `getRazorpayPaymentParams($order, $orderId)` → `createOrGetRazorpayOrderId(...)`.
2. **`createOrGetRazorpayOrderId()`** logic:
   - Checks order meta for existing Razorpay order ID (key = `razorpay_order_id{$orderId}` or `razorpay_order_id_1cc{$orderId}` for Magic Checkout orders).
   - If found, calls `verifyOrderAmount()` to confirm amount/currency matches.
   - If missing or mismatch → calls `createRazorpayOrderId()`.

3. **`createRazorpayOrderId()`**:
   - Builds `$data` via `getOrderCreationData($orderId)`:
     ```php
     [
       'receipt'         => (string) $orderId,
       'amount'          => (int) round($order->get_total() * 100),  // paise
       'currency'        => $order->get_currency(),
       'payment_capture' => 1 (capture) or 0 (authorize),
       'app_offer'       => 1 if discount > 0,
       'notes'           => ['woocommerce_order_id' => ..., 'woocommerce_order_number' => ...]
     ]
     ```
   - If Route is enabled, merges transfer data into order creation payload.
   - If Magic Checkout order, adds `line_items_total` and `line_items[]` via `orderArg1CC()`.
   - Calls `$api->order->create($data)` → Razorpay Orders API `POST /orders`.
   - Saves `razorpay_order_id` in order meta (HPOS-aware).
   - Inserts record into `{prefix}rzp_webhook_requests` table with status `0` (created).
   - Adds order note: `"Razorpay OrderId: {rzp_order_id}"`.

4. Fetches merchant preferences: `GET /preferences` (public API, no secret).
5. If `options.redirect === true` → **Hosted Checkout** (form POST to Razorpay hosted page).
6. Otherwise → **Modal Checkout**: enqueues `script.js` and checkout args as JS variable `razorpay_wc_checkout_vars`.

**Modal Form HTML rendered:**
```html
<form name='razorpayform' action="{callbackUrl}" method="POST">
  <input type="hidden" name="razorpay_payment_id" id="razorpay_payment_id">
  <input type="hidden" name="razorpay_signature" id="razorpay_signature">
  <input type="hidden" name="razorpay_wc_form_submit" value="1">
</form>
<button id="btn-razorpay">Pay Now</button>
<button id="btn-razorpay-cancel">Cancel</button>
```

The `script.js` JavaScript:
- Opens the Razorpay checkout modal using `razorpay_wc_checkout_vars`.
- On payment success: fills hidden form fields with `razorpay_payment_id` + `razorpay_signature` and submits the form to `callbackUrl`.
- On dismiss/cancel: submits the form without payment fields (triggers cancel path).

---

### Step 3 — Callback Handling

`callbackUrl` = `{site_url}/?wc-api=razorpay&order_key={key}`

WooCommerce routes this to `WC_Razorpay::check_razorpay_response()`.

**Logic flow:**

```
1. Resolve order from order_key (HPOS or post_meta lookup)
2. If order is draft/checkout-draft → update to wc-pending
3. If order already paid → redirectUser() (idempotency)
4. If POST contains razorpay_payment_id:
   a. Call verifySignature($orderId):
      - HMAC: SHA256(razorpay_order_id + "|" + razorpay_payment_id) vs razorpay_signature
   b. On success: success = true, extract payment id
   c. On SignatureVerificationError: success = false, track error
5. If no payment id in POST:
   a. If razorpay_wc_form_submit == 1: "Customer cancelled"
   b. Else: "Payment Failed"
   c. For 1CC order: fetch Razorpay order, UpdateOrderAddress()
   d. handleErrorCase(), updateOrder(false, ...) → redirect to cart/checkout
6. updateOrder($order, $success, $error, $paymentId)
7. If success: update rzp_webhook_requests status to 1 (processed by callback)
8. redirectUser($order) → WC thank-you page
```

---

### Step 4 — Order Update (`updateOrder`)

```php
if ($success === true) {
    // 1CC order: update1ccOrderWC() — address, shipping, COD fee, promotions
    if ($payment_method === 'cod') {
        $order->update_status('processing');
    } else {
        $order->payment_complete($razorpayPaymentId); // sets status to 'processing'
    }
    // Handle CartBounty/WATI recovery plugins
    // Add order note: "Razorpay payment successful"
    // If Route enabled: transferFromPayment()
    $woocommerce->cart->empty_cart();
} else {
    $order->update_status('failed');
    $order->add_order_note("Transaction Failed: $errorMessage");
}
```

---

## Hosted Checkout Flow (Redirect)

When `GET /preferences` returns `options.redirect = true`:

1. `hostCheckoutScripts($data)` generates an HTML form with all checkout args as hidden inputs.
2. Form POSTs to `Api::getFullUrl("checkout/embedded")` (Razorpay hosted page).
3. Razorpay redirects back to `callback_url` after payment.
4. Same `check_razorpay_response()` handling as modal flow.

---

## Magic Checkout (1CC) Flow

See [SUBSCRIPTION_FLOW.md](SUBSCRIPTION_FLOW.md) for subscription-specific 1CC details.

### Key Differences from Standard Flow:

1. **Order creation happens server-side via REST API** (`POST /wp-json/1cc/v1/order/create`) before the payment modal opens.
2. The WC order is created in `checkout-draft` / `draft` status.
3. `is_magic_checkout_order` meta is set to `yes`.
4. `createOrGetRazorpayOrderId($order, $orderId, 'yes')` is called → includes `line_items_total` and `line_items[]` in Razorpay order payload.
5. On payment success, `update1ccOrderWC()` is called to:
   - Fetch Razorpay order data → update WC order address (billing/shipping).
   - Apply shipping fees from Razorpay order.
   - Apply COD fee if payment method is `cod`.
   - Handle promotions (coupons, gift cards, Terra Wallet).
   - Call `POST 1cc/orders/cod/convert` for COD orders.
6. Session key stored as `razorpay_order_id_1cc{$orderId}`.

### Minimum Cart Amount Check

If cart total < `1cc_min_cart_amount` setting, the order creation REST call returns HTTP 400.

---

## Currency Handling

- Amounts are always converted to **smallest currency unit** (paise for INR): `(int) round($total * 100)`.
- Currencies **KWD**, **OMR**, **BHD** are explicitly blocked (3-decimal currencies).
- The currency is taken from `$order->get_currency()` (WC order's configured currency).
- Default label/description changes for US, MY, SG store base locations.

---

## Payment Actions

| Setting | Razorpay Behavior | `payment_capture` value |
|---|---|---|
| `capture` (default) | Auto-capture after authorization | `1` |
| `authorize` | Only authorize, manual capture from dashboard | `0` |

When a `payment.authorized` webhook arrives and the action is `capture`, the webhook cron will call `$payment->capture(['amount' => $amount])`.

---

## Error Handling

| Error Type | Response |
|---|---|
| API unreachable | Exception → "RAZORPAY ERROR: Razorpay API could not be reached" |
| `BadRequestError` from order creation | Message shown to customer |
| Other exceptions | Generic "Payment failed" shown to customer |
| Signature verification failure | Order stays failed, event tracked to DataLake |
| Invalid key/secret | Admin notice shown, webhook not created |

---

## Webhook vs. Callback

The plugin uses a **dual processing** approach:

- **Callback** (browser redirect): Primary path. `check_razorpay_response()` verifies signature and marks order paid immediately.
- **Webhook** (server-to-server): Fallback path. `payment.authorized` events are saved to `rzp_webhook_requests` table. A cron job (`one_cc_address_sync_cron`) processes unprocessed entries where callback didn't fire.

The `rzp_update_order_cron_status` column tracks this:
- `0` = order created (callback not received)
- `1` = processed by callback (skip webhook)
