# Skill: Debug a Failed Payment

## Purpose
Help diagnose why a payment failed to complete, did not reflect in WooCommerce, or shows an inconsistent state between Razorpay dashboard and the WooCommerce order.

## When to Use
- A merchant reports "Payment was deducted but order not confirmed"
- WooCommerce order is stuck in `pending` or `processing` despite a successful Razorpay payment
- A customer was charged but did not receive an order confirmation email
- The Razorpay dashboard shows `captured` but WooCommerce shows `failed`
- A signature verification failure was logged

## Prerequisites
- Access to WooCommerce admin (or database read access)
- The WooCommerce order ID (e.g., `1234`)
- Optionally: the Razorpay payment ID (`rzp_pay_XXXX`) or Razorpay order ID (`order_XXXX`)
- Read access to WordPress debug logs

---

## Steps

### Step 1 — Read the order notes and meta in WooCommerce

In WooCommerce admin → Orders → Order #1234 → Order notes:

Look for:
- "Razorpay payment successful" (callback succeeded)
- "Razorpay payment failed" + reason
- "Payment via Razorpay. Payment ID: rzp_pay_XXXX" (payment captured)
- "Razorpay webhook triggered" or "Webhook: payment.authorized received"
- Any signature verification failure messages

In the database, query order meta:
```sql
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id = 1234
  AND meta_key IN (
    'razorpay_payment_id',
    'razorpay_order_id',
    '_razorpay_order_id',
    'razorpay_wc_order_id',
    'is_magic_checkout_order',
    '_payment_method',
    '_order_total',
    'rzp_update_order_cron_status'
  );
```

For HPOS-enabled stores, query `wp_wc_orders_meta` instead of `wp_postmeta`.

**Key field:** `rzp_update_order_cron_status`
- `0` = webhook received but not yet processed by cron
- `1` = callback already processed the payment successfully

### Step 2 — Check the rzp_webhook_requests table

```sql
SELECT *
FROM wp_rzp_webhook_requests
WHERE woocommerce_order_id = 1234
ORDER BY created_at DESC
LIMIT 10;
```

Column meanings:
- `status = 0` → webhook event received, waiting for cron processing
- `status = 1` → callback already processed, cron will skip this
- `razorpay_order_id` → the `order_XXXX` value from Razorpay
- `razorpay_payment_id` → the `rzp_pay_XXXX` value
- `webhook_data` → JSON blob of the full webhook payload

If there are no rows: Razorpay order creation may have failed, OR the webhook was never received (check webhook registration).

### Step 3 — Check the session key used for this order

In WC_Razorpay, the Razorpay order ID is stored using a composite key:
- Standard: `razorpay_order_id1234` (meta key is `RAZORPAY_ORDER_ID . $orderId`)
- 1CC orders: `razorpay_order_id_1cc1234`

The correct key is determined by `getOrderSessionKey($orderId)` in `woo-razorpay.php` (line ~1137):
```php
protected function getOrderSessionKey($orderId)
{
    $key = get_post_meta($orderId, 'is_magic_checkout_order', true);
    // Returns RAZORPAY_ORDER_ID_1CC . $orderId or RAZORPAY_ORDER_ID . $orderId
}
```

Check if the session key exists and matches what's in `rzp_webhook_requests`.

### Step 4 — Look for signature verification failures in logs

In WordPress debug log (`wp-content/debug.log`) or WooCommerce system log:

Search for:
```
razorpay.wc.signature.verify_failed
```

If found, the HMAC didn't match. Possible causes:
- Webhook secret mismatch (check `woocommerce_razorpay_settings['webhook_secret']` vs Razorpay dashboard)
- Raw body was modified in transit (middleware issue)
- Wrong webhook URL configured

Search for:
```
Woocommerce orderId: 1234 webhook process
```

This shows the full lifecycle of webhook processing for this order.

### Step 5 — Query Razorpay API for payment status

Using the Razorpay PHP SDK or cURL:

```php
// In a temporary debug script
$api = new Api($keyId, $keySecret);

// If you have the payment ID:
$payment = $api->payment->fetch('rzp_pay_XXXX');
echo $payment->status;  // authorized, captured, failed, refunded

// If you only have the Razorpay order ID:
$order = $api->order->fetch('order_XXXX');
echo $order->status;    // created, attempted, paid
$payments = $api->order->payments('order_XXXX');
```

Compare the Razorpay API status with the WooCommerce order status.

### Step 6 — Check for Razorpay order ID mismatch

A mismatch happens when:
1. WooCommerce order was created with Razorpay order `order_AAA`
2. Customer abandoned and a new Razorpay order `order_BBB` was created for a retry
3. Payment was made on `order_BBB` but webhook references `order_BBB`
4. Callback tries to verify against `order_AAA` stored in session → verification fails

Check the session key to see which Razorpay order ID the WC order has stored.
In `woo-razorpay.php::verifyOrderAmount()` (line ~1507), mismatches cause a silent failure.

### Step 7 — Check if the cron ran

The `rzp_wc_webhook_processing` cron processes `status=0` rows in `rzp_webhook_requests`.

```sql
-- Were there unprocessed webhooks?
SELECT * FROM wp_rzp_webhook_requests WHERE status = 0 AND woocommerce_order_id = 1234;
```

If rows are stuck at `status=0`, the cron may not be running. Check:
```php
wp_next_scheduled('rzp_wc_webhook_processing')
```

The cron is registered in `includes/cron/` directory. Check if WP-Cron is disabled (`DISABLE_WP_CRON` constant).

### Step 8 — Determine the fix

| Scenario | Fix |
|---|---|
| Payment captured in Razorpay, order still pending | Manually update order to `processing`, add payment ID via `updateOrder()` |
| Webhook never received | Re-register webhook via `autoEnableWebhook()`, or manually trigger from Razorpay dashboard |
| Signature mismatch | Verify webhook secret matches in both plugin settings and Razorpay dashboard |
| Order ID mismatch | Check session key logic, ensure no race condition in `createOrGetRazorpayOrderId()` |
| Cron not running | Enable WP-Cron or add a server cron to trigger `wp-cron.php` |

---

## Key Files

- `woo-razorpay.php` — `check_razorpay_response()` (line ~1967), `verifySignature()` (line ~2203), `updateOrder()` (line ~2275), `verifyOrderAmount()` (line ~1507)
- `includes/razorpay-webhook.php` — `process()` (line ~80), `saveWebhookEvent()` (line ~198), `paymentAuthorized()` (line ~290)
- `includes/cron/` — Cron job that processes `rzp_webhook_requests`
- Database: `wp_rzp_webhook_requests`, `wp_postmeta` (or `wp_wc_orders_meta` for HPOS)

---

## Common Patterns

### Reading order meta (HPOS-aware)
```php
if ($this->isHposEnabled) {
    $order = wc_get_order($orderId);
    $paymentId = $order->get_meta('razorpay_payment_id');
} else {
    $paymentId = get_post_meta($orderId, 'razorpay_payment_id', true);
}
```

### Checking webhook processing status
```php
global $wpdb;
$table = $wpdb->prefix . 'rzp_webhook_requests';
$row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table WHERE woocommerce_order_id = %d ORDER BY id DESC LIMIT 1",
    $orderId
));
// $row->status: 0=pending, 1=processed
```

---

## Example Prompts

- "Use debug-payment skill. Order #1234, customer was charged but order is still pending."
- "Payment rzp_pay_ABC123 shows 'captured' in Razorpay but WC order #567 is 'failed'. Debug it."
- "Order #890 is stuck with rzp_update_order_cron_status=0. What went wrong?"

---

## Output

After completing this skill, produce:
1. A summary of the order's state in both WooCommerce and Razorpay
2. The identified root cause (signature failure / missing webhook / ID mismatch / cron issue)
3. The specific fix to apply
4. Any code changes needed (with HPOS dual-path if touching order meta)
