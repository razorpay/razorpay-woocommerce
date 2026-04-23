# API Integration — Razorpay WooCommerce Plugin

## SDK

The plugin uses the **official Razorpay PHP SDK** vendored in `razorpay-sdk/` and loaded via:
```php
require_once __DIR__.'/razorpay-sdk/Razorpay.php';
```

Namespace: `Razorpay\Api\Api`

### API Instance Creation

```php
// Private API (with secret) — for most operations
$api = new Api($key_id, $key_secret);

// Public API (no secret) — for merchant preferences
$api = new Api($key_id, "");
```

Helper methods in `WC_Razorpay`:
```php
$this->getRazorpayApiInstance($key = '', $secret = '')  // falls back to settings
$this->getRazorpayApiPublicInstance()                    // public, no secret
```

---

## Razorpay API Endpoints Used

### Orders API

| Method | Endpoint | When Used |
|---|---|---|
| `POST /orders` | Create Razorpay order | `createRazorpayOrderId()` |
| `GET /orders/{id}` | Fetch order details | `verifyOrderAmount()`, `update1ccOrderWC()` |
| `GET /orders` (list) | Validate credentials | `autoEnableWebhook()` key validation |

**Order creation payload:**
```json
{
  "receipt": "123",
  "amount": 150000,
  "currency": "INR",
  "payment_capture": 1,
  "app_offer": 0,
  "notes": {
    "woocommerce_order_id": "123",
    "woocommerce_order_number": "123"
  },
  "transfers": [...],         // optional, Route module
  "line_items_total": 150000, // 1CC only
  "line_items": [...]         // 1CC only
}
```

---

### Payments API

| Method | Endpoint | When Used |
|---|---|---|
| `GET /payments/{id}` | Fetch payment entity | `getPaymentEntity()`, `update1ccOrderWC()` |
| `POST /payments/{id}/capture` | Capture authorized payment | `paymentAuthorized()` webhook handler |
| `POST /payments/{id}/refund` | Initiate refund | `process_refund()`, `processRefundForOrdersWithGiftCard()` |
| `POST /payments/{id}/transfer` | Transfer from payment (Route) | `transferFromPayment()` |

---

### Webhooks API

| Method | Endpoint | When Used |
|---|---|---|
| `GET /webhooks?count=N&skip=N` | List all webhooks | `autoEnableWebhook()` |
| `POST /webhooks/` | Create webhook | `autoEnableWebhook()` |
| `PUT /webhooks/{id}` | Update webhook | `autoEnableWebhook()` |

Note: Webhook API calls set `Content-Type: application/x-www-form-urlencoded`.

---

### Merchant APIs

| Method | Endpoint | When Used |
|---|---|---|
| `GET /merchant/1cc_preferences` | Check 1CC feature availability | `__construct()` (admin settings page only) |
| `GET /accounts/me/features` | Check affordability widget feature | `init_form_fields()` |
| `GET /rtb?key_id={id}` | Check RTB widget availability | `init_form_fields()` |

**1CC preference example response:**
```json
{
  "features": {
    "one_click_checkout": true,
    "one_cc_store_account": true
  }
}
```

---

### Checkout Preferences

| Method | Endpoint | When Used |
|---|---|---|
| `GET /preferences` | Merchant checkout options | `generateOrderForm()` |

Used to determine if merchant uses **redirect** (hosted) checkout.

**Key response field:**
```json
{
  "options": {
    "redirect": true,
    "image": "https://cdn.example.com/logo.png"
  }
}
```

---

### Utility API

| Method | Endpoint | When Used |
|---|---|---|
| `POST /utility/verify_payment_signature` | Verify payment signature | `verifySignature()` (via SDK utility) |
| `POST /utility/verify_webhook_signature` | Verify webhook signature | `verifyWebhookSignature()` (via SDK utility) |
| `POST /utility/verify_signature` | Generic signature verify | `checkHmacSignature()` (1CC HMAC auth) |

Signature verification is done locally by the SDK using HMAC-SHA256:
```
expectedSignature = HMAC-SHA256(razorpay_order_id + "|" + razorpay_payment_id, key_secret)
```

---

### Instrumentation APIs

| Method | Endpoint | When Used |
|---|---|---|
| `POST /plugins/segment` | Track events to Segment | `TrackPluginInstrumentation::rzpTrackSegment()` |

Lumberjack (DataLake) is called directly via `wp_remote_post`:
```
POST https://lumberjack.razorpay.com/v1/track
```

---

### Route APIs

| Method | Endpoint | When Used |
|---|---|---|
| `GET /transfers?count=N&skip=N` | List transfers | `RZP_Route::get_data()` |
| `GET /transfers/{id}` | Fetch transfer | Admin view |
| `POST /transfers` | Direct transfer | `directTransfer()` |
| `POST /payments/{id}/transfer` | Transfer from payment | `createPaymentTransfer()` |
| `POST /transfers/{id}/reversals` | Reverse transfer | `reverseTransfer()` |
| `PATCH /transfers/{id}` | Update settlement | `updateTransferSettlement()` |

---

### 1CC Internal APIs

| Method | Endpoint | When Used |
|---|---|---|
| `POST /magic/merchant/auth/secret` | Register 1CC HMAC signing secret | `registerRzp1ccSigningSecret()` |
| `POST /1cc/orders/cod/convert` | Mark COD order as prepaid | `update1ccOrderWC()` on COD payment |
| `GET /1cc/merchant/woocommerce/plugins_list` | Plugin compatibility check | `plugin-fetch.php` cron |

---

## Authentication

### Private API (Key-Based)
All standard API calls use HTTP Basic Auth:
```
Authorization: Basic base64(key_id:key_secret)
```

### Webhook Signature
```
HMAC-SHA256(rawBody, webhookSecret)
```
Expected in: `HTTP_X_RAZORPAY_SIGNATURE` header.

### 1CC HMAC Signature
Same as webhook signature but using `rzp1cc_hmac_secret` (separate secret registered via `magic/merchant/auth/secret`).

---

## Base URL

Configured in the SDK. Default for production:
```
https://api.razorpay.com/v1/
```

Test mode uses the same URL but with test key (`rzp_test_*`).

---

## Error Handling

The SDK throws:
- `Razorpay\Api\Errors\BadRequestError` — 4xx errors (safe to show to customer)
- `Razorpay\Api\Errors\SignatureVerificationError` — HMAC mismatch
- `Razorpay\Api\Errors\Error` — Base error class (other HTTP errors)
- Generic `Exception` — Network errors, timeouts

The plugin wraps all API calls in try/catch and:
1. Logs errors via `rzpLogError()`.
2. Tracks to DataLake via `rzpTrackDataLake()`.
3. Returns meaningful errors to WooCommerce (WP_Error or exception message).

---

## Razorpay Order `notes` Convention

WC order metadata is embedded in Razorpay order `notes` to link orders:

```json
{
  "notes": {
    "woocommerce_order_id": "456",
    "woocommerce_order_number": "456"
  }
}
```

This is how webhook handlers resolve WC orders from Razorpay event payloads.

---

## Currency Support

Razorpay supports multiple currencies. The plugin explicitly blocks:
- `KWD` (Kuwaiti Dinar — 3 decimal places)
- `OMR` (Omani Rial — 3 decimal places)
- `BHD` (Bahraini Dinar — 3 decimal places)

All amounts are stored as integers in the **smallest currency unit** (paise for INR, cents for USD, etc.) using `(int) round($amount * 100)`.
