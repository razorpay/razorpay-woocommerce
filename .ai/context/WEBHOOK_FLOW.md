# Webhook Flow — Razorpay WooCommerce Plugin

## Webhook URL

```
{site_url}/wp-admin/admin-post.php?action=rzp_wc_webhook
```

Registered via:
```php
add_action('admin_post_nopriv_rzp_wc_webhook', 'razorpay_webhook_init', 10);
```

`razorpay_webhook_init()` instantiates `RZP_Webhook` and calls `->process()`.

---

## Auto-Registration of Webhooks

On every **settings save** (`woocommerce_update_options_payment_gateways_razorpay`), `autoEnableWebhook()` runs:

1. Validates Key ID and Key Secret via `GET /orders` API call.
2. Rejects localhost domains (no public IP = webhook unreachable).
3. Paginates `GET /webhooks?count=10&skip=N` to list all existing webhooks.
4. If webhook URL already exists → `PUT /webhooks/{id}` to update.
5. If not found → `POST /webhooks/` to create.
6. Default events enabled: `payment.authorized`, `refund.created`.
7. If subscription plugin flag is set → also enables: `subscription.cancelled`, `subscription.paused`, `subscription.resumed`, `subscription.charged`.

Webhook secret is auto-generated (20-char alphanumeric string) and stored in `webhook_secret` WP option.

Re-registration is throttled: `webhook_enable_flag` option stores last registration timestamp. During order creation, if `flag + 43200 < now`, webhook is re-registered.

---

## Signature Verification

Every incoming webhook is verified with HMAC-SHA256:

```php
$this->api->utility->verifyWebhookSignature(
    $rawBody,                         // raw php://input
    $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'],
    $razorpayWebhookSecret
);
```

Secret lookup order:
1. `woocommerce_razorpay_settings['webhook_secret']`
2. `get_option('webhook_secret')`
3. `get_option('rzp_webhook_secret')` (legacy)

If secret is missing or signature mismatch → return without processing (logged + tracked).

---

## `shouldConsumeWebhook()` Guard

Before signature verification, a quick structural check:

```php
isset($data['event']) &&
in_array($data['event'], $this->eventsArray) &&
(isset($data['payload']['payment']['entity']['notes']['woocommerce_order_id']) ||
 isset($data['payload']['subscription']['entity']['notes']['woocommerce_order_id']))
```

If this returns false, the webhook is silently dropped.

---

## Event Handling

### `payment.authorized`

Most important event. **Saved to table, NOT immediately processed.**

```php
case self::PAYMENT_AUTHORIZED:
    $webhookFilteredData = [
        'invoice_id'           => $data['payload']['payment']['entity']['invoice_id'],
        'woocommerce_order_id' => $data['payload']['payment']['entity']['notes']['woocommerce_order_id'],
        'razorpay_payment_id'  => $data['payload']['payment']['entity']['id'],
        'event'                => $data['event']
    ];
    $this->saveWebhookEvent($webhookFilteredData, $razorpayOrderId);
    return; // Does NOT process immediately
```

Processing happens via `paymentAuthorized($data)` called by the cron job:

1. Skip if `invoice_id` present (subscription payment — handled separately).
2. Skip if order already paid.
3. If order is draft → update to `wc-pending`.
4. Fetch payment from Razorpay: `$api->payment->fetch($razorpayPaymentId)`.
5. If `status === 'captured'` → success.
6. If `status === 'authorized'` AND `payment_action === 'capture'`:
   - Call `$payment->capture(['amount' => $orderAmount])`.
   - On failure → re-fetch payment; if still `captured`, mark success.
7. Call `$razorpay->updateOrder($order, $success, ...)`.

### `payment.pending`

Handles COD (Cash on Delivery) orders placed through Magic Checkout:

1. Skip non-COD payments.
2. Find order from `notes.woocommerce_order_id`.
3. If order is draft → `wc-pending`.
4. Fetch payment entity.
5. If `payment.status === 'pending'` AND `method === 'cod'` → `updateOrder($order, true, ...)`.
6. Exit with `exit` (graceful).

### `payment.failed`

No-op — returns immediately. Order status is handled by the callback.

### `refund.created`

Adds an order note with the refund ID received from Razorpay. Does not change order status.

### `virtual_account.credited`

For UPI Collect / Bank Transfer payments:

1. Get `order_id` from notes.
2. Fetch `razorpay_payment_id` and `virtual_account_id` from payload.
3. Compare `amount_paid` vs order total.
4. If amounts match and payment is `captured` (or `authorized` + auto-capture enabled) → `updateOrder(success=true)` with `virtualAccountId`.
5. Exit with `exit`.

### Subscription Events

Base `RZP_Webhook` class returns immediately for:
- `subscription.cancelled`
- `subscription.paused`
- `subscription.resumed`
- `subscription.charged`

These are overridden by the **Razorpay WooCommerce Subscriptions** extension (separate plugin).

---

## Webhook Storage Table

Table: `{prefix}rzp_webhook_requests`

| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT | Primary key |
| `integration` | VARCHAR | Always `"woocommerce"` |
| `order_id` | INT | WooCommerce order ID |
| `rzp_order_id` | VARCHAR | Razorpay order ID |
| `rzp_webhook_data` | JSON | Array of webhook event payloads |
| `rzp_update_order_cron_status` | INT | `0` = created, `1` = processed by callback |
| `rzp_webhook_notified_at` | INT | Unix timestamp of last webhook receipt |

Records are inserted in `createRazorpayOrderId()` with status `0`.

On successful callback: status updated to `1` via:
```sql
UPDATE {prefix}rzp_webhook_requests
SET rzp_update_order_cron_status = 1
WHERE integration = 'woocommerce' AND order_id = ? AND rzp_order_id = ?
```

The cron job queries for `rzp_update_order_cron_status = 0` to find orders that need webhook processing.

---

## Cron Processing of `payment.authorized`

The `one_cc_address_sync_cron` cron job (registered via `wp_schedule_event`) calls cron execution that includes processing unhandled `payment.authorized` webhook events saved in the table.

This ensures orders are completed even when:
- The customer closed the browser before the callback completed.
- Network issues prevented the callback from reaching the server.
- The modal was dismissed after payment was authorized.

---

## Security Considerations

1. **HMAC verification** on every webhook (HMAC-SHA256 with a 20-char secret).
2. **`admin_post_nopriv_*`** endpoint — publicly accessible but protected by signature check.
3. **Idempotency** — If order already has `needs_payment() === false`, webhook is skipped.
4. **Concurrent processing guard** — `wc_order_under_process_{$orderId}` transient (300s TTL) prevents simultaneous callback + webhook processing.
5. **1CC coupon list** endpoint uses HMAC signature (not just auth credentials) for extra security.
