# Skill: Investigate a Refund That Hasn't Processed

## Purpose
Investigate why a WooCommerce refund hasn't been processed, is stuck, or shows an inconsistent state between WooCommerce and the Razorpay dashboard.

## When to Use
- A merchant issued a refund in WooCommerce admin but the customer hasn't received money back
- The refund shows "Refunded" in WooCommerce but doesn't appear in Razorpay dashboard
- A partial refund was processed incorrectly
- A gift card refund failed silently
- The `refund.created` webhook wasn't received or processed

## Prerequisites
- The WooCommerce order ID (e.g., `1234`)
- The WooCommerce refund ID (found in `wp_posts` where `post_type = 'shop_order_refund'`)
- Access to WooCommerce admin and database
- Optionally: the Razorpay payment ID (`rzp_pay_XXXX`)

---

## Steps

### Step 1 — Check the WooCommerce refund post

Refunds in WooCommerce are stored as child posts of the order:

```sql
-- Find refund records for an order
SELECT ID, post_status, post_date, post_parent
FROM wp_posts
WHERE post_type = 'shop_order_refund'
  AND post_parent = 1234;

-- Check refund meta
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id = <refund_post_id>
  AND meta_key IN (
    '_refund_amount',
    '_refund_reason',
    'razorpay_refund_id',
    '_refunded_payment',
    '_refunded_by'
  );
```

For HPOS-enabled stores, check `wp_wc_orders` where `parent_order_id = 1234` and `type = 'shop_order_refund'`, and `wp_wc_orders_meta` for the refund meta.

**Key meta:** `razorpay_refund_id` — this is set only if the Razorpay API call succeeded. If it's missing, the API call failed or wasn't made.

### Step 2 — Trace the refund in woo-razorpay.php

Refunds are processed by `process_refund()` (line ~1784 in `woo-razorpay.php`). Read this method to understand the flow:

1. Validates that a `razorpay_payment_id` exists on the order
2. Checks for gift cards (delegates to `processRefundForOrdersWithGiftCard()` if present)
3. Calls `$this->api->payment->refund($razorpayPaymentId, $data)` via Razorpay API
4. Stores the returned refund ID in order meta as `razorpay_refund_id`
5. Adds an order note

Check order meta for `razorpay_payment_id`:

```sql
SELECT meta_value FROM wp_postmeta
WHERE post_id = 1234 AND meta_key = 'razorpay_payment_id';
```

If `razorpay_payment_id` is missing → the original payment wasn't captured via this plugin, or the meta was never stored.

### Step 3 — Verify razorpay_refund_id on the refund

After a successful refund, `process_refund()` stores the refund ID:
```php
update_post_meta($refundId, 'razorpay_refund_id', $refund->id);
```

Or with HPOS:
```php
$refundOrder->update_meta_data('razorpay_refund_id', $refund->id);
$refundOrder->save();
```

If `razorpay_refund_id` is empty, the API call failed. Check:
- WooCommerce error notices from when the refund was attempted
- PHP error log for exceptions from the API call
- Log for `rzpLogError` calls from `process_refund()`

### Step 4 — Check the refund.created webhook

The `refund.created` webhook is handled by `refundedCreated()` in `includes/razorpay-webhook.php` (line ~606):

```php
public function refundedCreated(array $data)
{
    $refundId  = $data['payload']['refund']['entity']['id'];
    $paymentId = $data['payload']['refund']['entity']['payment_id'];
    $amount    = $data['payload']['refund']['entity']['amount'];  // in paise
    $orderId   = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
    // ...
}
```

Check if the webhook was received by searching logs:
```
Woocommerce orderId: 1234 webhook process intitiated for event: refund.created
```

If not found, either:
- Webhook was not configured for `refund.created` events in Razorpay dashboard
- Webhook secret mismatch caused silent rejection
- The refund was issued directly from Razorpay dashboard (no plugin trigger)

### Step 5 — Audit the Razorpay API for the refund

Query Razorpay API directly to check refund status:

```php
$api = $this->getRazorpayApiInstance();
$payment = $api->payment->fetch('rzp_pay_XXXX');

// List all refunds for this payment
$refunds = $api->payment->refunds('rzp_pay_XXXX');
foreach ($refunds->items as $refund) {
    echo $refund->id . ' — ' . $refund->status . ' — ' . ($refund->amount / 100);
}
```

Refund statuses: `pending`, `processed`, `failed`

If the refund is `failed` in Razorpay:
- Check reason: insufficient balance, payment not captured, etc.
- May need to re-initiate from Razorpay dashboard

### Step 6 — Check gift card special handling

If the order used gift cards (Yith, Smart Coupon, or other gift card plugins), refunds go through `processRefundForOrdersWithGiftCard()` (line ~1843 in `woo-razorpay.php`).

Check order meta:
```sql
SELECT meta_key, meta_value FROM wp_postmeta
WHERE post_id = 1234
  AND meta_key LIKE '%gift%' OR meta_key LIKE '%giftcard%';
```

If gift cards are involved:
- The refund is split: gift card amount is credited back to the gift card, remaining amount to original payment method
- Read `processRefundForOrdersWithGiftCard()` carefully — it has special logic for this split
- Check that `debitGiftCards()` didn't fail silently

### Step 7 — Determine the fix

| Scenario | Fix |
|---|---|
| `razorpay_payment_id` missing on order | Manual lookup in Razorpay dashboard, store payment ID via order meta update |
| API call failed with exception | Check error message in logs; retry refund from WC admin if Razorpay payment is captured |
| Refund processed in Razorpay but not in WC | Manually create WC refund record, add `razorpay_refund_id` meta |
| `refund.created` webhook not received | Configure webhook in Razorpay dashboard to include `refund.created` event |
| Gift card refund failed | Debug `processRefundForOrdersWithGiftCard()`, check gift card plugin compatibility |
| Partial refund amount mismatch | Check that amount is in paise: `(int) round($amount * 100)` |

---

## Key Files

- `woo-razorpay.php`:
  - `process_refund()` (~line 1784) — main refund handler
  - `processRefundForOrdersWithGiftCard()` (~line 1843) — gift card refund logic
  - `debitGiftCards()` (~line 2762) — gift card credit-back logic
- `includes/razorpay-webhook.php`:
  - `refundedCreated()` (~line 606) — `refund.created` webhook handler

---

## Common Patterns

### Checking refund meta (HPOS-aware)
```php
if ($this->isHposEnabled) {
    $refundOrder = wc_get_order($refundId);
    $rzpRefundId = $refundOrder->get_meta('razorpay_refund_id');
} else {
    $rzpRefundId = get_post_meta($refundId, 'razorpay_refund_id', true);
}
```

### Amount conversion for refunds
```php
// WooCommerce passes amount in decimal (e.g., 100.50)
// Must convert to paise for Razorpay API
$data = ['amount' => (int) round($amount * 100)];
$refund = $this->api->payment->refund($razorpayPaymentId, $data);
```

### Logging refund operations
```php
rzpLogInfo("process_refund: orderId=$orderId, amount=$amount, paymentId=$razorpayPaymentId");
rzpLogError("process_refund: API failed — " . $e->getMessage());
```

---

## Example Prompts

- "Use refund-investigation skill. Order #1234, refund issued 3 days ago, customer hasn't received money."
- "A partial refund for order #567 shows 'refunded' in WC but the amount in Razorpay dashboard doesn't match."
- "The refund.created webhook for order #890 was never received. Investigate."

---

## Output

After completing this skill, produce:
1. The state of the refund in WooCommerce (meta, post record)
2. The state of the refund in Razorpay (API status)
3. Whether the webhook was received and processed
4. The root cause of the discrepancy
5. The specific fix (with code if meta updates are needed)
6. How to verify the fix worked
