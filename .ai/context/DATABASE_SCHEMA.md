# Database Schema — Razorpay WooCommerce Plugin

## Custom Tables

### `{prefix}rzp_webhook_requests`

Created by the plugin during order creation (inline via `$wpdb->insert`). This table tracks the relationship between WC orders and Razorpay webhook events.

| Column | Type | Description |
|---|---|---|
| `id` | INT AUTO_INCREMENT | Primary key |
| `integration` | VARCHAR(50) | Always `"woocommerce"` |
| `order_id` | INT | WooCommerce order ID |
| `rzp_order_id` | VARCHAR(100) | Razorpay order ID (e.g., `order_AbcXyz123`) |
| `rzp_webhook_data` | TEXT/JSON | JSON array of saved webhook event payloads |
| `rzp_update_order_cron_status` | INT | `0` = created, `1` = processed by callback |
| `rzp_webhook_notified_at` | INT | Unix timestamp of last webhook notification |

**Status Values for `rzp_update_order_cron_status`:**
- `0` (`RZP_ORDER_CREATED`) — Order created, waiting for callback/webhook processing
- `1` (`RZP_ORDER_PROCESSED_BY_CALLBACK`) — Callback received and order processed

**Key Operations:**
- **INSERT**: In `createRazorpayOrderId()` — one record per Razorpay order
- **UPDATE (data)**: In `saveWebhookEvent()` — appends webhook payload to JSON array
- **UPDATE (status)**: In `check_razorpay_response()` — marks as processed when callback succeeds
- **SELECT**: By cron job — finds orders with `rzp_update_order_cron_status = 0` needing processing

---

## WordPress Options

### Core Plugin Settings

| Option Key | Type | Description |
|---|---|---|
| `woocommerce_razorpay_settings` | array | All plugin settings (serialized array) |
| `webhook_secret` | string | Auto-generated HMAC webhook secret |
| `rzp1cc_hmac_secret` | string | 1CC signing secret (for coupon list endpoint) |
| `rzp_wc_last_key_id` | string | Last Key ID used (to detect key changes) |
| `webhook_enable_flag` | int | Unix timestamp of last webhook registration |
| `rzp_subscription_webhook_enable_flag` | bool | Set by subscriptions plugin to enable subscription events |
| `rzp_hpos` | string | `'yes'` or `'no'` — HPOS status tracking |
| `rzp_woocommerce_current_version` | string | Current plugin version (for upgrade detection) |
| `rzp_afd_enable` | string | `'yes'` or `'no'` — Affordability widget feature available |
| `rzp_rtb_enable` | string | `'yes'` or `'no'` — RTB widget available |
| `rzp_afd_feature_checked` | string | `'yes'` when feature check completed |
| `rzp_rtb_feature_checked` | string | `'yes'` when RTB check completed |

### Transients (Temporary Options)

| Transient Key | TTL | Description |
|---|---|---|
| `razorpay_wc_order_id` | 3600s | Current WC order ID during payment |
| `one_cc_merchant_preference` | 7200s | Cached 1CC merchant preferences from API |
| `rzp_wc_ensure_1cc_secret_lock` | 30s | Lock to prevent concurrent secret registration |
| `wc_order_under_process_{$orderId}` | 300s | Lock to prevent concurrent callback+webhook processing |
| `wc_razorpay_cart_hash_{$hash}` | 14400s | Maps session+cart hash to WC order ID |
| `wc_razorpay_cart_hash_{$orderId}` | 14400s | Maps order ID to session+cart hash |

---

## Post Meta Keys (Classic WC — non-HPOS)

Stored in `{prefix}postmeta` with `post_id` = WC order post ID.

### Payment Meta

| Meta Key | Example Value | Description |
|---|---|---|
| `razorpay_order_id{$orderId}` | `order_AbcXyz` | Standard Razorpay order ID for this WC order |
| `razorpay_order_id_1cc{$orderId}` | `order_AbcXyz` | Razorpay order ID for Magic Checkout orders |
| `_payment_method` | `razorpay` | WC standard: payment method identifier |
| `_payment_method_title` | `Razorpay` | WC standard: payment method title |
| `_transaction_id` | `pay_AbcXyz` | WC standard: Razorpay payment ID (set by `payment_complete()`) |

### Magic Checkout (1CC) Meta

| Meta Key | Value | Description |
|---|---|---|
| `is_magic_checkout_order` | `yes` / `no` | Flags whether order was placed via Magic Checkout |
| `1cc_shippinginfo` | JSON array | Shipping method details from 1CC checkout |
| `pys_enrich_data` | JSON | Pixel Your Site UTM tracking data |

### Route Module Meta (Product Level)

| Meta Key | Description |
|---|---|
| `_transfer_account_id` | Linked account ID for transfer |
| `_transfer_percentage` | Transfer percentage |

### User Meta (Updated on 1CC Account Creation)

| Meta Key | Description |
|---|---|
| `shipping_first_name` | Customer shipping first name |
| `shipping_address_1` | Shipping address line 1 |
| `shipping_address_2` | Shipping address line 2 |
| `shipping_city` | Shipping city |
| `shipping_country` | Shipping country code |
| `shipping_postcode` | Shipping postcode |
| `shipping_state` | Shipping state code |
| `shipping_email` | Customer email |
| `shipping_phone` | Customer phone |
| `billing_first_name` | Billing first name |
| `billing_phone` | Billing phone |
| `billing_address_1` | Billing address line 1 |
| `billing_address_2` | Billing address line 2 |
| `billing_city` | Billing city |
| `billing_country` | Billing country code |
| `billing_postcode` | Billing postcode |
| `billing_state` | Billing state code |

---

## HPOS Order Meta Keys

When HPOS (`custom_order_tables`) is enabled, metadata is stored in `{prefix}wc_orders_meta` (WooCommerce custom tables) and accessed via:

```php
$order->get_meta('meta_key')
$order->update_meta_data('meta_key', 'value')
$order->save()
```

The same meta keys apply, accessed through the HPOS API rather than `get_post_meta()`.

### HPOS Custom Tables

| Table | Description |
|---|---|
| `{prefix}wc_orders` | Core order data (replaces `{prefix}posts` for orders) |
| `{prefix}wc_orders_meta` | Order meta (replaces `{prefix}postmeta` for orders) |
| `{prefix}wc_order_operational_data` | Order keys, payment methods |

**HPOS lookup example (in plugin):**
```php
// Find order by order_key
$orderOperationalData = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT order_id FROM $orderOperationalDataTable AS P WHERE order_key = %s",
        $post_password
    )
);
```

---

## WooCommerce Standard Tables Used

| Table | Plugin Usage |
|---|---|
| `{prefix}postmeta` | Store order meta in classic mode |
| `{prefix}wc_orders` | HPOS: order lookup by payment_method |
| `{prefix}wc_orders_meta` | HPOS: order meta read/write |
| `{prefix}wc_order_operational_data` | HPOS: order key lookup |
| `{prefix}woocommerce_order_items` | Add gift card / shipping line items |
| `{prefix}woocommerce_order_itemmeta` | Store gift card item metadata |

---

## Notes on Data Integrity

1. **Razorpay Order ID storage**: The session key (`razorpay_order_id{$orderId}`) is unique per WC order — prevents multiple Razorpay orders for the same WC order on page refreshes.
2. **`rzp_webhook_requests` table**: Uses a combination of `integration + order_id + rzp_order_id` as a logical unique constraint (no DB-level constraint enforced).
3. **Concurrent processing**: The `wc_order_under_process_` transient acts as a distributed lock (300s TTL). This is not perfectly atomic but sufficient for typical load.
4. **No cascade deletes**: When a WC order is deleted, the `rzp_webhook_requests` record is NOT automatically cleaned up.
