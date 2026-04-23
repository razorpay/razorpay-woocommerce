# CLAUDE.md — Razorpay WooCommerce Plugin

## Build & Test Commands

```bash
# Install dev dependencies
composer install

# Run PHPUnit tests
./vendor/bin/phpunit

# Run specific test file
./vendor/bin/phpunit tests/SomeTest.php

# Install WordPress test environment
composer run wp-install
```

## Code Style

- PHP 7.4+ compatible (avoid PHP 8+ only features)
- Allman-style braces `{` on same line for control flow, new line for methods
- 4-space indentation
- Class constants: `UPPER_SNAKE_CASE`
- Methods: `camelCase`
- Global functions: `camelCase` (e.g., `rzpLogInfo`, `is1ccEnabled`)
- Always use `(int) round($amount * 100)` for currency amounts — never bare `* 100`

## Architecture Notes

### Critical Files

1. **`woo-razorpay.php`** — The entire `WC_Razorpay` class. Contains payment gateway registration, checkout form generation, callback handling, refund processing, and admin settings.

2. **`includes/razorpay-webhook.php`** — `RZP_Webhook` class. Handles all incoming webhooks. The `payment.authorized` handler saves to DB and defers to cron.

3. **`includes/api/api.php`** — REST endpoint registration for Magic Checkout (1CC) feature.

4. **`includes/api/order.php`** — `createWcOrder()` — the entry point for Magic Checkout order creation.

### HPOS Dual-Path (Important!)

Every place that reads/writes order metadata must handle both HPOS and classic:
```php
if ($this->isHposEnabled) {
    $order->update_meta_data('key', 'value');
    $order->save();
} else {
    update_post_meta($orderId, 'key', 'value');
}
```

Forgetting this breaks HPOS-enabled stores.

### Gateway ID

The gateway ID is `'razorpay'` (the `$this->id` property). This appears in:
- Hook names: `woocommerce_receipt_razorpay`, `woocommerce_api_razorpay`
- Settings key: `woocommerce_razorpay_settings`
- URL param: `?wc-api=razorpay`

### Session Key Pattern

Razorpay order ID is stored in order meta with a composite key:
- Standard: `razorpay_order_id{WC_ORDER_ID}` (e.g., `razorpay_order_id456`)
- 1CC: `razorpay_order_id_1cc{WC_ORDER_ID}` (e.g., `razorpay_order_id_1cc456`)

Always use `getOrderSessionKey($orderId)` which returns the correct key based on `is_magic_checkout_order` meta.

### Webhook Table

`{prefix}rzp_webhook_requests` tracks payment.authorized events:
- Inserted when Razorpay order is created (`status=0`)
- Updated to `status=1` when callback successfully processes the payment
- Cron queries `status=0` to find orders needing webhook processing

### 1CC Feature Flag

Magic Checkout features are behind `is1ccEnabled()`:
```php
function is1ccEnabled() {
    return 'yes' == get_option('woocommerce_razorpay_settings')['enable_1cc'];
}
```

Also gated by merchant having the feature: `$merchantPreferences['features']['one_click_checkout']`.

## Key Gotchas for Claude

1. **Do NOT use `$amount * 100`** — always `(int) round($amount * 100)` for currency
2. **Do NOT use `get_post_meta` alone** — always add HPOS path
3. **The `$hooks = false` constructor param** exists for subscriptions plugin compatibility — don't remove it
4. **`autoEnableWebhook()` blocks localhost** — don't test webhook registration on local without tunneling
5. **`exit`** is used intentionally in webhook handlers (virtual_account.credited, payment.pending) — this is standard WordPress pattern for early exits in POST handlers
6. **KWD/OMR/BHD currencies** are explicitly blocked — don't remove this check

## Where Things Are Configured

| Setting | Location |
|---|---|
| API keys (Key ID/Secret) | WC Admin > Payments > Razorpay > Settings |
| Webhook secret | Auto-generated, stored in `webhook_secret` option |
| Debug logging | Enable "debug mode" in plugin settings |
| 1CC features | Requires merchant feature flag from Razorpay |
| Route module | Only shown for INR currency stores |

## Running Locally

1. Install WordPress with WooCommerce
2. Copy plugin to `wp-content/plugins/razorpay-woocommerce/`
3. Activate plugin
4. Enter test Key ID (`rzp_test_...`) and Key Secret in settings
5. Use `ngrok http 80` for webhook testing (localhost is blocked)
6. Use Razorpay test cards: https://razorpay.com/docs/payments/testing/

## Relevant Context Files

- `.ai/context/CODEBASE_OVERVIEW.md` — Full architecture
- `.ai/context/PAYMENT_FLOW.md` — How payments work end-to-end
- `.ai/context/WEBHOOK_FLOW.md` — Webhook processing details
- `.ai/context/DATABASE_SCHEMA.md` — All meta keys and DB tables
- `.ai/context/WORDPRESS_HOOKS.md` — All hooks registered/fired
- `.ai/diagrams/LLD_CHECKOUT_SEQUENCE.md` — Detailed checkout sequence

---

## Model Selection Guide for Claude

Use the right Claude model for the task. See `.agent/config/claude.yaml` for full details.

| Task | Recommended Model |
|---|---|
| Add a log line, fix a typo, small string edit | `claude-3-5-haiku-20241022` |
| Add a settings field, write a PHPUnit stub | `claude-3-5-haiku-20241022` |
| Add a webhook handler, debug a payment | `claude-3-5-sonnet-20241022` |
| Refund investigation, HPOS dual-path refactor | `claude-3-5-sonnet-20241022` |
| Multi-file feature (webhook + settings + JS) | `claude-sonnet-4-5` |
| Code review of a full PR | `claude-sonnet-4-5` |
| Subscription debug, complex race condition | `claude-sonnet-4-5` |
| Security audit of webhook/HMAC handling | `claude-opus-4-5` |
| Architecture planning, full codebase audit | `claude-opus-4-5` |

### Context Window Tips

- `woo-razorpay.php` is ~2800 lines — loads fine in 200k context window
- For tasks touching 3+ files, prefer `claude-sonnet-4-5` or `claude-opus-4-5`
- Always load `CLAUDE.md` + `AGENTS.md` at session start
- For webhook tasks: also load `includes/razorpay-webhook.php`
- For subscription tasks: also load `.ai/context/SUBSCRIPTION_FLOW.md`

---

## Agent Skills

The `.agent/skills/` directory contains step-by-step runbooks for common tasks. Use them like this:

```
Follow the steps in .agent/skills/debug-payment.md to investigate order #1234.
```

### Available Skills

| Skill | When to Use |
|---|---|
| `.agent/skills/debug-payment.md` | Payment not reflecting in WC after being captured in Razorpay |
| `.agent/skills/add-webhook-handler.md` | Adding support for a new Razorpay webhook event |
| `.agent/skills/add-payment-method-variant.md` | Adding UPI Autopay, new EMI type, or payment option toggle |
| `.agent/skills/refund-investigation.md` | Refund stuck or not processed |
| `.agent/skills/subscription-debug.md` | Subscription renewal payment failures |
| `.agent/skills/order-status-sync.md` | WC order status out of sync with Razorpay |
| `.agent/skills/generate-test-payload.md` | Generate test webhook payloads with valid HMAC |
| `.agent/skills/api-endpoint-audit.md` | Audit all Razorpay API calls for security/error handling |

### Reusable Prompts

- `.agent/prompts/code-review.md` — Standard PR review checklist
- `.agent/prompts/bug-report-analysis.md` — Analyze and fix bug reports
- `.agent/prompts/feature-planning.md` — Plan a new feature
- `.agent/prompts/security-audit.md` — Security audit (use with Opus models)

---

## Agent Workflow Patterns

### Starting a new task
1. Tell Claude which skill to use (or describe the task)
2. Provide the specific context (order ID, payment ID, file to edit, etc.)
3. Claude reads the skill file and the referenced code files
4. Claude proposes the change and explains the reasoning

### Debugging a production issue
```
Read .agent/skills/debug-payment.md and investigate order #1234.
The customer says they were charged but the order shows pending.
The Razorpay payment ID is rzp_pay_ABC123.
```

### Adding a new feature
```
Read .agent/skills/add-webhook-handler.md and add support for
the payment.dispute.created webhook event. It should add an order
note with the dispute ID and put the order on hold.
```

### Code review
```
Use the prompt at .agent/prompts/code-review.md to review this diff:
<paste diff>
```

### Effective Claude Code usage for this repo
- Start sessions by saying "Read CLAUDE.md" — it gives Claude full context
- For multi-session tasks, use `/compact` to preserve context efficiently
- Reference skills by file path so Claude reads the full runbook
- Always provide order IDs, payment IDs, and error messages when debugging
