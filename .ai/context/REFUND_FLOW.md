# Refund Flow — Razorpay WooCommerce Plugin

## Overview

The plugin supports two refund paths:
1. **Standard Refund** — Initiated from WooCommerce admin order page via `process_refund()`.
2. **Gift Card Refund** — Initiated programmatically when gift card operations fail during Magic Checkout order processing.

The `supports` array in `WC_Razorpay` includes `'refunds'`, which tells WooCommerce to show the refund UI.

---

## Standard Refund Flow

Triggered when an admin clicks "Refund" on the WC order page.

### Entry Point

```php
WC_Razorpay::process_refund($orderId, $amount = null, $reason = '')
```

### Steps

1. **Validate:** Check that the order exists and has a `transaction_id` (Razorpay Payment ID).

   ```php
   $paymentId = $order->get_transaction_id(); // razorpay payment id stored by payment_complete()
   ```

2. **Build refund payload:**
   ```php
   $data = [
       'amount' => (int) round($amount * 100),  // convert to paise
       'notes'  => [
           'reason'              => $reason,
           'order_id'            => $orderId,
           'refund_from_website' => true,
           'source'              => 'woocommerce',
       ]
   ];
   ```

3. **Call Razorpay API:**
   ```php
   $refund = $client->payment->fetch($paymentId)->refund($data);
   // POST /payments/{payment_id}/refund
   ```

4. **On success:**
   - Add order note: `"Refund Id: {refund_id}"`.
   - Fire custom action: `do_action('woo_razorpay_refund_success', $refund->id, $orderId, $refund)`.
   - Log: refund ID, speed_requested, speed_processed.
   - Return `true`.

5. **On failure:**
   - Track error to DataLake (`razorpay.refund.failed`).
   - Return `WP_Error('error', $e->getMessage())`.

### Refund Amounts

- Razorpay supports **partial refunds** — the `$amount` parameter can be less than the full payment.
- Full refund is when `$amount === $order->get_total()`.
- WooCommerce handles the refund amount validation before calling `process_refund()`.

---

## Gift Card Refund Flow

Triggered programmatically when gift card deduction fails during `handlePromotions()`.

### Entry Point

```php
WC_Razorpay::processRefundForOrdersWithGiftCard($orderId, $razorpayPaymentId, $amount, $reason)
```

### When It Is Called

- YITH Gift Card plugin not active but gift card promotion applied.
- Gift card has insufficient balance.
- Gift card is trashed/deleted.
- PW Gift Card not found in DB.
- Terra Wallet plugin not active.
- Terra Wallet has insufficient balance.
- Terra Wallet transaction fails.

### Steps

1. **Call Razorpay API** (same as standard refund):
   ```php
   $refund = $client->payment->fetch($razorpayPaymentId)->refund($data);
   ```
   Note: `$amount` here is in paise (already converted), not rupees.

2. **Create WC Refund record:**
   ```php
   wc_create_refund([
       'amount'         => $amount,
       'reason'         => $reason,
       'order_id'       => $orderId,
       'refund_id'      => $refund->id,
       'line_items'     => [],
       'refund_payment' => false,  // don't call process_refund again
   ]);
   ```

3. Add order note: `"Refund Id: {refund_id}"`.

4. Redirect to cart URL: `wp_redirect(wc_get_cart_url())`.

---

## Webhook `refund.created` Event

When Razorpay sends a `refund.created` webhook:

```php
protected function refundedCreated(array $data)
{
    // Adds an order note with refund details
    // Does NOT change order status
}
```

This is primarily for audit trail purposes. The actual refund is already initiated from WooCommerce admin.

---

## Refund Notes and Caveats

| Scenario | Behavior |
|---|---|
| No transaction ID on order | Returns `WP_Error('error', 'Refund failed: No transaction ID')` |
| Razorpay API error | Returns `WP_Error('error', $e->getMessage())` |
| Partial refund | Supported — amount passed in rupees, converted to paise |
| Full refund | Supported — WC manages the order status change to `refunded` |
| Double refund | Razorpay will reject with error (payment already refunded) |
| Gift card scenario | Separate flow — refunds in paise, creates WC refund record |

---

## Custom Action Hook

```php
do_action('woo_razorpay_refund_success', $refund->id, $orderId, $refund);
```

Third-party plugins can hook into this action to perform post-refund operations (e.g., restore loyalty points, update inventory systems).

Parameters:
- `$refund->id` — Razorpay Refund ID (string, e.g., `rfnd_AbcXyz123`)
- `$orderId` — WooCommerce Order ID (int)
- `$refund` — Razorpay Refund object

---

## Razorpay Refund API

**Endpoint:** `POST /payments/{payment_id}/refund`

**Request Body:**
```json
{
  "amount": 10000,
  "notes": {
    "reason": "Customer request",
    "order_id": "123",
    "refund_from_website": true,
    "source": "woocommerce"
  }
}
```

**Response Fields Used:**
- `refund.id` — Refund ID for order note
- `refund.speed_requested` — `normal` or `optimum`
- `refund.speed_processed` — Actual processing speed
