# Prompt: Bug Report Analysis for razorpay-woocommerce

Use this prompt when a bug report comes in and you need to analyze it, identify the root cause, and plan a fix.

---

## Prompt Template

```
You are analyzing a bug report for the razorpay-woocommerce WordPress plugin.
Read CLAUDE.md and AGENTS.md before analyzing.

## Bug Report
<paste the bug report / GitHub issue / merchant complaint here>

## Supporting Information (attach what's available)
- WooCommerce version: 
- WordPress version:
- Plugin version:
- PHP version:
- HPOS enabled: yes/no
- Debug log excerpt: <paste relevant lines>
- Order ID(s) affected:
- Razorpay payment ID(s):
- Steps to reproduce:

## Analysis Framework

Work through these questions in order:

### 1. Classify the bug
Which area of the plugin is affected?
- [ ] Payment initiation (checkout form, Razorpay order creation)
- [ ] Payment callback (check_razorpay_response)
- [ ] Webhook processing (RZP_Webhook::process)
- [ ] Refund processing (process_refund)
- [ ] Subscription handling (RZP_Subscriptions or webhook events)
- [ ] Admin settings / webhook registration (autoEnableWebhook)
- [ ] 1CC / Magic Checkout (includes/api/*)
- [ ] Order meta / HPOS compatibility

### 2. Locate the bug
Based on the classification, which files are likely involved?
Read the relevant file(s) and identify the specific method/line.

### 3. Identify the root cause
Common root causes in this codebase:
- Missing HPOS dual-path (HPOS stores have different order meta APIs)
- Webhook secret mismatch (silent signature failure)
- Session key collision (wrong Razorpay order ID retrieved)
- Amount mismatch due to missing round() or (int) cast
- Race condition between callback and webhook (rzp_update_order_cron_status)
- WP-Cron not running (webhook deferred processing stuck)
- 1CC feature not enabled (is1ccEnabled() returns false unexpectedly)
- KWD/OMR/BHD currency being used (explicitly blocked)

### 4. Check for existing guards
Does the code have a guard for this case? Did it fail or was it missing?

### 5. Determine fix severity
- Critical: Payment data loss, security issue, orders not being created
- High: Payment failures for a subset of merchants/orders
- Medium: UI issue, incorrect order status, non-blocking error
- Low: Cosmetic, logging, documentation

### 6. Propose the fix
Write the specific code change needed. Always:
- Include the HPOS dual-path if touching order meta
- Use (int) round($amount * 100) for currency
- Add a log line with rzpLogInfo/rzpLogError
- Add an order note if the fix affects order state

### 7. Write a test case
Describe or write a PHPUnit test that would have caught this bug.

## Provide
1. Root cause analysis (2-3 sentences)
2. Affected code location (file, method, line)
3. Fix (code diff)
4. Test case
5. Severity assessment
6. Affected merchant/WC configurations (e.g., "only affects HPOS-enabled stores")
```

---

## Common Bug Patterns in This Plugin

| Symptom | Likely Cause | File to Check |
|---|---|---|
| Order stuck in `pending` after payment | Webhook not received or signature mismatch | `includes/razorpay-webhook.php` → `process()` |
| Payment captured twice | Race condition — callback + cron both processed | `rzp_update_order_cron_status` meta, `rzp_webhook_requests` |
| Refund fails silently | `razorpay_payment_id` meta missing or HPOS not handled | `woo-razorpay.php` → `process_refund()` |
| Order notes show wrong amount | Missing `(int) round()` | Anywhere amount is formatted |
| Webhook rejected without error message | Secret not set / wrong secret | `woo-razorpay.php` → `getSetting('webhook_secret')` |
| 1CC checkout broken | `is1ccEnabled()` or merchant preferences check failing | `includes/utils.php`, `woo-razorpay.php` |
| Subscription renewal not creating order | Subscription plugin not active or wrong token | `includes/razorpay-webhook.php` → `subscriptionCharged()` |
