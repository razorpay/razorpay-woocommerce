# Skill: Audit All Razorpay API Calls

## Purpose
Systematically identify every Razorpay API endpoint called by this plugin, verify error handling completeness, check rate limit awareness, and produce a security/reliability audit report.

## When to Use
- Pre-release security review
- Investigating API-related production failures
- Planning for a Razorpay API version upgrade
- Checking compliance with Razorpay API best practices
- Identifying missing error handling that could cause silent failures
- Reviewing rate limit exposure

## Prerequisites
- Read `.ai/context/API_INTEGRATION.md` first — it has a pre-compiled list
- Read `woo-razorpay.php` fully
- Read `includes/api/order.php`, `includes/razorpay-webhook.php`
- Understand the Razorpay API error types: `BadRequestError`, `ServerError`, `GatewayError`

---

## Steps

### Step 1 — Search for all API call patterns

Run these searches across the codebase:

**Search 1: All $this->api-> calls**
```
grep -rn "\$this->api->" woo-razorpay.php includes/
```

Expected hits:
- `$this->api->order->create($data)` — Create Razorpay order
- `$this->api->order->fetch($rzpOrderId)` — Verify order amount
- `$this->api->payment->fetch($paymentId)` — Get payment details
- `$this->api->payment->capture($paymentId, $data)` — Capture authorized payment
- `$this->api->payment->refund($paymentId, $data)` — Issue refund
- `$this->api->payment->transfer($paymentId, $data)` — Route transfer
- `$this->api->utility->verifyWebhookSignature(...)` — HMAC verification
- `$this->api->utility->verifyPaymentSignature(...)` — Payment HMAC verification

**Search 2: Direct API class instantiation**
```
grep -rn "new Api(" woo-razorpay.php includes/
grep -rn "getRazorpayApiInstance\|getRazorpayApiPublicInstance" woo-razorpay.php includes/
```

**Search 3: Webhook API calls (REST, not SDK)**
```
grep -rn "webhookAPI\|/v1/webhooks" woo-razorpay.php includes/
```

**Search 4: Direct HTTP calls (non-SDK)**
```
grep -rn "wp_remote_post\|wp_remote_get\|curl_exec" woo-razorpay.php includes/
```

**Search 5: 1CC-specific API calls**
```
grep -rn "preferences\|1cc_preferences\|segment\|magic\|accounts/me" woo-razorpay.php includes/
```

### Step 2 — Build the API call inventory

For each API call found, record:

| Method | Endpoint | File | Line | Error Handling | Notes |
|---|---|---|---|---|---|
| POST | /v1/orders | woo-razorpay.php | ~1406 | try/catch Exception | Creates Razorpay order |
| GET | /v1/orders/{id} | woo-razorpay.php | ~1507 | try/catch | Verifies order amount |
| GET | /v1/payments/{id} | woo-razorpay.php | ~1967 | try/catch | Fetches payment status |
| POST | /v1/payments/{id}/capture | woo-razorpay.php | various | try/catch | Captures authorized payment |
| POST | /v1/payments/{id}/refund | woo-razorpay.php | ~1784 | try/catch | Issues refund |
| POST | /v1/payments/{id}/transfer | includes/razorpay-route-actions.php | various | check | Route payment |
| GET | /v1/webhooks | woo-razorpay.php | ~669 | check $response | Lists webhooks |
| POST | /v1/webhooks/ | woo-razorpay.php | ~669 | check $response | Creates webhook |
| PUT | /v1/webhooks/{id} | woo-razorpay.php | ~669 | check $response | Updates webhook |
| GET | /v1/preferences | woo-razorpay.php | various | try/catch | Merchant preferences |
| GET | /v1/merchant/1cc_preferences | woo-razorpay.php | various | check | 1CC feature flag |
| GET | /v1/accounts/me/features | woo-razorpay.php | various | check | Affordability widget |
| POST | /v1/plugins/segment | includes/plugin-instrumentation.php | various | silent | Event tracking |
| POST | /v1/magic/merchant/auth/secret | woo-razorpay.php | ~1012 | try/catch | Register 1CC HMAC |
| POST | /v1/1cc/orders/cod/convert | includes/api/prepay-cod.php | various | check | COD to prepaid |

### Step 3 — Audit error handling for each call

The standard error handling pattern (from AGENTS.md):
```php
try {
    $result = $this->api->someMethod($data);
} catch (Errors\BadRequestError $e) {
    // Safe to show to customer
    wc_add_notice($e->getMessage(), 'error');
} catch (\Exception $e) {
    rzpLogError($e->getMessage());
    $trackObject->rzpTrackDataLake('razorpay.operation.failed', ['error' => $e->getMessage()]);
    wc_add_notice(__('Something went wrong', 'woocommerce'), 'error');
}
```

For each API call, check:
1. Is it wrapped in `try/catch`? If not — flag as **MISSING ERROR HANDLING**
2. Does it catch `Errors\BadRequestError` separately from `\Exception`?
3. Is the error logged via `rzpLogError()`?
4. Is the error tracked via `rzpTrackDataLake()`?
5. Is the user shown an appropriate message (not the raw exception)?
6. Does a failure return early properly (no downstream code runs with null data)?

### Step 4 — Check for rate limit awareness

Razorpay has rate limits on its API. Check:

1. Is `autoEnableWebhook()` (line ~669) called on every page load? It should be cached.
   - Look for the `webhook_registered` option or similar guard
   - This makes repeated GET /v1/webhooks calls on admin pages

2. Is `pluginInstrumentation()` (line ~874) called too frequently?
   - Should be cached with a transient

3. Is `/v1/preferences` cached?
   - Should use WordPress transients: `get_transient('rzp_preferences')` / `set_transient(..., 3600)`

4. Are there any API calls in the frontend (checkout page) that run without caching?
   - Check `enqueue_checkout_js_script_on_checkout()` (line ~850)

### Step 5 — Check authentication security

1. **Admin API calls** — should use Key ID + Key Secret:
   ```php
   $this->getRazorpayApiInstance()  // includes key_secret
   ```
   Verify that `key_secret` is NEVER logged or exposed in responses.

2. **Public API calls** (no secret):
   ```php
   $this->getRazorpayApiPublicInstance()  // no key_secret
   ```
   These should only hit endpoints that don't require authentication (`/v1/preferences`).

3. **Webhook verification** — must always use `verifyWebhookSignature()`:
   ```php
   $this->api->utility->verifyWebhookSignature($post, $signature, $secret);
   ```
   Verify this is called BEFORE any payload processing.

4. **1CC coupon endpoint** — uses HMAC with `rzp1cc_hmac_secret`:
   ```php
   // In includes/api/auth.php
   $hmac = hash_hmac('sha256', $body, $secret);
   ```

### Step 6 — Check for sensitive data exposure

Search for any place where API keys or secrets might be logged:
```
grep -n "key_secret\|rzp_secret\|webhook_secret" woo-razorpay.php includes/
```

Verify that:
- `rzpLogInfo()` and `rzpLogError()` never log the key secret
- API error messages shown to customers don't include internal error details
- The raw API response is not echoed or printed

### Step 7 — Check for missing API call results validation

Some calls may return `null` or an unexpected structure. Check:

```php
// Example of missing validation:
$order = $this->api->order->fetch($rzpOrderId);
$amount = $order->amount;  // Crashes if $order is null!

// Should be:
$order = $this->api->order->fetch($rzpOrderId);
if (empty($order) || empty($order->amount)) {
    rzpLogError("Could not fetch Razorpay order $rzpOrderId");
    throw new Exception("Order verification failed");
}
```

### Step 8 — Produce the audit report

Format the report as:

```markdown
## Razorpay API Audit Report — razorpay-woocommerce

### Summary
- Total API endpoints called: N
- Endpoints with complete error handling: N
- Endpoints with missing/incomplete error handling: N
- Rate limit risks: N
- Security concerns: N

### Endpoints with Missing Error Handling
[List each one with file, line, and recommended fix]

### Rate Limit Risks
[List uncached repeated calls]

### Security Concerns
[List any key exposure, missing signature checks, etc.]

### Recommendations
[Prioritized list of fixes]
```

---

## Key Files

- `woo-razorpay.php` — Most API calls live here
  - `createRazorpayOrderId()` (~line 1406)
  - `verifyOrderAmount()` (~line 1507)
  - `check_razorpay_response()` (~line 1967) — payment fetch and signature verify
  - `process_refund()` (~line 1784) — refund API call
  - `autoEnableWebhook()` (~line 669) — webhook list/create/update
  - `pluginInstrumentation()` (~line 874) — segment tracking
  - `getRazorpayApiInstance()` (~line 1943) — API client factory
  - `getRazorpayApiPublicInstance()` (~line 1958) — public API client
- `includes/razorpay-route-actions.php` — Route/transfer API calls
- `includes/api/order.php` — 1CC order creation API calls
- `includes/api/auth.php` — HMAC auth for 1CC endpoints
- `includes/plugin-instrumentation.php` — Segment/DataLake tracking calls
- `.ai/context/API_INTEGRATION.md` — Pre-compiled API call list

---

## Common Patterns

### Standard API call with full error handling
```php
try {
    $payment = $this->api->payment->fetch($paymentId);
} catch (Errors\BadRequestError $e) {
    rzpLogError("fetch payment BadRequest: $paymentId — " . $e->getMessage());
    return new WP_Error('razorpay_error', $e->getMessage());
} catch (\Exception $e) {
    rzpLogError("fetch payment error: $paymentId — " . $e->getMessage());
    return new WP_Error('razorpay_error', __('Payment verification failed', 'woocommerce'));
}
```

### Checking API response validity
```php
if (empty($payment) || $payment->status !== 'captured') {
    rzpLogError("Payment $paymentId not in expected state: " . ($payment->status ?? 'null'));
    throw new Exception("Unexpected payment state");
}
```

---

## Example Prompts

- "Use api-endpoint-audit skill to audit all Razorpay API calls for missing error handling."
- "Run the api-endpoint-audit skill and focus specifically on rate limit risks."
- "Before the v5.0 release, run api-endpoint-audit to check for security issues in API usage."

---

## Output

After completing this skill, produce:
1. Complete inventory table of all API endpoints called
2. List of all calls with missing or incomplete error handling (with file + line)
3. Rate limit risks (uncached repeated calls)
4. Security concerns (key exposure, missing auth checks)
5. Prioritized recommendations with code snippets for fixes
