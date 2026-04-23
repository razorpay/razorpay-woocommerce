# Prompt: Code Review for razorpay-woocommerce

Use this prompt when reviewing a PR or code change in this repository.

---

## Prompt Template

```
You are reviewing a code change in the razorpay-woocommerce WordPress plugin.
Read CLAUDE.md and AGENTS.md before reviewing.

## Diff to Review
<paste diff here>

## Review Checklist

Review the diff against all of the following criteria:

### 1. HPOS Dual-Path
- [ ] Every `get_post_meta()` has a corresponding HPOS path (`$order->get_meta()`)
- [ ] Every `update_post_meta()` has a corresponding HPOS path (`$order->update_meta_data()` + `$order->save()`)
- [ ] The `isHposEnabled` check is used to branch correctly

### 2. Currency Handling
- [ ] All amounts passed to Razorpay API use `(int) round($amount * 100)`
- [ ] No bare `$amount * 100` without `round()` and `(int)` cast

### 3. Webhook Security
- [ ] Any new webhook handler verifies `HTTP_X_RAZORPAY_SIGNATURE` before processing
- [ ] Signature verification uses `$this->api->utility->verifyWebhookSignature()`
- [ ] No processing happens if signature check fails

### 4. Error Handling
- [ ] API calls are wrapped in `try/catch`
- [ ] `Errors\BadRequestError` is caught separately from `\Exception`
- [ ] Errors are logged via `rzpLogError()`
- [ ] Errors are tracked via `rzpTrackDataLake()`
- [ ] User-facing error messages don't expose internal details

### 5. Session Key Integrity
- [ ] Razorpay order ID is stored/retrieved via `getOrderSessionKey($orderId)`
- [ ] The composite key format `razorpay_order_id{$orderId}` is not changed
- [ ] 1CC orders use `razorpay_order_id_1cc{$orderId}` correctly

### 6. Constructor Compatibility
- [ ] The `$hooks = false` path in `WC_Razorpay::__construct()` is not broken
- [ ] New hook registrations are inside `if ($hooks)` block

### 7. WooCommerce Compatibility
- [ ] New hooks follow `woocommerce_*` naming conventions
- [ ] `exit` is used appropriately in webhook handlers (not removed)
- [ ] `wc_add_notice()` is used for customer-facing messages (not `echo`)

### 8. Logging
- [ ] New code paths log key events via `rzpLogInfo()`
- [ ] Error paths log via `rzpLogError()`
- [ ] Log messages include `orderId` or `paymentId` for traceability

### 9. Blocked Patterns
- [ ] KWD/OMR/BHD currency block is not removed
- [ ] `autoEnableWebhook()` still blocks localhost

### 10. Code Style
- [ ] PHP 7.4+ compatible (no PHP 8-only features)
- [ ] Allman-style braces for methods
- [ ] 4-space indentation
- [ ] `camelCase` methods, `UPPER_SNAKE_CASE` constants

## Provide
1. A summary of what the change does
2. Issues found (critical / warning / suggestion)
3. Specific lines to fix with corrected code
4. Approval recommendation: Approve / Request Changes / Needs Discussion
```

---

## Notes for Reviewers

- Focus on the HPOS dual-path — it's the most commonly missed pattern
- Payment amount handling is a frequent source of bugs
- Webhook handlers must always exit after successful processing
- Check that new `add_action` / `add_filter` calls go inside `initHooks()` in `woo-razorpay.php`
