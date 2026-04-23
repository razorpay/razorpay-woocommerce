# Skill: Add a New Webhook Event Handler

## Purpose
Add support for a new Razorpay webhook event type in the `RZP_Webhook` class so the plugin can respond to new Razorpay events (e.g., `payment.dispute.created`, `order.paid`, `payment.link.paid`).

## When to Use
- Razorpay has introduced a new event type that the plugin needs to handle
- A merchant needs a specific webhook event to trigger a WooCommerce action
- Extending the plugin to handle dispute or chargeback events
- Adding support for payment link events

## Prerequisites
- Know the exact event name from the [Razorpay Webhook docs](https://razorpay.com/docs/webhooks/)
- Know the JSON payload structure for that event (check Razorpay docs or test dashboard)
- Understand what WooCommerce action should result (status change, order note, email, etc.)
- Read `includes/razorpay-webhook.php` fully before starting

---

## Steps

### Step 1 — Read the webhook file and understand existing patterns

Read `includes/razorpay-webhook.php` fully. Pay attention to:

- The `$eventsArray` property (line ~38) — list of events the plugin accepts
- The `$subscriptionEvents` property (line ~46) — subscription-specific events
- The `process()` method (line ~80) — the main router
- The `shouldConsumeWebhook()` method (line ~575) — validates incoming events
- An existing simple handler like `paymentFailed()` (line ~244) or `subscriptionCancelled()` (line ~253)
- The complex `paymentAuthorized()` handler (line ~290) for a full-featured example

### Step 2 — Find the event in Razorpay docs

Identify:
1. The event name string (e.g., `payment.dispute.created`)
2. The payload structure — specifically:
   - Where the `woocommerce_order_id` note lives in the payload
   - Where the relevant entity ID lives
   - What status/amount fields are available
3. Whether this is a payment event, subscription event, or other

### Step 3 — Add the event constant

In `includes/razorpay-webhook.php`, add a new constant in the class constants block (after line ~34):

```php
const PAYMENT_DISPUTE_CREATED = 'payment.dispute.created';
```

### Step 4 — Add to the $eventsArray

Add the constant to `$eventsArray` (around line ~38):

```php
protected $eventsArray = [
    self::PAYMENT_AUTHORIZED,
    self::VIRTUAL_ACCOUNT_CREDITED,
    self::REFUNDED_CREATED,
    self::PAYMENT_FAILED,
    self::PAYMENT_PENDING,
    self::SUBSCRIPTION_CANCELLED,
    self::SUBSCRIPTION_PAUSED,
    self::SUBSCRIPTION_RESUMED,
    self::SUBSCRIPTION_CHARGED,
    self::PAYMENT_DISPUTE_CREATED,  // ADD THIS
];
```

If it's a subscription event, also add to `$subscriptionEvents`.

### Step 5 — Add a case in the process() method switch statement

In the `process()` method (around line ~155), find the switch statement and add a new case:

```php
switch ($data['event']) {
    case self::PAYMENT_AUTHORIZED:
        // ... existing code ...

    // ADD THIS CASE:
    case self::PAYMENT_DISPUTE_CREATED:
        return $this->paymentDisputeCreated($data);

    // ... other existing cases ...
    default:
        return;
}
```

**IMPORTANT:** Also verify that the `$orderId` extraction at the top of the `process()` method (around line ~91) works for this event type. Payment events use:
```php
$orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
```
If your new event has a different payload structure, add a condition for it.

### Step 6 — Write the handler method

Add the new handler method after the existing handlers (after `refundedCreated()`, around line ~700). Follow the exact same pattern as existing handlers:

```php
/**
 * Handles payment.dispute.created webhook event.
 * Updates the WooCommerce order with dispute information.
 *
 * @param array $data Webhook payload
 */
protected function paymentDisputeCreated(array $data)
{
    $paymentId = $data['payload']['payment']['entity']['id'];
    $orderId   = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
    $disputeId = $data['payload']['dispute']['entity']['id'];

    $order = $this->checkIsObject($orderId);

    if ($order === false)
    {
        rzpLogError("paymentDisputeCreated: Order $orderId not found for payment $paymentId");
        return;
    }

    // Add an order note (always add notes for audit trail)
    $note = "Razorpay dispute created. Payment ID: $paymentId. Dispute ID: $disputeId.";
    $order->add_order_note($note);

    // Optionally change order status
    // $order->update_status('on-hold', __('Payment under dispute', 'woocommerce'));

    // Log it
    rzpLogInfo("paymentDisputeCreated: Order $orderId dispute $disputeId created for payment $paymentId");

    // Track the event
    $trackObject = $this->razorpay->newTrackPluginInstrumentation();
    $trackObject->rzpTrackDataLake('razorpay.webhook.dispute.created', [
        'order_id'   => $orderId,
        'payment_id' => $paymentId,
        'dispute_id' => $disputeId,
    ]);

    exit;  // Standard pattern — exit after successful handling
}
```

**Key patterns to follow:**
- Always call `$this->checkIsObject($orderId)` before using the order
- Always add an `$order->add_order_note()` for audit trail
- Always call `rzpLogInfo()` or `rzpLogError()` with order ID in the message
- Call `rzpTrackDataLake()` for analytics
- Use `exit` at the end of successful handlers (same as `paymentFailed`, `virtualAccountCredited`)

### Step 7 — Update rzp_webhook_requests table (if needed)

For events that affect payment processing state, you may need to update the `rzp_webhook_requests` table. Look at `saveWebhookEvent()` (line ~198) for the pattern:

```php
global $wpdb;
$table = $wpdb->prefix . 'rzp_webhook_requests';
// Only needed if this event changes payment processing state
```

Most event handlers do NOT need to write to this table — only `payment.authorized` uses it as a deferred processing queue.

### Step 8 — Test with a sample payload

Generate a test payload using the skill at `.agent/skills/generate-test-payload.md`.

Test by POSTing to:
```
POST /wp-admin/admin-post.php?action=rzp_wc_webhook
X-Razorpay-Signature: <computed_hmac>
Content-Type: application/json

{ "event": "payment.dispute.created", "payload": { ... } }
```

Check:
1. Order note was added
2. Log messages appear in debug log
3. No PHP errors
4. Exit is reached (response code 200 or empty body)

---

## Key Files

- `includes/razorpay-webhook.php` — Only file that needs editing
  - Event constants block (~line 30)
  - `$eventsArray` property (~line 38)
  - `process()` method switch statement (~line 155)
  - Handler methods (~line 244+)
- `woo-razorpay.php` — `checkIsObject()` is defined here (~line 700); `newTrackPluginInstrumentation()` (~line 843)

---

## Common Patterns

### Extracting order ID for payment events
```php
$orderId = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];
$paymentId = $data['payload']['payment']['entity']['id'];
$razorpayOrderId = $data['payload']['payment']['entity']['order_id'];
```

### Extracting order ID for subscription events
```php
$orderId = $data['payload']['subscription']['entity']['notes']['woocommerce_order_id'];
```

### Logging with order context
```php
rzpLogInfo("handlerName: Woocommerce orderId: $orderId message here");
rzpLogError("handlerName: Failed for orderId: $orderId — " . $e->getMessage());
```

### Standard handler skeleton
```php
protected function myNewHandler(array $data)
{
    $paymentId = $data['payload']['payment']['entity']['id'];
    $orderId   = $data['payload']['payment']['entity']['notes']['woocommerce_order_id'];

    $order = $this->checkIsObject($orderId);
    if ($order === false) { rzpLogError("..."); return; }

    $order->add_order_note("Razorpay event: ...");
    rzpLogInfo("handlerName: orderId $orderId ...");
    exit;
}
```

---

## Example Prompts

- "Use add-webhook-handler skill to add support for the payment.dispute.created event."
- "Add a webhook handler for order.paid that marks the WC order as processing."
- "We need to handle payment.link.paid webhooks. Use add-webhook-handler skill."

---

## Output

After completing this skill, produce:
1. The updated `includes/razorpay-webhook.php` with:
   - New constant
   - Updated `$eventsArray`
   - New switch case in `process()`
   - New handler method
2. A test payload for the new event
3. A brief description of what the handler does and any edge cases
