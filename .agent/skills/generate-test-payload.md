# Skill: Generate Test Webhook Payloads

## Purpose
Generate valid Razorpay webhook payloads with correct HMAC-SHA256 signatures for use in local development, unit tests, and webhook handler testing.

## When to Use
- Writing PHPUnit tests for webhook handlers in `RZP_Webhook`
- Testing a newly added webhook event handler locally
- Reproducing a webhook-related bug without a real payment
- Verifying webhook signature verification logic
- Populating `wp_rzp_webhook_requests` with test data

## Prerequisites
- The webhook secret (from WC > Payments > Razorpay settings, or `webhook_secret` option)
- The WooCommerce order ID you want to simulate
- A Razorpay test Key ID (for constructing realistic IDs)

---

## Payload Templates

### payment.authorized (most common)

```json
{
  "entity": "event",
  "account_id": "acc_TEST1234567890",
  "event": "payment.authorized",
  "contains": ["payment"],
  "payload": {
    "payment": {
      "entity": {
        "id": "rzp_pay_TEST1234567890",
        "entity": "payment",
        "amount": 50000,
        "currency": "INR",
        "status": "authorized",
        "order_id": "order_TEST1234567890",
        "invoice_id": null,
        "international": false,
        "method": "upi",
        "amount_refunded": 0,
        "refund_status": null,
        "captured": false,
        "description": "WooCommerce Order #1234",
        "card_id": null,
        "bank": null,
        "wallet": null,
        "vpa": "success@razorpay",
        "email": "customer@example.com",
        "contact": "+919999999999",
        "notes": {
          "woocommerce_order_id": "1234",
          "merchant_order_id": "1234"
        },
        "fee": 1180,
        "tax": 180,
        "error_code": null,
        "error_description": null,
        "created_at": 1700000000
      }
    }
  },
  "created_at": 1700000000
}
```

### payment.authorized (deferred/table insertion variant)

```json
{
  "entity": "event",
  "account_id": "acc_TEST1234567890",
  "event": "payment.authorized",
  "contains": ["payment"],
  "payload": {
    "payment": {
      "entity": {
        "id": "rzp_pay_TEST1234567890",
        "entity": "payment",
        "amount": 50000,
        "currency": "INR",
        "status": "authorized",
        "order_id": "order_TEST1234567890",
        "invoice_id": null,
        "international": false,
        "method": "card",
        "captured": false,
        "notes": {
          "woocommerce_order_id": "1234"
        },
        "created_at": 1700000000
      }
    }
  },
  "created_at": 1700000000
}
```

### payment.failed

```json
{
  "entity": "event",
  "account_id": "acc_TEST1234567890",
  "event": "payment.failed",
  "contains": ["payment"],
  "payload": {
    "payment": {
      "entity": {
        "id": "rzp_pay_TEST1234567890",
        "entity": "payment",
        "amount": 50000,
        "currency": "INR",
        "status": "failed",
        "order_id": "order_TEST1234567890",
        "invoice_id": null,
        "method": "card",
        "captured": false,
        "error_code": "BAD_REQUEST_ERROR",
        "error_description": "Payment failed due to card being declined.",
        "error_source": "customer",
        "error_step": "payment_authentication",
        "error_reason": "payment_declined",
        "notes": {
          "woocommerce_order_id": "1234"
        },
        "created_at": 1700000000
      }
    }
  },
  "created_at": 1700000000
}
```

### refund.created

```json
{
  "entity": "event",
  "account_id": "acc_TEST1234567890",
  "event": "refund.created",
  "contains": ["refund", "payment"],
  "payload": {
    "refund": {
      "entity": {
        "id": "rfnd_TEST1234567890",
        "entity": "refund",
        "amount": 25000,
        "currency": "INR",
        "payment_id": "rzp_pay_TEST1234567890",
        "notes": [],
        "receipt": null,
        "acquirer_data": {},
        "created_at": 1700000000,
        "batch_id": null,
        "status": "processed",
        "speed_processed": "normal",
        "speed_requested": "optimum"
      }
    },
    "payment": {
      "entity": {
        "id": "rzp_pay_TEST1234567890",
        "entity": "payment",
        "amount": 50000,
        "currency": "INR",
        "status": "refunded",
        "order_id": "order_TEST1234567890",
        "notes": {
          "woocommerce_order_id": "1234"
        },
        "created_at": 1700000000
      }
    }
  },
  "created_at": 1700000000
}
```

### subscription.charged

```json
{
  "entity": "event",
  "account_id": "acc_TEST1234567890",
  "event": "subscription.charged",
  "contains": ["subscription", "payment"],
  "payload": {
    "subscription": {
      "entity": {
        "id": "sub_TEST1234567890",
        "entity": "subscription",
        "plan_id": "plan_TEST1234567890",
        "status": "active",
        "current_start": 1700000000,
        "current_end": 1702592000,
        "ended_at": null,
        "quantity": 1,
        "notes": {
          "woocommerce_order_id": "1234"
        },
        "charge_at": 1700000000,
        "start_at": 1700000000,
        "end_at": 1800000000,
        "auth_attempts": 0,
        "total_count": 12,
        "paid_count": 2,
        "customer_notify": true,
        "created_at": 1700000000,
        "expire_by": null,
        "short_url": null,
        "has_scheduled_changes": false,
        "change_scheduled_at": null,
        "source": "api",
        "payment_method": "card",
        "offer_id": null,
        "remaining_count": 10
      }
    },
    "payment": {
      "entity": {
        "id": "rzp_pay_TEST9876543210",
        "entity": "payment",
        "amount": 50000,
        "currency": "INR",
        "status": "captured",
        "order_id": "order_TEST9876543210",
        "invoice_id": "inv_TEST1234567890",
        "method": "card",
        "captured": true,
        "notes": {
          "woocommerce_order_id": "1234"
        },
        "created_at": 1700000000
      }
    }
  },
  "created_at": 1700000000
}
```

---

## How to Generate a Valid HMAC-SHA256 Signature

### PHP (for test scripts)

```php
/**
 * Generate a valid Razorpay webhook signature for testing.
 *
 * @param string $payload     The raw JSON body
 * @param string $webhookSecret The webhook secret from plugin settings
 * @return string The hex-encoded HMAC-SHA256 signature
 */
function generateTestWebhookSignature(string $payload, string $webhookSecret): string
{
    return hash_hmac('sha256', $payload, $webhookSecret);
}

// Usage:
$payload = json_encode($webhookData);
$secret  = 'your_webhook_secret_here';  // From woocommerce_razorpay_settings['webhook_secret']
$sig     = generateTestWebhookSignature($payload, $secret);

// Then send:
// POST /wp-admin/admin-post.php?action=rzp_wc_webhook
// X-Razorpay-Signature: $sig
// Content-Type: application/json
// Body: $payload
```

### Shell (cURL)

```bash
WEBHOOK_SECRET="your_webhook_secret_here"
PAYLOAD='{"event":"payment.authorized","payload":{"payment":{"entity":{"id":"rzp_pay_TEST1234567890","amount":50000,"order_id":"order_TEST1234567890","notes":{"woocommerce_order_id":"1234"},"status":"authorized","invoice_id":null}}}}'

# Generate HMAC signature
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $2}')

# Send webhook
curl -X POST \
  "https://your-store.com/wp-admin/admin-post.php?action=rzp_wc_webhook" \
  -H "Content-Type: application/json" \
  -H "X-Razorpay-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

### PHPUnit Test Setup

```php
class WebhookTest extends WP_UnitTestCase
{
    // This is a test-only value — never use a real secret here
    private string $webhookSecret = 'TEST_ONLY_DO_NOT_USE_IN_PROD';

    private function buildSignedRequest(array $payloadData): array
    {
        $json      = json_encode($payloadData);
        $signature = hash_hmac('sha256', $json, $this->webhookSecret);

        return [
            'body'      => $json,
            'signature' => $signature,
        ];
    }

    public function testPaymentAuthorizedWebhook(): void
    {
        $payload = [
            'event'    => 'payment.authorized',
            'payload'  => [
                'payment' => [
                    'entity' => [
                        'id'       => 'rzp_pay_TEST123',
                        'amount'   => 50000,
                        'order_id' => 'order_TEST456',
                        'invoice_id' => null,
                        'notes'    => ['woocommerce_order_id' => $this->orderId],
                        'status'   => 'authorized',
                    ]
                ]
            ]
        ];

        $request = $this->buildSignedRequest($payload);

        // Set up mock $_SERVER and php://input
        $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] = $request['signature'];
        // ... rest of test
    }
}
```

---

## Populating rzp_webhook_requests for Testing

```php
global $wpdb;
$table = $wpdb->prefix . 'rzp_webhook_requests';

// Insert a test pending webhook event
$wpdb->insert($table, [
    'woocommerce_order_id'  => 1234,
    'razorpay_order_id'     => 'order_TEST1234567890',
    'razorpay_payment_id'   => 'rzp_pay_TEST1234567890',
    'status'                => 0,  // 0 = pending, 1 = processed
    'webhook_data'          => json_encode(['payment_id' => 'rzp_pay_TEST1234567890']),
    'created_at'            => current_time('mysql'),
]);
```

---

## Key Files

- `includes/razorpay-webhook.php` — The code that verifies signatures and processes events
  - `shouldConsumeWebhook()` (~line 575) — validates event type
  - `process()` (~line 80) — signature verification using `$this->api->utility->verifyWebhookSignature()`
- `razorpay-sdk/Razorpay.php` — The SDK's `verifyWebhookSignature()` method uses `hash_hmac('sha256', ...)`
- `tests/` — Existing test files to follow for PHPUnit patterns

---

## Example Prompts

- "Use generate-test-payload skill to create a payment.authorized payload for order #1234."
- "Generate a refund.created webhook payload and a valid HMAC signature. Use the webhook secret stored in your WooCommerce Razorpay settings."
- "Create a test subscription.charged payload with valid HMAC for our PHPUnit tests."

---

## Output

After completing this skill, produce:
1. The complete JSON payload for the requested event type
2. The HMAC-SHA256 signature computed with the provided secret
3. A cURL command or PHP snippet ready to send the webhook
4. (Optional) A PHPUnit test method using the payload
