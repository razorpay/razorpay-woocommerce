# Skill: Sync Order Status with Razorpay Dashboard

## Purpose
Reconcile a WooCommerce order whose status doesn't match what Razorpay shows in the dashboard. This skill queries the Razorpay API for the actual payment/order state and updates WooCommerce accordingly.

## When to Use
- A WC order shows `pending` or `failed` but the Razorpay dashboard shows the payment as `captured`
- A WC order shows `processing` but Razorpay shows `failed` or `refunded`
- Bulk reconciliation of orders after a webhook outage
- After a server crash/downtime where WC order updates may have been lost
- An order was updated manually in WC without reflecting on Razorpay

## Prerequisites
- The WooCommerce order ID
- The Razorpay Key ID and Key Secret (from WC > Payments > Razorpay settings)
- Read access to WooCommerce order meta and database

---

## Steps

### Step 1 — Get the razorpay_order_id from order meta

The Razorpay order ID is the primary lookup key. It's stored using a composite meta key:

```sql
-- For standard orders:
SELECT meta_value FROM wp_postmeta
WHERE post_id = 1234
  AND meta_key = 'razorpay_order_id1234';  -- note: no separator between key and orderId

-- For 1CC/Magic Checkout orders:
SELECT meta_value FROM wp_postmeta
WHERE post_id = 1234
  AND meta_key = 'razorpay_order_id_1cc1234';
```

Or use `getOrderSessionKey()` from `woo-razorpay.php` (~line 1137):
```php
$sessionKey = $this->getOrderSessionKey($orderId);
$razorpayOrderId = get_post_meta($orderId, $sessionKey, true);
// Returns something like "order_XXXXXXXXXX"
```

Also check for `razorpay_payment_id` which is set after payment capture:
```sql
SELECT meta_value FROM wp_postmeta
WHERE post_id = 1234 AND meta_key = 'razorpay_payment_id';
```

For HPOS stores, use `$order->get_meta('razorpay_payment_id')`.

### Step 2 — Query Razorpay API for order status

Using the Razorpay PHP SDK:

```php
$api = new Api($keyId, $keySecret);

// Fetch the Razorpay order
$rzpOrder = $api->order->fetch($razorpayOrderId);
echo "Razorpay order status: " . $rzpOrder->status;
// possible: created, attempted, paid

echo "Amount authorized: " . $rzpOrder->amount_due . " paise";
echo "Amount paid: " . $rzpOrder->amount_paid . " paise";

// Fetch all payments for this order
$payments = $api->order->payments($razorpayOrderId);
foreach ($payments->items as $payment) {
    echo $payment->id . " — " . $payment->status . " — " . $payment->amount;
    // payment status: created, authorized, captured, refunded, failed
}
```

### Step 3 — Compare WooCommerce status vs Razorpay status

Build a status comparison matrix:

| Razorpay Order Status | Razorpay Payment Status | Expected WC Status | Action Needed |
|---|---|---|---|
| `paid` | `captured` | `processing` or `completed` | Update WC order if pending/failed |
| `paid` | `authorized` | `on-hold` (manual capture) | Enable auto-capture or update WC |
| `attempted` | `failed` | `failed` | Verify WC shows failed |
| `created` | (none) | `pending` | No payment made, WC correct |
| `paid` | `refunded` | `refunded` | Update WC order if not already |

### Step 4 — Update WooCommerce order if needed

Call the existing `updateOrder()` method in `woo-razorpay.php` (~line 2275):

```php
/**
 * Signature: updateOrder(&$order, $success, $errorMessage, $razorpayPaymentId, $virtualAccountId = null, $webhook = false)
 */
$order = wc_get_order($orderId);
$this->updateOrder($order, true, '', $razorpayPaymentId, null, true);
// $success = true means payment succeeded
// $webhook = true marks this as a webhook/external update
```

Or manually update order status with a note:

```php
$order = wc_get_order($orderId);

// Add the payment ID to order meta (HPOS-aware)
if ($this->isHposEnabled) {
    $order->update_meta_data('razorpay_payment_id', $razorpayPaymentId);
    $order->save();
} else {
    update_post_meta($orderId, 'razorpay_payment_id', $razorpayPaymentId);
}

// Update status
$order->payment_complete($razorpayPaymentId);

// Add order note for audit trail
$order->add_order_note(
    "Order synced with Razorpay. Payment ID: $razorpayPaymentId. " .
    "Razorpay status: captured. Synced at: " . current_time('mysql')
);
```

### Step 5 — Update rzp_webhook_requests table if needed

If the webhook table shows `status=0` (unprocessed) for this order, update it to prevent cron from re-processing:

```sql
UPDATE wp_rzp_webhook_requests
SET status = 1
WHERE woocommerce_order_id = 1234
  AND status = 0;
```

Also update `rzp_update_order_cron_status` on the order meta to `1`:
```php
update_post_meta($orderId, 'rzp_update_order_cron_status', 1);
```

Or for HPOS:
```php
$order->update_meta_data('rzp_update_order_cron_status', 1);
$order->save();
```

### Step 6 — Handle the case where WC is ahead of Razorpay

If WC shows `processing` but Razorpay shows `failed`:
- This indicates the payment verification in `check_razorpay_response()` may have accepted a fraudulent signature
- Do NOT automatically downgrade the order status without manual review
- Add an order note flagging the discrepancy and alert the merchant
- Check signature verification logs

If WC shows `refunded` but Razorpay shows `captured`:
- The WC refund may have failed after changing WC status
- Check `process_refund()` logs for the failed API call
- Re-initiate the refund from WC admin

### Step 7 — For bulk reconciliation

For reconciling multiple orders after an outage:

```php
// Query all pending orders older than 1 hour with razorpay as payment method
$args = [
    'status'         => 'pending',
    'payment_method' => 'razorpay',
    'date_before'    => date('Y-m-d H:i:s', strtotime('-1 hour')),
    'limit'          => 50,
];
$orders = wc_get_orders($args);

foreach ($orders as $order) {
    $orderId     = $order->get_id();
    $sessionKey  = $this->getOrderSessionKey($orderId);
    $rzpOrderId  = get_post_meta($orderId, $sessionKey, true);

    if (empty($rzpOrderId)) continue;

    $rzpOrder = $api->order->fetch($rzpOrderId);
    if ($rzpOrder->status === 'paid') {
        // Get the payment ID
        $payments = $api->order->payments($rzpOrderId);
        $capturedPayment = collect($payments->items)->firstWhere('status', 'captured');
        if ($capturedPayment) {
            // Update WC order
            // ... use updateOrder() or payment_complete()
        }
    }
}
```

---

## Key Files

- `woo-razorpay.php`:
  - `getOrderSessionKey()` (~line 1137) — get the meta key for Razorpay order ID
  - `updateOrder()` (~line 2275) — the standard order update method
  - `verifyOrderAmount()` (~line 1507) — verifies amount consistency before updating
  - `getRazorpayApiInstance()` (~line 1943) — get API instance
  - `isHposEnabled` property — determines which meta path to use
- `includes/razorpay-webhook.php`:
  - `paymentAuthorized()` (~line 290) — how the webhook updates orders (reference pattern)
- `.ai/context/DATABASE_SCHEMA.md` — all meta keys used in the plugin

---

## Common Patterns

### Get session key for an order
```php
$sessionKey    = $this->getOrderSessionKey($orderId);
$rzpOrderId    = get_post_meta($orderId, $sessionKey, true);
// E.g., $sessionKey = 'razorpay_order_id1234', $rzpOrderId = 'order_XXXXXXXXXX'
```

### Check payment amount matches (avoid double-processing wrong amount)
```php
$orderAmountInPaise = (int) round($order->get_total() * 100);
$rzpOrderAmount     = $rzpOrder->amount;
if ($orderAmountInPaise !== $rzpOrderAmount) {
    rzpLogError("Amount mismatch: WC=$orderAmountInPaise, Razorpay=$rzpOrderAmount");
    // Do NOT update order — potential fraud
}
```

### HPOS-aware meta update
```php
if ($this->isHposEnabled) {
    $order->update_meta_data('razorpay_payment_id', $paymentId);
    $order->update_meta_data('rzp_update_order_cron_status', 1);
    $order->save();
} else {
    update_post_meta($orderId, 'razorpay_payment_id', $paymentId);
    update_post_meta($orderId, 'rzp_update_order_cron_status', 1);
}
```

---

## Example Prompts

- "Use order-status-sync skill. Order #1234 shows pending but customer paid — sync it."
- "After our server was down for 2 hours, about 20 orders are stuck in pending. Sync them all with Razorpay."
- "Order #567 shows processing in WC but the Razorpay dashboard shows refunded. What's the state?"

---

## Output

After completing this skill, produce:
1. Current status of the order in both WooCommerce and Razorpay
2. The discrepancy identified
3. The action taken (updated WC, added note, flagged for manual review)
4. Any code executed to fix the sync issue
5. Recommendation to prevent recurrence (e.g., fix webhook, enable cron)
