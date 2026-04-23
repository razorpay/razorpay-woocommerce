# Skill: Debug Subscription Payment Failures

## Purpose
Diagnose why a WooCommerce subscription renewal payment failed, wasn't charged, or shows inconsistent state between WooCommerce Subscriptions and Razorpay.

## When to Use
- A subscription renewal payment failed silently
- `subscription.charged` webhook was received but the order wasn't updated
- A customer's subscription was cancelled unexpectedly
- Recurring payment token is invalid or missing
- The subscription was paused/resumed but WooCommerce status doesn't reflect it

## Prerequisites
- The WooCommerce subscription ID (found in WC > Subscriptions)
- The parent order ID (the original subscription order)
- Read `.ai/context/SUBSCRIPTION_FLOW.md` fully
- Understand that subscription logic lives in a **separate plugin** (WooCommerce Subscriptions + Razorpay Subscriptions for WooCommerce) — the main plugin only provides hooks
- Read `includes/razorpay-webhook.php` for webhook handler code

---

## Steps

### Step 1 — Verify WooCommerce Subscriptions plugin is active

```php
// Check if WC Subscriptions is active
if (class_exists('WC_Subscriptions')) {
    echo 'WC Subscriptions is active';
}

// Check if Razorpay Subscriptions extension is active
if (class_exists('RZP_Subscriptions')) {
    echo 'Razorpay Subscriptions extension is active';
}
```

In WP Admin: Plugins → look for "WooCommerce Subscriptions" and "Razorpay Subscriptions for WooCommerce".

If the Razorpay Subscriptions plugin is not active, subscription payments **cannot** process — this is a separate commercial plugin.

### Step 2 — Locate the RZP_Subscriptions class

The subscriptions logic extends `WC_Razorpay` (it's in the separate Razorpay Subscriptions plugin, not in this repo). However, this repo's `woo-razorpay.php` provides hooks for it:

- The `$hooks = false` constructor path prevents double hook registration
- The `$subscriptionEvents` array in `RZP_Webhook` handles subscription webhook events

Within the main repo, check:
```
includes/razorpay-webhook.php
```

Subscription webhook handlers here (~line 253–290):
- `subscriptionCancelled()` — handles `subscription.cancelled`
- `subscriptionPaused()` — handles `subscription.paused`
- `subscriptionResumed()` — handles `subscription.resumed`
- `subscriptionCharged()` — handles `subscription.charged`

### Step 3 — Check subscription token in order meta

The subscription token (Razorpay subscription ID) is stored in the parent order meta. Query:

```sql
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id = <parent_order_id>
  AND meta_key IN (
    'razorpay_subscription_id',
    '_razorpay_subscription_id',
    'subscription_payment_method',
    'razorpay_payment_id',
    '_payment_method'
  );
```

For HPOS stores, query `wp_wc_orders_meta` instead.

**Key field:** `razorpay_subscription_id` — the `sub_XXXX` ID from Razorpay. If missing, the subscription was never created in Razorpay.

### Step 4 — Check recurring payment webhook events

In the `process()` method of `RZP_Webhook`, subscription events use a different path for extracting the order ID:

```php
if (in_array($data['event'], $this->subscriptionEvents) === true)
{
    $orderId = $data['payload']['subscription']['entity']['notes']['woocommerce_order_id'];
    $razorpayOrderId = ($data['event'] == self::SUBSCRIPTION_CHARGED)
        ? $razorpayOrderId
        : "No payment id in subscription event";
}
```

Check the logs for:
```
Woocommerce orderId: <id> webhook process intitiated for event: subscription.charged
```

If not found, either:
- The webhook event type isn't enabled in Razorpay dashboard
- The webhook secret is wrong (silent rejection)
- The `notes.woocommerce_order_id` isn't set in the subscription

### Step 5 — Check subscriptionCharged() handler

Look at `subscriptionCharged()` in `includes/razorpay-webhook.php` (line ~280):

```php
protected function subscriptionCharged(array $data)
{
    // Delegates to subscriptions plugin's handler
    // The actual charge processing is in RZP_Subscriptions class
}
```

This method may delegate to the Razorpay Subscriptions plugin. Check if:
- The event payload has the expected structure
- The `woocommerce_order_id` note on the subscription matches an active WC order
- The payment entity has `invoice_id` set (subscription invoices)

### Step 6 — Look at invoice_id handling

Subscription payments typically come with an `invoice_id` in the payment entity. In the `process()` method of `RZP_Webhook`, payments with `invoice_id` are passed through (not processed by the main plugin):

```php
// From paymentAuthorized flow:
if (empty($data['payload']['payment']['entity']['invoice_id']) === false)
{
    // Has invoice_id — this is a subscription payment
    // Passed to subscription plugin handler, not processed here
}
```

Check if the `invoice_id` is present in the failed payment's webhook payload.

### Step 7 — Check subscription status in Razorpay dashboard

Use the Razorpay API or dashboard to check:

```php
$api = new Api($keyId, $keySecret);

// Fetch the Razorpay subscription
$subscription = $api->subscription->fetch('sub_XXXX');
echo $subscription->status;
// active, halted, cancelled, completed, expired

// List invoices for the subscription
$invoices = $subscription->invoices(['count' => 10]);
foreach ($invoices->items as $invoice) {
    echo $invoice->id . ' — ' . $invoice->status . ' — ' . $invoice->payment_id;
}
```

Compare the Razorpay subscription status with the WC subscription status.

### Step 8 — Check for subscription cancellation events

If the subscription was unexpectedly cancelled, look for the `subscription.cancelled` webhook. The handler `subscriptionCancelled()` (line ~253) updates the WC subscription status.

Search logs for:
```
subscription.cancelled
subscriptionCancelled
```

Also check if the Razorpay subscription reached its max billing cycles or expiry date.

### Step 9 — Common fixes

| Scenario | Fix |
|---|---|
| `razorpay_subscription_id` missing | Subscription wasn't created in Razorpay — re-create via Razorpay Subscriptions plugin settings |
| `subscription.charged` webhook not received | Enable event in Razorpay dashboard webhook config |
| Subscription halted in Razorpay | Re-activate via Razorpay API or dashboard; update WC subscription to active |
| Payment token expired | Customer needs to update payment method in WC My Account |
| `invoice_id` missing | Check Razorpay subscription setup — invoice generation may not be enabled |
| WC subscription active but Razorpay cancelled | Sync state: cancel WC subscription or re-activate Razorpay subscription |

---

## Key Files

- `includes/razorpay-webhook.php`:
  - `$subscriptionEvents` array (~line 46)
  - `subscriptionCancelled()` (~line 253)
  - `subscriptionPaused()` (~line 262)
  - `subscriptionResumed()` (~line 271)
  - `subscriptionCharged()` (~line 280)
  - The `process()` method order ID extraction for subscription events (~line 91–100)
- `woo-razorpay.php`:
  - `__construct($hooks = false)` — the `$hooks` param exists for subscriptions plugin compatibility (~line 336)
- `.ai/context/SUBSCRIPTION_FLOW.md` — full subscription architecture
- `.ai/diagrams/LLD_SUBSCRIPTION_SEQUENCE.md` — sequence diagram

---

## Common Patterns

### Extracting subscription order ID from webhook
```php
// For subscription events, order ID is in subscription entity notes
$orderId = $data['payload']['subscription']['entity']['notes']['woocommerce_order_id'];

// For subscription.charged, the payment entity also has the order ID
$paymentOrderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
```

### Checking if subscription plugin is active
```php
if (class_exists('WC_Subscriptions_Order')) {
    // WC Subscriptions is active
    $subscriptionId = wcs_get_subscriptions_for_order($orderId);
}
```

### Logging subscription events
```php
rzpLogInfo("subscriptionCharged: Woocommerce orderId: $orderId charged successfully");
rzpLogError("subscriptionCharged: Failed for orderId: $orderId — " . $e->getMessage());
```

---

## Example Prompts

- "Use subscription-debug skill. Subscription #45 renewal payment failed 2 days ago, no order created."
- "A customer's subscription was cancelled in Razorpay but WooCommerce still shows it as active."
- "subscription.charged webhook is being received but the renewal order isn't being created."

---

## Output

After completing this skill, produce:
1. State of the WC subscription and Razorpay subscription
2. Whether the webhook events are being received and processed
3. The Razorpay subscription ID and its current status
4. Root cause of the failure
5. Specific fix with any code changes needed
6. Whether manual intervention in Razorpay dashboard is required
