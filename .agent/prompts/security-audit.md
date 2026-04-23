# Prompt: Security Audit for razorpay-woocommerce

Use this prompt for a thorough security review of the plugin. Payment plugins are high-value targets.

**Recommended model:** `claude-opus-4-5` or `claude-3-opus-20240229`

---

## Prompt Template

```
You are performing a security audit of the razorpay-woocommerce WordPress plugin.
This is a payment gateway plugin — security vulnerabilities can lead to financial fraud,
data theft, or unauthorized order manipulation.

Read the following files before starting:
- CLAUDE.md
- AGENTS.md  
- woo-razorpay.php (entire file)
- includes/razorpay-webhook.php
- includes/api/auth.php
- includes/api/order.php

## Audit Scope
<specify: full plugin, specific feature, specific file, or specific vulnerability class>

## Security Audit Checklist

### A. Authentication & Authorization

#### A1. Webhook Signature Verification
- [ ] Is `HTTP_X_RAZORPAY_SIGNATURE` checked on ALL webhook endpoints?
- [ ] Is verification done BEFORE any payload processing (not after)?
- [ ] Is `verifyWebhookSignature()` from the Razorpay SDK used (not a custom implementation)?
- [ ] Is the webhook secret stored securely (not hardcoded, not logged)?
- [ ] Is there a path where verification can be bypassed (empty secret edge case)?

Check: In `includes/razorpay-webhook.php::process()`, the `$razorpayWebhookSecret` fallback chain:
```php
$razorpayWebhookSecret = ... getSetting('webhook_secret') ...
if (empty($razorpayWebhookSecret)) {
    $razorpayWebhookSecret = get_option('rzp_webhook_secret');
}
```
Is there any path where `$razorpayWebhookSecret` could be empty and still proceed?

#### A2. 1CC HMAC Authentication
- [ ] Does `includes/api/auth.php` correctly verify HMAC for all 1CC endpoints?
- [ ] Is the `rzp1cc_hmac_secret` never logged?
- [ ] Is timing-safe comparison used? (`hash_equals()` not `===`)
- [ ] Are all 1CC REST endpoints protected by `permission_callback`?

#### A3. Payment Signature Verification (Callback)
- [ ] In `check_razorpay_response()`, is `verifyPaymentSignature()` called before order update?
- [ ] Is there any path to update an order without signature verification?
- [ ] Is the signature verification done server-side (not client-side)?

### B. Input Validation & Sanitization

#### B1. User Input Sanitization
- [ ] All `$_POST`, `$_GET`, `$_REQUEST` values are sanitized with `sanitize_text_field()` or equivalent
- [ ] Order IDs from user input are cast to `(int)` before DB queries
- [ ] Are there any SQL queries using unsanitized user input? (Should use `$wpdb->prepare()`)

#### B2. Webhook Payload Validation
- [ ] Are all fields accessed from `$data['payload']` validated before use?
- [ ] What happens if `$data['payload']['payment']['entity']['notes']['woocommerce_order_id']` is missing?
- [ ] Is there an integer overflow risk with `amount` fields from the API?

#### B3. Amount Manipulation Prevention
- [ ] Is `verifyOrderAmount()` called to cross-check payment amount vs WC order amount?
- [ ] Could a merchant or customer manipulate the amount passed to Razorpay?
- [ ] Is the amount from the Razorpay API response used (not from user input) for order updates?

### C. Data Exposure

#### C1. API Key Exposure
- [ ] Is `key_secret` never logged via `rzpLogInfo()` or `rzpLogError()`?
- [ ] Is `key_secret` never included in error messages shown to customers?
- [ ] Is `key_secret` never included in order notes?
- [ ] Is `getRazorpayApiPublicInstance()` (no secret) used where secret is not needed?

#### C2. Webhook Secret Exposure
- [ ] Is `webhook_secret` never logged?
- [ ] Is `rzp1cc_hmac_secret` never logged?
- [ ] Are these options protected with WordPress nonce on settings save?

#### C3. Customer PII Exposure
- [ ] Are customer email/phone from Razorpay API stored only in expected places?
- [ ] Is customer data from the `getCustomerInfo()` method sanitized before storage?

### D. Race Conditions & Double-Processing

#### D1. Callback + Webhook Race Condition
- [ ] Is `rzp_update_order_cron_status` used correctly to prevent double-processing?
- [ ] Is `wc_order_under_process_` transient set before processing to prevent concurrent requests?
- [ ] What happens if the callback and the cron run simultaneously?

#### D2. Refund Double-Processing
- [ ] Is there a guard to prevent `process_refund()` from being called twice for the same refund?
- [ ] Is the `razorpay_refund_id` meta checked before initiating a new refund?

### E. WordPress Security Patterns

#### E1. Nonce Verification
- [ ] Admin actions (settings save, webhook re-registration) use WordPress nonces
- [ ] REST endpoints use `X-WP-Nonce` where appropriate

#### E2. Capability Checks
- [ ] Admin-only actions check `current_user_can('manage_woocommerce')`
- [ ] REST endpoints that create/modify data have appropriate capability checks in `permission_callback`

#### E3. CSRF Protection
- [ ] Form submissions from admin pages use `wp_nonce_field()` / `check_admin_referer()`

### F. Third-Party Plugin Interaction

#### F1. Subscription Plugin Security
- [ ] The `$hooks = false` path doesn't bypass any security checks
- [ ] Subscription plugin's extended class can't override security-critical methods

#### F2. Gift Card Plugin Interaction
- [ ] `processRefundForOrdersWithGiftCard()` validates the gift card amount before crediting
- [ ] Can a user manipulate gift card refund amounts?

## Provide

For each issue found, report:

```
SEVERITY: Critical / High / Medium / Low / Info
CATEGORY: [A1/A2/B1/etc.]
LOCATION: [file, method, line number]
DESCRIPTION: [what the vulnerability is]
ATTACK SCENARIO: [how an attacker could exploit it]
IMPACT: [financial loss / data exposure / fraud / etc.]
RECOMMENDED FIX: [specific code change]
```

Finally provide:
1. Executive summary (2-3 sentences)
2. Prioritized list of issues
3. Overall risk rating: Critical / High / Medium / Low
```

---

## Known Security-Critical Areas

Based on the codebase architecture, pay extra attention to:

1. **`woo-razorpay.php::check_razorpay_response()`** — The callback handler. If signature verification fails silently, orders can be marked paid without real payment.

2. **`includes/razorpay-webhook.php::process()`** — The webhook handler. Empty secret edge case documented in the code; verify the fallback chain can't be exploited.

3. **`woo-razorpay.php::verifyOrderAmount()`** — Prevents amount manipulation. Must be called before `updateOrder()`.

4. **`includes/api/auth.php`** — HMAC auth for 1CC coupon endpoints. Price-sensitive data; HMAC bypass = free coupon exploit.

5. **`woo-razorpay.php::process_refund()`** — Incorrect refund amounts could over-refund customers (financial loss).
