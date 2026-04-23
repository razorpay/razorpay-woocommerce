# Prompt: Feature Planning for razorpay-woocommerce

Use this prompt when planning a new feature, significant refactor, or integration.

---

## Prompt Template

```
You are planning a new feature for the razorpay-woocommerce WordPress plugin.
Read CLAUDE.md, AGENTS.md, and the relevant .ai/context/ files before planning.

## Feature Request
<describe the feature in plain English>

## Context
- Requested by: [merchant / Razorpay product team / internal]
- Priority: [P0 critical / P1 high / P2 medium / P3 low]
- Target version: 
- Related Razorpay API changes (if any):
- Related WooCommerce API changes (if any):

## Planning Framework

Work through these sections:

### 1. Understand the current flow
Which existing flows does this feature touch?
- Payment initiation → read `.ai/context/PAYMENT_FLOW.md`
- Webhook processing → read `.ai/context/WEBHOOK_FLOW.md`
- Refunds → read `.ai/context/REFUND_FLOW.md`
- Subscriptions → read `.ai/context/SUBSCRIPTION_FLOW.md`
- API integration → read `.ai/context/API_INTEGRATION.md`
- Admin settings → read `init_form_fields()` in `woo-razorpay.php`

### 2. Identify all touch points
List every file and method that needs to change:
- Settings: `init_form_fields()` in `woo-razorpay.php`
- Checkout: `getCheckoutArguments()` or `getDefaultCheckoutArguments()`
- Payment create: `createRazorpayOrderId()` or `getOrderCreationData()`
- Callback: `check_razorpay_response()`
- Webhook: `RZP_Webhook::process()` and handlers
- Refund: `process_refund()`
- Frontend JS: `script.js` or `btn-1cc-checkout.js`
- 1CC API: `includes/api/order.php`
- DB: new meta keys or table columns

### 3. HPOS impact assessment
Does this feature read or write order metadata?
- If YES: Every meta read/write needs both HPOS and classic paths
- New meta keys must be registered with WooCommerce via `woocommerce_order_data_store_cpt_get_orders_query`

### 4. Backward compatibility
- Does this break any existing merchant configuration?
- Does this change the session key format? (NEVER change this)
- Does this change any webhook payload handling that might affect existing integrations?
- Does this require database migration?

### 5. Feature flag / rollout strategy
Should this be behind a feature flag?
- Use plugin settings: new checkbox in `init_form_fields()`
- Use merchant feature flag: check `$merchantPreferences['features']['new_feature']`
- Hard-coded release: only if all merchants should get it immediately

### 6. Security checklist
- Does the feature accept new user input? → sanitize with `sanitize_text_field()`
- Does it expose a new REST endpoint? → add `permission_callback`
- Does it call a new Razorpay API? → wrap in try/catch with proper error handling
- Does it store new sensitive data? → ensure it's not logged

### 7. Testing plan
List test cases to write:
- Happy path: feature works for standard checkout
- HPOS enabled: same happy path on HPOS store
- Feature disabled: existing behavior unchanged when flag is off
- API failure: graceful degradation when Razorpay API fails
- 1CC path: if Magic Checkout is affected, test that flow too

### 8. Implementation plan
Write a step-by-step implementation plan:

Step 1: [First thing to implement]
  - File: `woo-razorpay.php`
  - Method: `init_form_fields()`
  - Change: Add settings field

Step 2: [Second thing]
  ...

### 9. Estimated complexity
- Number of files to change: N
- New API endpoints: N
- New DB tables/columns: N
- Estimated story points: N
- Risk level: Low / Medium / High

## Provide
1. Feature scope (in/out of scope)
2. Complete list of files and methods to modify
3. HPOS considerations
4. Backward compatibility risks
5. Security checklist results
6. Step-by-step implementation plan
7. Test plan
8. Complexity estimate
```

---

## Common Feature Categories

### Adding a new payment method option
Follow skill: `.agent/skills/add-payment-method-variant.md`
Key files: `init_form_fields()`, `getCheckoutArguments()`, potentially `process_payment()`

### Adding a new webhook event
Follow skill: `.agent/skills/add-webhook-handler.md`
Key files: `includes/razorpay-webhook.php` only (usually)

### Adding a new admin setting
Key files: `init_form_fields()` (adds the field), `getSetting()` (reads it), wherever the setting is applied

### Adding a new 1CC API endpoint
Key files: `includes/api/api.php` (register route), new `includes/api/my-endpoint.php` (handler), `includes/api/auth.php` (if HMAC-protected)

### HPOS migration work
Key pattern: Replace all `get_post_meta($orderId, ...)` with the dual-path pattern
Key files: All files that touch order metadata
Reference: Every existing HPOS dual-path in `woo-razorpay.php`
